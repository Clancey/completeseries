<?php
/**
 * getData.php - Fetch server-side stored data
 *
 * Returns the complete data structure from the persistent storage file.
 * Only works when server is configured via environment variables.
 * If not configured, returns "not_configured" status so client uses local storage.
 */

// Prevent PHP errors from corrupting JSON output
error_reporting(0);
ini_set('display_errors', '0');

header("Content-Type: application/json");
header("Cache-Control: no-cache, must-revalidate");

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

// Default data structure
$defaultData = [
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

// Check if data file exists
if (!file_exists($dataFilePath)) {
    echo json_encode([
        "status" => "success",
        "data" => $defaultData,
        "source" => "default"
    ]);
    exit;
}

// Read and parse existing data
$fileContents = file_get_contents($dataFilePath);
if ($fileContents === false) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to read data file"
    ]);
    exit;
}

$data = json_decode($fileContents, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON in data file: " . json_last_error_msg()
    ]);
    exit;
}

// Merge with defaults to ensure all keys exist
$data = array_merge($defaultData, $data ?? []);

echo json_encode([
    "status" => "success",
    "data" => $data,
    "source" => "file"
]);
