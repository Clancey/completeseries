<?php
// audibleSeriesFetcher.php
// Fetches series data directly from Audible as a fallback when audimeta.de is incomplete

header("Content-Type: application/json");

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
// Step 4: Parse the HTML for book data
// -----------------------------------------------------------------------------

$books = [];
$seenAsins = [];

// Method 1: Look for JSON-LD structured data (schema.org) - most reliable
preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $jsonLdMatches, PREG_SET_ORDER);
foreach ($jsonLdMatches as $jsonLdMatch) {
    $jsonLd = json_decode($jsonLdMatch[1], true);
    if ($jsonLd) {
        // Handle @graph structure
        $items = isset($jsonLd["@graph"]) ? $jsonLd["@graph"] : [$jsonLd];
        foreach ($items as $item) {
            if (isset($item["@type"]) && $item["@type"] === "Audiobook") {
                $book = parseJsonLdBook($item);
                if ($book["asin"] && !isset($seenAsins[$book["asin"]])) {
                    $seenAsins[$book["asin"]] = true;
                    $books[] = $book;
                }
            }
        }
    }
}

// Method 2: Parse product cards - look for li elements with data-asin
if (empty($books)) {
    // Pattern: Find li elements with data-asin attribute, then extract title from h3/a
    preg_match_all('/<li[^>]*data-asin="([A-Z0-9]{10})"[^>]*>.*?<h3[^>]*>.*?<a[^>]*>([^<]+)<\/a>/Usi', $html, $liMatches, PREG_SET_ORDER);

    foreach ($liMatches as $match) {
        $asin = $match[1];
        $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
        if ($asin && $title && !isset($seenAsins[$asin])) {
            $seenAsins[$asin] = true;
            $books[] = [
                "asin" => $asin,
                "title" => $title,
                "source" => "li_parse"
            ];
        }
    }
}

// Method 3: Parse product links with data-asin - fallback pattern
if (empty($books)) {
    preg_match_all('/<a[^>]*href="\/pd\/([^"?]+)\?[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $linkMatches, PREG_SET_ORDER);

    foreach ($linkMatches as $match) {
        // Extract ASIN from URL path (usually the last segment before query params)
        $urlPath = $match[1];
        if (preg_match('/([A-Z0-9]{10})$/', $urlPath, $asinMatch)) {
            $asin = $asinMatch[1];
            $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
            // Filter out non-book titles
            if ($asin && $title && !isset($seenAsins[$asin]) &&
                !preg_match('/^(Buy|Add|Listen|Play|Sample|Preview|Reviews?)/i', $title)) {
                $seenAsins[$asin] = true;
                $books[] = [
                    "asin" => $asin,
                    "title" => $title,
                    "source" => "link_parse"
                ];
            }
        }
    }
}

// Method 4: Look for aria-label on product links
if (empty($books)) {
    preg_match_all('/data-asin="([A-Z0-9]{10})"[^>]*aria-label="([^"]+)"/i', $html, $ariaMatches, PREG_SET_ORDER);

    foreach ($ariaMatches as $match) {
        $asin = $match[1];
        $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
        if ($asin && $title && !isset($seenAsins[$asin])) {
            $seenAsins[$asin] = true;
            $books[] = [
                "asin" => $asin,
                "title" => $title,
                "source" => "aria_parse"
            ];
        }
    }
}

// Method 5: Find book numbers with ASINs (series page specific)
if (empty($books)) {
    // Pattern for series book listings - "Book N" pattern
    preg_match_all('/Book\s+(\d+)[^<]*<\/.*?data-asin="([A-Z0-9]{10})"/Usi', $html, $bookNumMatches, PREG_SET_ORDER);

    foreach ($bookNumMatches as $match) {
        $bookNum = $match[1];
        $asin = $match[2];
        if ($asin && !isset($seenAsins[$asin])) {
            $seenAsins[$asin] = true;
            $books[] = [
                "asin" => $asin,
                "title" => "Book $bookNum",
                "bookNumber" => (int)$bookNum,
                "source" => "book_num_parse"
            ];
        }
    }
}

// Extract additional book details (release date, title) for each found ASIN
foreach ($books as &$book) {
    $asin = $book["asin"];

    // Try to find the full title near this ASIN if we only have "Book N"
    if (preg_match('/Book \d+/', $book["title"])) {
        // Look for the actual title nearby
        if (preg_match('/data-asin="' . preg_quote($asin, '/') . '"[^>]*>.*?class="[^"]*bc-heading[^"]*"[^>]*>([^<]+)</Usi', $html, $titleMatch)) {
            $fullTitle = html_entity_decode(trim($titleMatch[1]), ENT_QUOTES, 'UTF-8');
            if ($fullTitle && strlen($fullTitle) > strlen($book["title"])) {
                $book["title"] = $fullTitle;
            }
        }
    }

    // Try to find release date
    if (preg_match('/data-asin="' . preg_quote($asin, '/') . '".*?Release date[:\s]*([A-Za-z]+\s+\d{1,2},?\s+\d{4})/Usi', $html, $dateMatch)) {
        $book["releaseDate"] = $dateMatch[1];
    }
}
unset($book);

// Extract series name from page title
$seriesName = "";
if (preg_match('/<title>([^<|]+)/i', $html, $titleMatch)) {
    $seriesName = html_entity_decode(trim($titleMatch[1]), ENT_QUOTES, 'UTF-8');
    // Clean up the title (often formatted as "Series Name | Audible.com")
    $seriesName = preg_replace('/\s*\|.*$/', '', $seriesName);
    $seriesName = preg_replace('/^Series:\s*/i', '', $seriesName);
    // Remove "Audiobooks" suffix if present
    $seriesName = preg_replace('/\s*Audiobooks?\s*$/i', '', $seriesName);
}

// Filter books to only include those matching the series name
if ($seriesName) {
    // Create a base pattern from the series name (e.g., "The Primal Hunter" -> pattern matches "The Primal Hunter", "Primal Hunter", etc.)
    $baseSeriesName = trim($seriesName);
    // Remove leading "The" for more flexible matching
    $flexibleName = preg_replace('/^The\s+/i', '', $baseSeriesName);

    $filteredBooks = [];
    foreach ($books as $book) {
        $title = $book["title"] ?? "";
        // Check if the title contains the series name (with or without "The")
        if (stripos($title, $baseSeriesName) !== false ||
            stripos($title, $flexibleName) !== false) {
            $filteredBooks[] = $book;
        }
    }

    // Only use filtered list if we found at least some matches
    if (count($filteredBooks) >= 1) {
        $books = $filteredBooks;
    }
}

// Sort books by extracting book number from title
usort($books, function($a, $b) {
    $numA = 0;
    $numB = 0;
    if (preg_match('/(\d+)/', $a["title"] ?? "", $matchA)) {
        $numA = (int)$matchA[1];
    }
    if (preg_match('/(\d+)/', $b["title"] ?? "", $matchB)) {
        $numB = (int)$matchB[1];
    }
    return $numA - $numB;
});

// -----------------------------------------------------------------------------
// Step 5: Return results
// -----------------------------------------------------------------------------

echo json_encode([
    "status" => "success",
    "seriesAsin" => $seriesAsin,
    "seriesName" => $seriesName,
    "region" => $region,
    "bookCount" => count($books),
    "books" => $books,
    "source" => "audible_direct"
]);

// -----------------------------------------------------------------------------
// Helper function to parse JSON-LD book data
// -----------------------------------------------------------------------------

function parseJsonLdBook($item) {
    return [
        "asin" => extractAsinFromUrl($item["url"] ?? ""),
        "title" => $item["name"] ?? "",
        "authors" => isset($item["author"]) ? (is_array($item["author"]) ? array_map(fn($a) => $a["name"] ?? $a, $item["author"]) : [$item["author"]["name"] ?? $item["author"]]) : [],
        "releaseDate" => $item["datePublished"] ?? null,
        "duration" => $item["duration"] ?? null,
        "source" => "json_ld"
    ];
}

function extractAsinFromUrl($url) {
    if (preg_match('/\/([A-Z0-9]{10})(?:\?|$)/', $url, $match)) {
        return $match[1];
    }
    return "";
}
