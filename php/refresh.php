<?php
/**
 * refresh.php - Server-side data refresh using environment credentials
 *
 * Authenticates with AudiobookShelf using stored credentials,
 * fetches all libraries and series data, and saves to persistent storage.
 */

// Prevent PHP errors from corrupting JSON output
error_reporting(0);
ini_set('display_errors', '0');

header("Content-Type: application/json");

$dataFilePath = "/data/completeseries.json";
$dataDir = "/data";

// -----------------------------------------------------------------------------
// Step 1: Read and validate environment configuration
// -----------------------------------------------------------------------------

$serverUrl = rtrim(getenv("ABS_URL") ?: "", "/");
$username = getenv("ABS_USERNAME") ?: "";
$password = getenv("ABS_PASSWORD") ?: "";
$apiKey = getenv("ABS_API_KEY") ?: "";
$useApiKey = filter_var(getenv("ABS_USE_API_KEY") ?: "false", FILTER_VALIDATE_BOOLEAN);

// Validate configuration
if (!$serverUrl) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Server not configured: ABS_URL environment variable missing"
    ]);
    exit;
}

if ($useApiKey && !$apiKey) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "API key mode enabled but ABS_API_KEY not set"
    ]);
    exit;
}

if (!$useApiKey && (!$username || !$password)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Username/password mode but ABS_USERNAME or ABS_PASSWORD not set"
    ]);
    exit;
}

// Helper functions
function loadExistingData($path) {
    $default = [
        "version" => 1,
        "lastUpdated" => null,
        "hiddenItems" => [],
        "existingFirstBookASINs" => [],
        "existingBookMetadata" => [],
        "serverConfig" => ["lastRefresh" => null, "refreshStatus" => "idle"]
    ];

    if (!file_exists($path)) return $default;

    $content = file_get_contents($path);
    if ($content === false) return $default;

    $data = json_decode($content, true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

function saveData($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $temp = $path . ".tmp." . uniqid();
    file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($temp, $path);
}

function updateRefreshStatus($status) {
    global $dataFilePath;
    $data = loadExistingData($dataFilePath);
    $data["serverConfig"]["refreshStatus"] = $status;
    saveData($dataFilePath, $data);
}

// -----------------------------------------------------------------------------
// Step 2: Update status to "refreshing"
// -----------------------------------------------------------------------------

updateRefreshStatus("refreshing");

try {
    // -------------------------------------------------------------------------
    // Step 3: Authenticate (if not using API key)
    // -------------------------------------------------------------------------

    $authToken = $apiKey;

    if (!$useApiKey) {
        $loginUrl = "$serverUrl/login";
        $loginPayload = json_encode([
            "username" => $username,
            "password" => $password
        ]);

        $loginCurl = curl_init($loginUrl);
        curl_setopt_array($loginCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
            CURLOPT_POSTFIELDS => $loginPayload
        ]);

        $loginResponse = curl_exec($loginCurl);
        $loginStatus = curl_getinfo($loginCurl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($loginCurl);
        curl_close($loginCurl);

        if ($loginStatus < 200 || $loginStatus >= 300) {
            throw new Exception("Authentication failed: HTTP $loginStatus - " . ($curlError ?: $loginResponse));
        }

        $loginData = json_decode($loginResponse, true);
        $authToken = $loginData["user"]["token"] ?? null;

        if (!$authToken) {
            throw new Exception("No auth token in login response");
        }
    }

    // -------------------------------------------------------------------------
    // Step 4: Fetch libraries
    // -------------------------------------------------------------------------

    $librariesUrl = "$serverUrl/api/libraries";
    $librariesCurl = curl_init($librariesUrl);
    curl_setopt_array($librariesCurl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $authToken",
            "Accept: application/json"
        ]
    ]);

    $librariesResponse = curl_exec($librariesCurl);
    $librariesStatus = curl_getinfo($librariesCurl, CURLINFO_HTTP_CODE);
    curl_close($librariesCurl);

    if ($librariesStatus < 200 || $librariesStatus >= 300) {
        throw new Exception("Failed to fetch libraries: HTTP $librariesStatus");
    }

    $librariesData = json_decode($librariesResponse, true);
    $libraries = array_filter(
        $librariesData["libraries"] ?? [],
        fn($lib) => ($lib["mediaType"] ?? "") === "book"
    );

    // -------------------------------------------------------------------------
    // Step 5: Fetch all series with pagination
    // -------------------------------------------------------------------------

    $seriesFirstASIN = [];
    $seriesAllASIN = [];
    $limit = 100;

    foreach ($libraries as $library) {
        $libraryId = $library["id"] ?? null;
        if (!$libraryId) continue;

        $page = 0;
        $totalSeries = null;
        $fetchedCount = 0;

        do {
            $seriesUrl = "$serverUrl/api/libraries/$libraryId/series?limit=$limit&page=$page";

            $seriesCurl = curl_init($seriesUrl);
            curl_setopt_array($seriesCurl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $authToken",
                    "Accept: application/json"
                ]
            ]);

            $seriesResponse = curl_exec($seriesCurl);
            $seriesStatus = curl_getinfo($seriesCurl, CURLINFO_HTTP_CODE);
            curl_close($seriesCurl);

            if ($seriesStatus < 200 || $seriesStatus >= 300) {
                throw new Exception("Failed to fetch series page $page: HTTP $seriesStatus");
            }

            $seriesData = json_decode($seriesResponse, true);
            $results = $seriesData["results"] ?? [];

            if ($totalSeries === null && isset($seriesData["total"])) {
                $totalSeries = $seriesData["total"];
            }

            foreach ($results as $series) {
                $seriesName = $series["name"] ?? "Unknown Series";
                $books = $series["books"] ?? [];

                if (!empty($books)) {
                    $firstMeta = $books[0]["media"]["metadata"] ?? [];
                    $seriesFirstASIN[] = [
                        "series" => $seriesName,
                        "title" => $firstMeta["title"] ?? "Unknown Title",
                        "asin" => $firstMeta["asin"] ?? "Unknown ASIN"
                    ];
                }

                foreach ($books as $book) {
                    $meta = $book["media"]["metadata"] ?? [];
                    $bookSeriesName = $meta["seriesName"] ?? "";
                    $hashPos = strpos($bookSeriesName, "#");
                    $position = $hashPos !== false
                        ? trim(substr($bookSeriesName, $hashPos + 1))
                        : "N/A";

                    $seriesAllASIN[] = [
                        "series" => $seriesName,
                        "title" => $meta["title"] ?? "Unknown Title",
                        "asin" => $meta["asin"] ?? "Unknown ASIN",
                        "subtitle" => $meta["subtitle"] ?? "No Subtitle",
                        "seriesPosition" => $position
                    ];
                }
            }

            $fetchedCount += count($results);
            $page++;

        } while ($totalSeries !== null && $fetchedCount < $totalSeries);
    }

    // -------------------------------------------------------------------------
    // Step 6: Save refreshed data
    // -------------------------------------------------------------------------

    $existingData = loadExistingData($dataFilePath);
    $existingData["existingFirstBookASINs"] = $seriesFirstASIN;
    $existingData["seriesAllASIN"] = $seriesAllASIN;
    $existingData["lastUpdated"] = gmdate("c");
    $existingData["serverConfig"]["lastRefresh"] = gmdate("c");
    $existingData["serverConfig"]["refreshStatus"] = "complete";

    saveData($dataFilePath, $existingData);

    echo json_encode([
        "status" => "success",
        "message" => "Refresh completed",
        "seriesCount" => count($seriesFirstASIN),
        "bookCount" => count($seriesAllASIN),
        "lastRefresh" => $existingData["serverConfig"]["lastRefresh"]
    ]);

} catch (Exception $e) {
    updateRefreshStatus("error");
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
