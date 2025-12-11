<?php
/**
 * getConfig.php - Return server configuration status
 *
 * Returns whether the server is configured with credentials.
 * Does NOT expose actual credentials, only status information.
 */

// Prevent PHP errors from corrupting JSON output
error_reporting(0);
ini_set('display_errors', '0');

header("Content-Type: application/json");
header("Cache-Control: no-cache, must-revalidate");

// Read environment variables
$serverUrl = getenv("ABS_URL") ?: "";
$useApiKey = filter_var(getenv("ABS_USE_API_KEY") ?: "false", FILTER_VALIDATE_BOOLEAN);
$hasUsername = !empty(getenv("ABS_USERNAME"));
$hasPassword = !empty(getenv("ABS_PASSWORD"));
$hasApiKey = !empty(getenv("ABS_API_KEY"));

// Determine if properly configured
$hasCredentials = $useApiKey ? $hasApiKey : ($hasUsername && $hasPassword);
$isConfigured = !empty($serverUrl) && $hasCredentials;

// Get last refresh info from data file if it exists
$lastRefresh = null;
$refreshStatus = "idle";
$dataFilePath = "/data/completeseries.json";

if (file_exists($dataFilePath)) {
    $fileContents = file_get_contents($dataFilePath);
    if ($fileContents !== false) {
        $data = json_decode($fileContents, true);
        if (is_array($data) && isset($data["serverConfig"])) {
            $lastRefresh = $data["serverConfig"]["lastRefresh"] ?? null;
            $refreshStatus = $data["serverConfig"]["refreshStatus"] ?? "idle";
        }
    }
}

// Get Audible region (defaults to "us")
$audibleRegion = getenv("AUDIBLE_REGION") ?: "us";

// Return config status (without exposing credentials)
echo json_encode([
    "status" => "success",
    "configured" => $isConfigured,
    "serverUrl" => !empty($serverUrl) ? preg_replace('/^(https?:\/\/[^\/]+).*/', '$1', $serverUrl) : null,
    "authMethod" => $useApiKey ? "api_key" : "password",
    "hasCredentials" => $hasCredentials,
    "lastRefresh" => $lastRefresh,
    "refreshStatus" => $refreshStatus,
    "audibleRegion" => $audibleRegion
]);
