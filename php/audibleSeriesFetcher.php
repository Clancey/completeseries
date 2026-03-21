<?php
// audibleSeriesFetcher.php
// Fetches all books in a series from Audible's catalog API using the
// similarity endpoint (InTheSameSeries). Requires a book ASIN that belongs
// to the series, not the series ASIN itself.
//
// The caller (metadataFlow.js) provides a series ASIN. We first need to find
// a book that belongs to that series, then use InTheSameSeries to get all books.

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

$seriesAsin = trim($input["asin"] ?? "");
$region = strtolower(trim($input["region"] ?? "us"));
// Optional: a book ASIN known to be in this series (avoids the discovery step)
$bookAsin = trim($input["bookAsin"] ?? "");

if (!$seriesAsin) {
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
$responseGroups = "series,product_attrs,contributors,product_details,media,product_desc";

// -----------------------------------------------------------------------------
// Step 3: Find a book ASIN in this series (if not provided)
// -----------------------------------------------------------------------------

if (!$bookAsin) {
    // Search for any book in this series using the series name or ASIN as keyword
    $searchUrl = "https://$apiDomain/1.0/catalog/products?keywords=$seriesAsin&response_groups=series,product_attrs&num_results=10";

    $searchResponse = audibleApiGet($searchUrl);
    if ($searchResponse === null) {
        http_response_code(502);
        echo json_encode(["status" => "error", "message" => "Failed to search Audible API for series"]);
        exit;
    }

    $searchData = json_decode($searchResponse, true);
    $searchProducts = $searchData["products"] ?? [];

    // Find a product that belongs to this series
    foreach ($searchProducts as $p) {
        foreach ($p["series"] ?? [] as $s) {
            if (($s["asin"] ?? "") === $seriesAsin) {
                $bookAsin = $p["asin"] ?? "";
                break 2;
            }
        }
    }

    if (!$bookAsin) {
        // Series ASIN search didn't work — caller may have provided a book ASIN as the series ASIN.
        // Try treating it as a book ASIN directly and check if it has series info.
        $bookCheckUrl = "https://$apiDomain/1.0/catalog/products?keywords=$seriesAsin&response_groups=series,product_attrs&num_results=1";
        $bookCheckResponse = audibleApiGet($bookCheckUrl);
        if ($bookCheckResponse) {
            $bookCheckData = json_decode($bookCheckResponse, true);
            $firstProduct = ($bookCheckData["products"] ?? [])[0] ?? null;
            if ($firstProduct && ($firstProduct["asin"] ?? "") === $seriesAsin) {
                // The "series ASIN" is actually a book ASIN — use it directly
                $bookAsin = $seriesAsin;
                // Update seriesAsin from the book's series info
                foreach ($firstProduct["series"] ?? [] as $s) {
                    $seriesAsin = $s["asin"] ?? $seriesAsin;
                    break;
                }
            }
        }
    }

    if (!$bookAsin) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "Could not find any book in series: $seriesAsin"
        ]);
        exit;
    }
}

// -----------------------------------------------------------------------------
// Step 4: Fetch all books in the series using InTheSameSeries
// -----------------------------------------------------------------------------

$simsUrl = "https://$apiDomain/1.0/catalog/products/$bookAsin/sims?response_groups=$responseGroups&similarity_type=InTheSameSeries&num_results=50";

$simsResponse = audibleApiGet($simsUrl);
if ($simsResponse === null) {
    http_response_code(502);
    echo json_encode(["status" => "error", "message" => "Failed to fetch series books from Audible API"]);
    exit;
}

$simsData = json_decode($simsResponse, true);
$similarProducts = $simsData["similar_products"] ?? $simsData["products"] ?? [];

// Also fetch the seed book itself (sims endpoint doesn't include the source book)
$seedUrl = "https://$apiDomain/1.0/catalog/products?keywords=$bookAsin&response_groups=$responseGroups&num_results=1";
$seedResponse = audibleApiGet($seedUrl);
$seedProduct = null;
if ($seedResponse) {
    $seedData = json_decode($seedResponse, true);
    foreach ($seedData["products"] ?? [] as $p) {
        if (($p["asin"] ?? "") === $bookAsin) {
            $seedProduct = $p;
            break;
        }
    }
}

// Combine seed book + similar books, deduplicating by ASIN
$allProducts = [];
$seenAsins = [];

if ($seedProduct) {
    $allProducts[] = $seedProduct;
    $seenAsins[$seedProduct["asin"]] = true;
}

foreach ($similarProducts as $p) {
    $pAsin = $p["asin"] ?? "";
    if ($pAsin && !isset($seenAsins[$pAsin])) {
        $allProducts[] = $p;
        $seenAsins[$pAsin] = true;
    }
}

// Extract series name from the first product that has it
$seriesName = "";
foreach ($allProducts as $p) {
    foreach ($p["series"] ?? [] as $s) {
        if (($s["asin"] ?? "") === $seriesAsin) {
            $seriesName = $s["title"] ?? "";
            break 2;
        }
    }
}

// -----------------------------------------------------------------------------
// Step 5: Convert all products to audimeta-compatible format
// -----------------------------------------------------------------------------

$books = [];
foreach ($allProducts as $product) {
    $books[] = convertProductToBook($product, $region, $webDomain, $seriesAsin, $seriesName);
}

// Sort by series position
usort($books, function($a, $b) {
    $posA = $a["series"][0]["position"] ?? "";
    $posB = $b["series"][0]["position"] ?? "";
    $numA = is_numeric($posA) ? (float)$posA : PHP_INT_MAX;
    $numB = is_numeric($posB) ? (float)$posB : PHP_INT_MAX;
    return $numA <=> $numB;
});

// -----------------------------------------------------------------------------
// Step 6: Return results
// -----------------------------------------------------------------------------

echo json_encode([
    "status" => "success",
    "seriesAsin" => $seriesAsin,
    "seriesName" => $seriesName,
    "region" => $region,
    "bookCount" => count($books),
    "books" => $books,
    "audiMetaResponse" => $books,
    "responseHeaders" => [
        "requestLimit" => "60",
        "requestRemaining" => "59",
        "cached" => null
    ],
    "source" => "audible_api"
]);

// -----------------------------------------------------------------------------
// Helper functions
// -----------------------------------------------------------------------------

/**
 * Make a GET request to the Audible API.
 *
 * @param string $url The API URL
 * @return string|null Response body, or null on failure
 */
function audibleApiGet($url) {
    $curl = curl_init($url);
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

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return ($httpCode === 200 && $response) ? $response : null;
}

/**
 * Convert an Audible API product to an audimeta-compatible book object.
 */
function convertProductToBook($product, $region, $webDomain, $seriesAsin = "", $seriesName = "") {
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

    // Series - use the product's own series data, ensuring the target series is included
    $series = [];
    $foundTargetSeries = false;
    foreach ($product["series"] ?? [] as $s) {
        $series[] = [
            "asin" => $s["asin"] ?? "",
            "name" => $s["title"] ?? "",
            "position" => $s["sequence"] ?? ""
        ];
        if (($s["asin"] ?? "") === $seriesAsin) {
            $foundTargetSeries = true;
        }
    }
    // If the target series isn't in the product's series list, add it
    if (!$foundTargetSeries && $seriesAsin) {
        $series[] = [
            "asin" => $seriesAsin,
            "name" => $seriesName,
            "position" => ""
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

    // Genres
    $genres = [];
    if (isset($product["platinum_keywords"])) {
        foreach ($product["platinum_keywords"] as $kw) {
            $genres[] = ["name" => str_replace("_", " ", $kw)];
        }
    }

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
        "link" => "https://$webDomain/pd/$asin",
        "bookFormat" => $bookFormat,
        "rating" => $rating,
        "summary" => $summary,
        "publisher" => $publisher,
        "genres" => $genres,
        "lengthMinutes" => $lengthMinutes
    ];
}
