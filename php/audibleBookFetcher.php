<?php
// audibleBookFetcher.php
// Fetches book metadata from Audible's catalog API.
// Returns data in audimeta-compatible format for seamless integration.

// Prevent PHP warnings/deprecations from corrupting JSON output
error_reporting(0);
ini_set('display_errors', '0');

header("Content-Type: application/json");

// Rate limiting: simple file-based throttle
$rateLockFile = sys_get_temp_dir() . "/audible_rate_lock";
$minDelay = 300000; // 300ms between requests (microseconds)
if (file_exists($rateLockFile)) {
    $lastRequest = (int)file_get_contents($rateLockFile);
    $elapsed = (int)(microtime(true) * 1000000) - $lastRequest;
    if ($elapsed < $minDelay) {
        usleep($minDelay - $elapsed);
    }
}
file_put_contents($rateLockFile, (string)(int)(microtime(true) * 1000000));

// -----------------------------------------------------------------------------
// Step 1: Read and validate input
// -----------------------------------------------------------------------------

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON input"]);
    exit;
}

$asin = trim($input["asin"] ?? "");
$region = strtolower(trim($input["region"] ?? "us"));

if (!$asin) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required field: asin"]);
    exit;
}

// -----------------------------------------------------------------------------
// Step 2: Build Audible API URL based on region
// -----------------------------------------------------------------------------

$apiDomains = [
    "us" => "api.audible.com",
    "uk" => "api.audible.co.uk",
    "ca" => "api.audible.ca",
    "au" => "api.audible.com.au",
    "de" => "api.audible.de",
    "fr" => "api.audible.fr",
    "it" => "api.audible.it",
    "in" => "api.audible.in",
    "jp" => "api.audible.co.jp",
    "es" => "api.audible.es",
    "br" => "api.audible.com.br"
];

$webDomains = [
    "us" => "www.audible.com",
    "uk" => "www.audible.co.uk",
    "ca" => "www.audible.ca",
    "au" => "www.audible.com.au",
    "de" => "www.audible.de",
    "fr" => "www.audible.fr",
    "it" => "www.audible.it",
    "in" => "www.audible.in",
    "jp" => "www.audible.co.jp",
    "es" => "www.audible.es",
    "br" => "www.audible.com.br"
];

$apiDomain = $apiDomains[$region] ?? "api.audible.com";
$webDomain = $webDomains[$region] ?? "www.audible.com";

$responseGroups = "series,product_attrs,contributors,product_details,media,product_desc,product_extended_attrs";
$apiUrl = "https://$apiDomain/1.0/catalog/products?keywords=$asin&response_groups=$responseGroups&num_results=1";

// -----------------------------------------------------------------------------
// Step 3: Fetch from Audible API
// -----------------------------------------------------------------------------

$curl = curl_init($apiUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
        "Accept: application/json",
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true
]);

$responseBody = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($httpCode !== 200 || !$responseBody) {
    http_response_code($httpCode ?: 500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to fetch from Audible API",
        "httpCode" => $httpCode,
        "curlError" => $curlError
    ]);
    exit;
}

$data = json_decode($responseBody, true);
$products = $data["products"] ?? [];

if (empty($products)) {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "Book not found on Audible for ASIN: $asin"
    ]);
    exit;
}

// Find the matching product (prefer exact ASIN match)
$product = null;
foreach ($products as $p) {
    if (($p["asin"] ?? "") === $asin) {
        $product = $p;
        break;
    }
}
// Fallback to first result if no exact match
if (!$product) {
    $product = $products[0];
}

// -----------------------------------------------------------------------------
// Step 4: Convert to audimeta-compatible format
// -----------------------------------------------------------------------------

$book = convertProductToBook($product, $region, $webDomain);

// Return in the shape that fetchAudimetaMetadata.js expects
echo json_encode([
    "audiMetaResponse" => $book,
    "responseHeaders" => [
        "requestLimit" => "60",
        "requestRemaining" => "59",
        "cached" => null
    ]
]);

// -----------------------------------------------------------------------------
// Helper: Convert Audible API product to audimeta-compatible book object
// -----------------------------------------------------------------------------

function convertProductToBook($product, $region, $webDomain) {
    $asin = $product["asin"] ?? "";

    // Authors
    $authors = [];
    foreach ($product["authors"] ?? [] as $author) {
        $authors[] = ["name" => $author["name"] ?? ""];
    }

    // Narrators
    $narrators = [];
    foreach ($product["narrators"] ?? [] as $narrator) {
        $narrators[] = ["name" => $narrator["name"] ?? ""];
    }

    // Series
    $series = [];
    foreach ($product["series"] ?? [] as $s) {
        $series[] = [
            "asin" => $s["asin"] ?? "",
            "name" => $s["title"] ?? "",
            "position" => $s["sequence"] ?? ""
        ];
    }

    // Release date — normalize far-future placeholders (e.g. 2199) from Audible
    $releaseDate = $product["release_date"] ?? $product["issue_date"] ?? null;
    $isAvailable = true;
    if ($releaseDate) {
        $ts = strtotime($releaseDate);
        if ($ts !== false) {
            $year = (int) date("Y", $ts);
            if ($year >= 2100) {
                // Audible placeholder date — mark unavailable but keep date for display as "TBD"
                $isAvailable = false;
            } elseif ($ts > time()) {
                $isAvailable = false;
            }
        }
    }

    // Image
    $images = $product["product_images"] ?? [];
    $imageUrl = $images["500"] ?? ($images["1024"] ?? null);

    // Duration in minutes
    $lengthMinutes = $product["runtime_length_min"] ?? null;

    // Rating
    $rating = null;
    if (isset($product["rating"])) {
        $rating = is_array($product["rating"])
            ? (float)($product["rating"]["overall_distribution"]["average_rating"] ?? 0)
            : (float)$product["rating"];
    }

    // Description/Summary
    $summary = $product["merchandising_summary"] ?? $product["publisher_summary"] ?? null;
    if ($summary) {
        $summary = strip_tags($summary);
    }

    // Book format
    $formatType = $product["format_type"] ?? "unabridged";
    $bookFormat = ($formatType === "abridged") ? "abridged" : "unabridged";

    // Publisher
    $publisher = $product["publisher_name"] ?? null;

    // Genres from categories
    $genres = [];
    foreach ($product["category_ladders"] ?? [] as $ladder) {
        foreach ($ladder["ladder"] ?? [] as $cat) {
            $genres[] = ["name" => $cat["name"] ?? ""];
        }
    }
    // Fallback to platinum_keywords
    if (empty($genres) && isset($product["platinum_keywords"])) {
        foreach ($product["platinum_keywords"] as $kw) {
            $genres[] = ["name" => str_replace("_", " ", $kw)];
        }
    }

    // Build the Audible link
    $link = "https://$webDomain/pd/$asin";

    return [
        "asin" => $asin,
        "title" => $product["title"] ?? "",
        "subtitle" => $product["subtitle"] ?? null,
        "authors" => $authors,
        "narrators" => $narrators,
        "series" => $series,
        "releaseDate" => $releaseDate,
        "region" => $region,
        "isAvailable" => $isAvailable,
        "isListenable" => $product["is_listenable"] ?? true,
        "imageUrl" => $imageUrl,
        "link" => $link,
        "bookFormat" => $bookFormat,
        "rating" => $rating,
        "summary" => $summary,
        "publisher" => $publisher,
        "genres" => $genres,
        "lengthMinutes" => $lengthMinutes
    ];
}
