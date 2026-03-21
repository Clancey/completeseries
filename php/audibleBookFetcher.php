<?php
// audibleBookFetcher.php
// Fetches book metadata directly from Audible product pages.
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

$asin = trim($input["asin"] ?? "");
$region = strtolower(trim($input["region"] ?? "us"));

if (!$asin) {
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
$bookUrl = "https://$domain/pd/$asin";

// -----------------------------------------------------------------------------
// Step 3: Fetch the Audible book page
// -----------------------------------------------------------------------------

$curl = curl_init($bookUrl);
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
        "message" => "Failed to fetch Audible book page",
        "httpCode" => $httpCode,
        "curlError" => $curlError
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Step 4: Parse the HTML for book data
// -----------------------------------------------------------------------------

$book = [
    "asin" => $asin,
    "title" => "",
    "subtitle" => null,
    "authors" => [],
    "narrators" => [],
    "series" => [],
    "releaseDate" => null,
    "region" => $region,
    "isAvailable" => true,
    "imageUrl" => null,
    "link" => $bookUrl,
    "bookFormat" => "unabridged",
    "rating" => null,
    "summary" => null,
    "publisher" => null,
    "genres" => [],
    "lengthMinutes" => null
];

// Method 1: JSON-LD structured data (most reliable)
$jsonLdParsed = false;
preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $jsonLdMatches, PREG_SET_ORDER);
foreach ($jsonLdMatches as $jsonLdMatch) {
    $jsonLd = json_decode($jsonLdMatch[1], true);
    if (!$jsonLd) continue;

    // Handle @graph structure
    $items = isset($jsonLd["@graph"]) ? $jsonLd["@graph"] : [$jsonLd];
    foreach ($items as $item) {
        $type = $item["@type"] ?? "";
        if ($type !== "Audiobook" && $type !== "Book") continue;

        $jsonLdParsed = true;

        // Title
        $book["title"] = $item["name"] ?? "";

        // Authors
        if (isset($item["author"])) {
            $authors = is_array($item["author"]) && isset($item["author"][0])
                ? $item["author"]
                : [$item["author"]];
            $book["authors"] = array_map(function($a) {
                return ["name" => is_array($a) ? ($a["name"] ?? "") : $a];
            }, $authors);
        }

        // Narrators (readBy)
        if (isset($item["readBy"])) {
            $narrators = is_array($item["readBy"]) && isset($item["readBy"][0])
                ? $item["readBy"]
                : [$item["readBy"]];
            $book["narrators"] = array_map(function($n) {
                return ["name" => is_array($n) ? ($n["name"] ?? "") : $n];
            }, $narrators);
        }

        // Release date
        if (isset($item["datePublished"])) {
            $book["releaseDate"] = $item["datePublished"];
        }

        // Duration (ISO 8601 to minutes)
        if (isset($item["duration"])) {
            $book["lengthMinutes"] = parseDurationToMinutes($item["duration"]);
        }

        // Image
        if (isset($item["image"])) {
            $book["imageUrl"] = is_array($item["image"]) ? ($item["image"][0] ?? null) : $item["image"];
        }

        // Description / Summary
        if (isset($item["description"])) {
            $book["summary"] = $item["description"];
        }

        // Publisher
        if (isset($item["publisher"])) {
            $pub = $item["publisher"];
            $book["publisher"] = is_array($pub) ? ($pub["name"] ?? $pub[0] ?? null) : $pub;
        }

        // Rating
        if (isset($item["aggregateRating"]["ratingValue"])) {
            $book["rating"] = (float)$item["aggregateRating"]["ratingValue"];
        }

        // Abridged status
        if (isset($item["abridged"])) {
            $book["bookFormat"] = $item["abridged"] === true ? "abridged" : "unabridged";
        }

        break; // Use first matching Audiobook
    }
}

// Method 2: Meta tag fallbacks for title and image
if (!$book["title"]) {
    if (preg_match('/<meta\s+property="og:title"\s+content="([^"]+)"/i', $html, $m)) {
        $book["title"] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
}
if (!$book["imageUrl"]) {
    if (preg_match('/<meta\s+property="og:image"\s+content="([^"]+)"/i', $html, $m)) {
        $book["imageUrl"] = $m[1];
    }
}

// Method 3: HTML parsing fallbacks for fields not in JSON-LD
// Release date from page text
if (!$book["releaseDate"]) {
    if (preg_match('/Release date[:\s]*([A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $html, $m)) {
        $book["releaseDate"] = date("Y-m-d", strtotime($m[1]));
    }
}

// Subtitle from page
if (!$book["subtitle"]) {
    if (preg_match('/<li[^>]*class="[^"]*bc-list-item\s+subtitle[^"]*"[^>]*>\s*(?:<span[^>]*>)?\s*(.*?)\s*(?:<\/span>)?\s*<\/li>/is', $html, $m)) {
        $book["subtitle"] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
    }
}

// Narrators from page if not from JSON-LD
if (empty($book["narrators"])) {
    if (preg_match('/<li[^>]*class="[^"]*narratorLabel[^"]*"[^>]*>(.*?)<\/li>/is', $html, $m)) {
        preg_match_all('/<a[^>]*>([^<]+)<\/a>/i', $m[1], $narratorMatches);
        if (!empty($narratorMatches[1])) {
            $book["narrators"] = array_map(function($n) {
                return ["name" => html_entity_decode(trim($n), ENT_QUOTES, 'UTF-8')];
            }, $narratorMatches[1]);
        }
    }
}

// Publisher from page if not from JSON-LD
if (!$book["publisher"]) {
    if (preg_match('/<li[^>]*class="[^"]*publisherLabel[^"]*"[^>]*>(.*?)<\/li>/is', $html, $m)) {
        if (preg_match('/<a[^>]*>([^<]+)<\/a>/i', $m[1], $pubMatch)) {
            $book["publisher"] = html_entity_decode(trim($pubMatch[1]), ENT_QUOTES, 'UTF-8');
        }
    }
}

// Format (unabridged/abridged) from page
if ($book["bookFormat"] === "unabridged") {
    // Only override if explicitly abridged
    if (preg_match('/\bAbridged\b/i', $html) && !preg_match('/\bUnabridged\b/i', $html)) {
        $book["bookFormat"] = "abridged";
    }
}

// Genres from breadcrumbs or category labels
if (empty($book["genres"])) {
    if (preg_match_all('/<a[^>]*class="[^"]*bc-color-link[^"]*"[^>]*href="\/cat\/[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $genreMatches)) {
        $book["genres"] = array_map(function($g) {
            return ["name" => html_entity_decode(trim($g), ENT_QUOTES, 'UTF-8')];
        }, array_unique($genreMatches[1]));
    }
}

// -----------------------------------------------------------------------------
// Step 5: Extract series information
// -----------------------------------------------------------------------------

// Method A: Look for series links in the page
// Pattern: <a href="/series/ASIN...">Series Name</a> with nearby "Book N" text
if (preg_match_all('/<a[^>]*href="[^"]*\/series\/([A-Z0-9]{10})[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $seriesMatches, PREG_SET_ORDER)) {
    $seenSeries = [];
    foreach ($seriesMatches as $sm) {
        $seriesAsin = $sm[1];
        $seriesName = html_entity_decode(trim($sm[2]), ENT_QUOTES, 'UTF-8');

        if (isset($seenSeries[$seriesAsin])) continue;
        $seenSeries[$seriesAsin] = true;

        // Try to find the position near this series reference
        $position = "";

        // Look for "Book N" pattern near the series link
        $seriesContext = "";
        $seriesLinkPos = strpos($html, $sm[0]);
        if ($seriesLinkPos !== false) {
            // Get surrounding context (500 chars before and after)
            $start = max(0, $seriesLinkPos - 500);
            $end = min(strlen($html), $seriesLinkPos + strlen($sm[0]) + 500);
            $seriesContext = substr($html, $start, $end - $start);
        }

        // Match patterns like "Book 3", ", Book 3", "Book 3,", "#3"
        if (preg_match('/(?:Book|#)\s*(\d+(?:\.\d+)?)/i', $seriesContext, $posMatch)) {
            $position = $posMatch[1];
        }

        $book["series"][] = [
            "asin" => $seriesAsin,
            "name" => $seriesName,
            "position" => $position
        ];
    }
}

// Method B: Look for series info in structured data or breadcrumbs
if (empty($book["series"])) {
    // Try isPartOf in JSON-LD
    foreach ($jsonLdMatches as $jsonLdMatch) {
        $jsonLd = json_decode($jsonLdMatch[1], true);
        if (!$jsonLd) continue;
        $items = isset($jsonLd["@graph"]) ? $jsonLd["@graph"] : [$jsonLd];
        foreach ($items as $item) {
            if (isset($item["isPartOf"])) {
                $parts = is_array($item["isPartOf"]) && isset($item["isPartOf"][0])
                    ? $item["isPartOf"]
                    : [$item["isPartOf"]];
                foreach ($parts as $part) {
                    $partUrl = $part["url"] ?? "";
                    $partName = $part["name"] ?? "";
                    $partPosition = $part["position"] ?? "";
                    $partAsin = "";
                    if (preg_match('/\/series\/([A-Z0-9]{10})/', $partUrl, $asinMatch)) {
                        $partAsin = $asinMatch[1];
                    }
                    if ($partAsin) {
                        $book["series"][] = [
                            "asin" => $partAsin,
                            "name" => html_entity_decode(trim($partName), ENT_QUOTES, 'UTF-8'),
                            "position" => (string)$partPosition
                        ];
                    }
                }
            }
        }
    }
}

// Method C: Look for series in breadcrumb-style navigation
if (empty($book["series"])) {
    if (preg_match_all('/series\/([A-Z0-9]{10})/i', $html, $asinMatches)) {
        $uniqueAsins = array_unique($asinMatches[1]);
        foreach ($uniqueAsins as $sAsin) {
            // Try to find the series name near this ASIN
            $sName = "";
            if (preg_match('/series\/' . preg_quote($sAsin, '/') . '[^"]*"[^>]*>([^<]+)/i', $html, $nameMatch)) {
                $sName = html_entity_decode(trim($nameMatch[1]), ENT_QUOTES, 'UTF-8');
            }
            $book["series"][] = [
                "asin" => $sAsin,
                "name" => $sName,
                "position" => ""
            ];
        }
    }
}

// -----------------------------------------------------------------------------
// Step 6: Determine availability from release date
// -----------------------------------------------------------------------------

if ($book["releaseDate"]) {
    $releaseTimestamp = strtotime($book["releaseDate"]);
    if ($releaseTimestamp !== false && $releaseTimestamp > time()) {
        $book["isAvailable"] = false;
    }
    // Normalize release date to Y-m-d format
    if ($releaseTimestamp !== false) {
        $book["releaseDate"] = date("Y-m-d", $releaseTimestamp);
    }
}

// Clean up title - remove " | Audible.com" etc. suffixes
$book["title"] = preg_replace('/\s*\|.*$/', '', $book["title"]);
$book["title"] = preg_replace('/\s*\(Unabridged\)\s*$/i', '', $book["title"]);

// -----------------------------------------------------------------------------
// Step 7: Return result in audimeta-compatible format
// -----------------------------------------------------------------------------

// Return in the same shape that fetchAudimetaMetadata.js expects:
// { audiMetaResponse: bookObject, responseHeaders: {...} }
echo json_encode([
    "audiMetaResponse" => $book,
    "responseHeaders" => [
        "requestLimit" => "60",
        "requestRemaining" => "59",
        "cached" => null
    ]
]);

// -----------------------------------------------------------------------------
// Helper functions
// -----------------------------------------------------------------------------

/**
 * Converts ISO 8601 duration (e.g., "PT8H30M") to minutes.
 */
function parseDurationToMinutes($isoDuration) {
    if (!$isoDuration) return null;

    // Handle ISO 8601 format: PT8H30M45S
    $minutes = 0;
    if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $isoDuration, $m)) {
        $hours = isset($m[1]) ? (int)$m[1] : 0;
        $mins = isset($m[2]) ? (int)$m[2] : 0;
        $secs = isset($m[3]) ? (int)$m[3] : 0;
        $minutes = ($hours * 60) + $mins + ($secs > 30 ? 1 : 0);
    }

    // Handle "X hrs and Y mins" format
    if ($minutes === 0 && preg_match('/(\d+)\s*hrs?\s*(?:and\s*)?(\d+)\s*mins?/i', $isoDuration, $m)) {
        $minutes = ((int)$m[1] * 60) + (int)$m[2];
    }

    return $minutes > 0 ? $minutes : null;
}
