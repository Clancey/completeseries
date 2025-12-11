<?php
/**
 * diagnostic.php - Check server configuration and permissions
 *
 * Returns detailed information about the server environment
 * to help debug data persistence issues.
 */

// Prevent PHP errors from corrupting JSON output
error_reporting(0);
ini_set('display_errors', '0');

header("Content-Type: application/json");
header("Cache-Control: no-cache, must-revalidate");

$dataDir = "/data";
$dataFilePath = "/data/completeseries.json";

$diagnostic = [
    "timestamp" => gmdate("c"),
    "php_version" => PHP_VERSION,
    "data_directory" => [
        "path" => $dataDir,
        "exists" => is_dir($dataDir),
        "is_writable" => is_writable($dataDir),
        "permissions" => is_dir($dataDir) ? substr(sprintf('%o', fileperms($dataDir)), -4) : null,
        "owner" => is_dir($dataDir) ? posix_getpwuid(fileowner($dataDir))['name'] ?? fileowner($dataDir) : null
    ],
    "data_file" => [
        "path" => $dataFilePath,
        "exists" => file_exists($dataFilePath),
        "is_readable" => file_exists($dataFilePath) ? is_readable($dataFilePath) : null,
        "is_writable" => file_exists($dataFilePath) ? is_writable($dataFilePath) : null,
        "size_bytes" => file_exists($dataFilePath) ? filesize($dataFilePath) : null,
        "last_modified" => file_exists($dataFilePath) ? date("c", filemtime($dataFilePath)) : null
    ],
    "process" => [
        "user" => posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid(),
        "uid" => posix_geteuid(),
        "gid" => posix_getegid()
    ],
    "environment" => [
        "ABS_URL_set" => !empty(getenv("ABS_URL")),
        "ABS_USERNAME_set" => !empty(getenv("ABS_USERNAME")),
        "ABS_PASSWORD_set" => !empty(getenv("ABS_PASSWORD")),
        "ABS_API_KEY_set" => !empty(getenv("ABS_API_KEY")),
        "ABS_USE_API_KEY" => getenv("ABS_USE_API_KEY") ?: "false",
        "AUDIBLE_REGION" => getenv("AUDIBLE_REGION") ?: "us"
    ]
];

// Try to write a test file
$testFile = $dataDir . "/test_" . uniqid() . ".tmp";
$writeTest = @file_put_contents($testFile, "test");
$diagnostic["write_test"] = [
    "success" => $writeTest !== false,
    "bytes_written" => $writeTest !== false ? $writeTest : null
];
if ($writeTest !== false) {
    @unlink($testFile);
}

// If data file exists, show a preview
if (file_exists($dataFilePath)) {
    $content = @file_get_contents($dataFilePath);
    if ($content !== false) {
        $data = json_decode($content, true);
        if (is_array($data)) {
            $diagnostic["data_preview"] = [
                "version" => $data["version"] ?? null,
                "lastUpdated" => $data["lastUpdated"] ?? null,
                "hiddenItems_count" => is_array($data["hiddenItems"] ?? null) ? count($data["hiddenItems"]) : null,
                "existingFirstBookASINs_count" => is_array($data["existingFirstBookASINs"] ?? null) ? count($data["existingFirstBookASINs"]) : null,
                "seriesAllASIN_count" => is_array($data["seriesAllASIN"] ?? null) ? count($data["seriesAllASIN"]) : null,
                "serverConfig" => $data["serverConfig"] ?? null
            ];
        } else {
            $diagnostic["data_preview"] = ["error" => "Invalid JSON: " . json_last_error_msg()];
        }
    } else {
        $diagnostic["data_preview"] = ["error" => "Cannot read file"];
    }
}

echo json_encode($diagnostic, JSON_PRETTY_PRINT);
