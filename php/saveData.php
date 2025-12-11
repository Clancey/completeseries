<?php
/**
 * saveData.php - Save client data to server-side storage
 *
 * Only works when server is configured via environment variables.
 * Accepts partial updates and merges with existing data.
 * Uses atomic write (temp file + rename) to prevent corruption.
 */

header("Content-Type: application/json");

// Check if server is configured
$serverUrl = getenv("ABS_URL") ?: "";
$useApiKey = filter_var(getenv("ABS_USE_API_KEY") ?: "false", FILTER_VALIDATE_BOOLEAN);
$hasCredentials = $useApiKey
    ? !empty(getenv("ABS_API_KEY"))
    : (!empty(getenv("ABS_USERNAME")) && !empty(getenv("ABS_PASSWORD")));

$isConfigured = !empty($serverUrl) && $hasCredentials;

// If not configured, return status so client knows to use local storage only
if (!$isConfigured) {
    echo json_encode([
        "status" => "not_configured",
        "message" => "Server not configured. Using client-side storage only."
    ]);
    exit;
}

$dataFilePath = "/data/completeseries.json";
$dataDir = "/data";

// Read input
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON input"]);
    exit;
}

// Validate allowed keys
$allowedKeys = ["hiddenItems", "existingFirstBookASINs", "existingBookMetadata"];
$hasValidKey = false;
foreach ($allowedKeys as $key) {
    if (array_key_exists($key, $input)) {
        $hasValidKey = true;
        if (!is_array($input[$key])) {
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Key '$key' must be an array"
            ]);
            exit;
        }
    }
}

if (!$hasValidKey) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "No valid data keys provided. Allowed: " . implode(", ", $allowedKeys)
    ]);
    exit;
}

// Ensure data directory exists and is writable
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Cannot create data directory"
        ]);
        exit;
    }
}

// Load existing data or initialize
$existingData = [
    "version" => 1,
    "lastUpdated" => null,
    "hiddenItems" => [],
    "existingFirstBookASINs" => [],
    "existingBookMetadata" => [],
    "serverConfig" => [
        "lastRefresh" => null,
        "refreshStatus" => "idle"
    ]
];

if (file_exists($dataFilePath)) {
    $fileContents = file_get_contents($dataFilePath);
    if ($fileContents !== false) {
        $parsed = json_decode($fileContents, true);
        if (is_array($parsed)) {
            $existingData = array_merge($existingData, $parsed);
        }
    }
}

// Merge incoming data
foreach ($allowedKeys as $key) {
    if (array_key_exists($key, $input)) {
        $existingData[$key] = $input[$key];
    }
}

// Update timestamp
$existingData["lastUpdated"] = gmdate("c");

// Write atomically using temp file + rename
$tempFile = $dataFilePath . ".tmp." . uniqid();
$jsonOutput = json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if (file_put_contents($tempFile, $jsonOutput) === false) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to write temporary file"
    ]);
    exit;
}

if (!rename($tempFile, $dataFilePath)) {
    @unlink($tempFile);
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to save data file"
    ]);
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Data saved successfully",
    "lastUpdated" => $existingData["lastUpdated"]
]);
