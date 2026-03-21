<?php
// audibleSeriesFetcher.php
// Fetches series data directly from Audible series pages.
// Returns data in audimeta-compatible format for seamless integration.

header("Content-Type: application/json");

// Rate limiting: simple file-based throttle
$rateLockFile = sys_get_temp_dir() . "/audible_rate_lock";
$minDelay = 500000; // 500ms between requests (microseconds)
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

if (!$seriesAsin) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required field: asin"]);
    exit;
}

// -----------------------------------------------------------------------------
// Step 2: Build Audible URL based on region
// -----------------------------------------------------------------------------

$audibleDomains = [
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

$domain = $audibleDomains[$region] ?? "www.audible.com";
$seriesUrl = "https://$domain/series/$seriesAsin";

// -----------------------------------------------------------------------------
// Step 3: Fetch the Audible series page
// -----------------------------------------------------------------------------

$curl = curl_init($seriesUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
        "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.9"
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true
]);

$html = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($httpCode !== 200 || !$html) {
    http_response_code($httpCode ?: 500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to fetch Audible series page",
        "httpCode" => $httpCode,
        "curlError" => $curlError
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Step 4: Extract series name from page title
// -----------------------------------------------------------------------------

$seriesName = "";
if (preg_match('/<title>([^<|]+)/i', $html, $titleMatch)) {
    $seriesName = html_entity_decode(trim($titleMatch[1]), ENT_QUOTES, 'UTF-8');
    $seriesName = preg_replace('/\s*\|.*$/', '', $seriesName);
    $seriesName = preg_replace('/^Series:\s*/i', '', $seriesName);
    $seriesName = preg_replace('/\s*Audiobooks?\s*$/i', '', $seriesName);
}

// -----------------------------------------------------------------------------
// Step 5: Parse the HTML for book data
// -----------------------------------------------------------------------------

$books = [];
$seenAsins = [];

// Method 1: JSON-LD structured data (most reliable, richest data)
preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $jsonLdMatches, PREG_SET_ORDER);
foreach ($jsonLdMatches as $jsonLdMatch) {
    $jsonLd = json_decode($jsonLdMatch[1], true);
    if (!$jsonLd) continue;

    $items = isset($jsonLd["@graph"]) ? $jsonLd["@graph"] : [$jsonLd];
    foreach ($items as $item) {
        if (!isset($item["@type"]) || ($item["@type"] !== "Audiobook" && $item["@type"] !== "Book")) continue;

        $bookAsin = extractAsinFromUrl($item["url"] ?? "");
        if (!$bookAsin || isset($seenAsins[$bookAsin])) continue;
        $seenAsins[$bookAsin] = true;

        $book = buildAudiMetaBook($item, $bookAsin, $seriesAsin, $seriesName, $region, $domain);
        $books[] = $book;
    }
}

// Method 2: Parse product cards with data-asin
if (empty($books)) {
    preg_match_all('/<li[^>]*data-asin="([A-Z0-9]{10})"[^>]*>.*?<h3[^>]*>.*?<a[^>]*>([^<]+)<\/a>/Usi', $html, $liMatches, PREG_SET_ORDER);

    foreach ($liMatches as $match) {
        $asin = $match[1];
        $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
        if ($asin && $title && !isset($seenAsins[$asin])) {
            $seenAsins[$asin] = true;
            $books[] = buildMinimalBook($asin, $title, $seriesAsin, $seriesName, $region, $domain, $html);
        }
    }
}

// Method 3: Parse product links
if (empty($books)) {
    preg_match_all('/<a[^>]*href="\/pd\/([^"?]+)\?[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $linkMatches, PREG_SET_ORDER);

    foreach ($linkMatches as $match) {
        $urlPath = $match[1];
        if (preg_match('/([A-Z0-9]{10})$/', $urlPath, $asinMatch)) {
            $asin = $asinMatch[1];
            $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
            if ($asin && $title && !isset($seenAsins[$asin]) &&
                !preg_match('/^(Buy|Add|Listen|Play|Sample|Preview|Reviews?)/i', $title)) {
                $seenAsins[$asin] = true;
                $books[] = buildMinimalBook($asin, $title, $seriesAsin, $seriesName, $region, $domain, $html);
            }
        }
    }
}

// Method 4: aria-label on product links
if (empty($books)) {
    preg_match_all('/data-asin="([A-Z0-9]{10})"[^>]*aria-label="([^"]+)"/i', $html, $ariaMatches, PREG_SET_ORDER);

    foreach ($ariaMatches as $match) {
        $asin = $match[1];
        $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
        if ($asin && $title && !isset($seenAsins[$asin])) {
            $seenAsins[$asin] = true;
            $books[] = buildMinimalBook($asin, $title, $seriesAsin, $seriesName, $region, $domain, $html);
        }
    }
}

// Method 5: Book number patterns
if (empty($books)) {
    preg_match_all('/Book\s+(\d+)[^<]*<\/.*?data-asin="([A-Z0-9]{10})"/Usi', $html, $bookNumMatches, PREG_SET_ORDER);

    foreach ($bookNumMatches as $match) {
        $bookNum = $match[1];
        $asin = $match[2];
        if ($asin && !isset($seenAsins[$asin])) {
            $seenAsins[$asin] = true;
            // Try to find a better title
            $title = "Book $bookNum";
            if (preg_match('/data-asin="' . preg_quote($asin, '/') . '"[^>]*>.*?class="[^"]*bc-heading[^"]*"[^>]*>([^<]+)</Usi', $html, $titleMatch)) {
                $fullTitle = html_entity_decode(trim($titleMatch[1]), ENT_QUOTES, 'UTF-8');
                if ($fullTitle && strlen($fullTitle) > strlen($title)) {
                    $title = $fullTitle;
                }
            }
            $books[] = buildMinimalBook($asin, $title, $seriesAsin, $seriesName, $region, $domain, $html);
        }
    }
}

// Enrich minimal books with additional details from surrounding HTML
foreach ($books as &$book) {
    $asin = $book["asin"];

    // Try to find release date from HTML if not already set
    if (!$book["releaseDate"]) {
        if (preg_match('/data-asin="' . preg_quote($asin, '/') . '".*?Release date[:\s]*([A-Za-z]+\s+\d{1,2},?\s+\d{4})/Usi', $html, $dateMatch)) {
            $parsedDate = strtotime($dateMatch[1]);
            $book["releaseDate"] = $parsedDate ? date("Y-m-d", $parsedDate) : null;
            // Update isAvailable based on release date
            if ($parsedDate && $parsedDate > time()) {
                $book["isAvailable"] = false;
            }
        }
    }

    // Try to find cover image near this ASIN if not already set
    if (!$book["imageUrl"]) {
        if (preg_match('/data-asin="' . preg_quote($asin, '/') . '".*?<img[^>]*src="(https:\/\/m\.media-amazon\.com\/images\/[^"]+)"/Usi', $html, $imgMatch)) {
            $book["imageUrl"] = $imgMatch[1];
        }
    }
}
unset($book);

// Filter books to series-relevant titles
if ($seriesName) {
    $baseSeriesName = trim($seriesName);
    $flexibleName = preg_replace('/^The\s+/i', '', $baseSeriesName);

    $filteredBooks = [];
    foreach ($books as $book) {
        $title = $book["title"] ?? "";
        if (stripos($title, $baseSeriesName) !== false ||
            stripos($title, $flexibleName) !== false) {
            $filteredBooks[] = $book;
        }
    }

    if (count($filteredBooks) >= 1) {
        $books = $filteredBooks;
    }
}

// Sort books by position/number
usort($books, function($a, $b) {
    $posA = $a["series"][0]["position"] ?? "";
    $posB = $b["series"][0]["position"] ?? "";
    $numA = is_numeric($posA) ? (float)$posA : 0;
    $numB = is_numeric($posB) ? (float)$posB : 0;
    if ($numA === $numB) {
        // Fallback to extracting number from title
        preg_match('/(\d+)/', $a["title"] ?? "", $matchA);
        preg_match('/(\d+)/', $b["title"] ?? "", $matchB);
        $numA = isset($matchA[1]) ? (int)$matchA[1] : 0;
        $numB = isset($matchB[1]) ? (int)$matchB[1] : 0;
    }
    return $numA - $numB;
});

// -----------------------------------------------------------------------------
// Step 6: Return results
// -----------------------------------------------------------------------------

// Return in audimeta-compatible format:
// The audiMetaResponse is the array of book objects (same as audimeta /series/{ASIN}/books)
// Include both the direct envelope and the audimeta-compatible format
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
    "source" => "audible_direct"
]);

// -----------------------------------------------------------------------------
// Helper functions
// -----------------------------------------------------------------------------

/**
 * Build a full audimeta-compatible book object from JSON-LD data.
 */
function buildAudiMetaBook($item, $bookAsin, $seriesAsin, $seriesName, $region, $domain) {
    // Authors
    $authors = [];
    if (isset($item["author"])) {
        $authorList = is_array($item["author"]) && isset($item["author"][0])
            ? $item["author"]
            : [$item["author"]];
        $authors = array_map(function($a) {
            return ["name" => is_array($a) ? ($a["name"] ?? "") : (string)$a];
        }, $authorList);
    }

    // Narrators (readBy)
    $narrators = [];
    if (isset($item["readBy"])) {
        $narratorList = is_array($item["readBy"]) && isset($item["readBy"][0])
            ? $item["readBy"]
            : [$item["readBy"]];
        $narrators = array_map(function($n) {
            return ["name" => is_array($n) ? ($n["name"] ?? "") : (string)$n];
        }, $narratorList);
    }

    // Release date
    $releaseDate = $item["datePublished"] ?? null;
    $isAvailable = true;
    if ($releaseDate) {
        $ts = strtotime($releaseDate);
        if ($ts !== false) {
            $releaseDate = date("Y-m-d", $ts);
            if ($ts > time()) {
                $isAvailable = false;
            }
        }
    }

    // Duration to minutes
    $lengthMinutes = null;
    if (isset($item["duration"])) {
        $lengthMinutes = parseDurationToMinutes($item["duration"]);
    }

    // Image
    $imageUrl = null;
    if (isset($item["image"])) {
        $imageUrl = is_array($item["image"]) ? ($item["image"][0] ?? null) : $item["image"];
    }

    // Publisher
    $publisher = null;
    if (isset($item["publisher"])) {
        $pub = $item["publisher"];
        $publisher = is_array($pub) ? ($pub["name"] ?? $pub[0] ?? null) : $pub;
    }

    // Rating
    $rating = null;
    if (isset($item["aggregateRating"]["ratingValue"])) {
        $rating = (float)$item["aggregateRating"]["ratingValue"];
    }

    // Book format
    $bookFormat = "unabridged";
    if (isset($item["abridged"]) && $item["abridged"] === true) {
        $bookFormat = "abridged";
    }

    // Series position - extract from title
    $title = $item["name"] ?? "";
    $position = extractBookNumber($title);

    // Description
    $summary = $item["description"] ?? null;

    return [
        "asin" => $bookAsin,
        "title" => $title,
        "subtitle" => null,
        "authors" => $authors,
        "narrators" => $narrators,
        "series" => [[
            "asin" => $seriesAsin,
            "name" => $seriesName,
            "position" => $position
        ]],
        "releaseDate" => $releaseDate,
        "region" => $region,
        "isAvailable" => $isAvailable,
        "imageUrl" => $imageUrl,
        "link" => "https://$domain/pd/$bookAsin",
        "bookFormat" => $bookFormat,
        "rating" => $rating,
        "summary" => $summary,
        "publisher" => $publisher,
        "genres" => [],
        "lengthMinutes" => $lengthMinutes
    ];
}

/**
 * Build a minimal audimeta-compatible book object from basic parsed data.
 * Used when JSON-LD is not available.
 */
function buildMinimalBook($asin, $title, $seriesAsin, $seriesName, $region, $domain, $html) {
    $position = extractBookNumber($title);

    return [
        "asin" => $asin,
        "title" => $title,
        "subtitle" => null,
        "authors" => [],
        "narrators" => [],
        "series" => [[
            "asin" => $seriesAsin,
            "name" => $seriesName,
            "position" => $position
        ]],
        "releaseDate" => null,
        "region" => $region,
        "isAvailable" => true,
        "imageUrl" => null,
        "link" => "https://$domain/pd/$asin",
        "bookFormat" => "unabridged",
        "rating" => null,
        "summary" => null,
        "publisher" => null,
        "genres" => [],
        "lengthMinutes" => null
    ];
}

/**
 * Extract book number from a title string.
 * E.g., "The Primal Hunter 13" -> "13", "Book 3" -> "3"
 */
function extractBookNumber($title) {
    if (!$title) return "";
    // Match "Book N" or trailing number
    if (preg_match('/(?:Book\s+)?(\d+(?:\.\d+)?)\s*$/i', $title, $m)) {
        return $m[1];
    }
    // Match leading "Book N"
    if (preg_match('/Book\s+(\d+(?:\.\d+)?)/i', $title, $m)) {
        return $m[1];
    }
    // Match "#N" pattern
    if (preg_match('/#(\d+(?:\.\d+)?)/', $title, $m)) {
        return $m[1];
    }
    return "";
}

function extractAsinFromUrl($url) {
    if (preg_match('/\/([A-Z0-9]{10})(?:\?|$)/', $url, $match)) {
        return $match[1];
    }
    return "";
}

/**
 * Converts ISO 8601 duration (e.g., "PT8H30M") to minutes.
 */
function parseDurationToMinutes($isoDuration) {
    if (!$isoDuration) return null;

    $minutes = 0;
    if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $isoDuration, $m)) {
        $hours = isset($m[1]) ? (int)$m[1] : 0;
        $mins = isset($m[2]) ? (int)$m[2] : 0;
        $secs = isset($m[3]) ? (int)$m[3] : 0;
        $minutes = ($hours * 60) + $mins + ($secs > 30 ? 1 : 0);
    }

    if ($minutes === 0 && preg_match('/(\d+)\s*hrs?\s*(?:and\s*)?(\d+)\s*mins?/i', $isoDuration, $m)) {
        $minutes = ((int)$m[1] * 60) + (int)$m[2];
    }

    return $minutes > 0 ? $minutes : null;
}
