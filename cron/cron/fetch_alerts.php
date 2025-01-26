<?php
// cron/fetch_alerts.php

// Include bootstrap first
require_once __DIR__ . '/../includes/bootstrap.php';

// Full path to your application - this must come FIRST, before any includes
define('APP_PATH', '/var/www/html/weewx/saratoga/weather-alerts');

// Set up error logging with rotation
$logFile = APP_PATH . '/logs/cron.log';
$maxLogSize = 10 * 1024 * 1024; // 10MB

// Rotate log if it's too big
if (file_exists($logFile) && filesize($logFile) > $maxLogSize) {
    rename($logFile, $logFile . '.' . date('Y-m-d-H-i-s'));
}

ini_set('log_errors', 1);
ini_set('error_log', $logFile);
date_default_timezone_set('America/New_York');

// Set execution time limit
set_time_limit(300); // 5 minutes

// Log start time with memory usage
$start_time = microtime(true);
$mem_start = memory_get_usage();
error_log("\n=== Starting weather alert fetch at " . date('Y-m-d H:i:s') . " ===");
error_log("Initial memory usage: " . number_format($mem_start / 1024 / 1024, 2) . " MB");

// Ensure required files exist
$requiredFile = APP_PATH . '/includes/WeatherAlertSystem.php';
if (!file_exists($requiredFile)) {
    error_log("Critical Error: Required file not found: " . $requiredFile);
    exit(1);
}

require_once $requiredFile;

// Create tmp directory if it doesn't exist
$tmpDir = APP_PATH . '/tmp';
if (!is_dir($tmpDir)) {
    error_log("Creating tmp directory at: " . $tmpDir);
    if (!mkdir($tmpDir, 0775, true)) {
        error_log("Error: Unable to create tmp directory");
        exit(1);
    }
}

// Create lock file to prevent concurrent runs
$lockFile = $tmpDir . '/fetch_alerts.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 300) { // 5 minutes
        error_log("Another instance is already running (lock file exists)");
        exit(0);
    }
    error_log("Found stale lock file, removing...");
    unlink($lockFile);
}

if (!touch($lockFile)) {
    error_log("Error: Unable to create lock file");
    exit(1);
}

try {
    $alertSystem = new WeatherAlertSystem();
    
    // Test connection first
    $test = $alertSystem->testConnection();
    error_log("Connection test: " . ($test['success'] ? 'Success' : 'Failed - ' . $test['message']));
    
    if ($test['success']) {
        error_log("Number of alerts in feed: " . $test['entry_count']);
        error_log("Starting alert fetch process...");
        
        // Fetch and process alerts
        $newAlertCount = $alertSystem->fetchAlerts();
        
        // Get current active alerts for logging
        $activeAlerts = $alertSystem->getActiveAlerts();
        
        // Log completion with detailed stats
        $execution_time = microtime(true) - $start_time;
        $mem_peak = memory_get_peak_usage();
        $mem_final = memory_get_usage();
        
        error_log("Completed weather alert fetch at " . date('Y-m-d H:i:s'));
        error_log("Execution time: " . number_format($execution_time, 2) . " seconds");
        error_log("Peak memory usage: " . number_format($mem_peak / 1024 / 1024, 2) . " MB");
        error_log("Final memory usage: " . number_format($mem_final / 1024 / 1024, 2) . " MB");
        error_log("Status: " . ($newAlertCount > 0 ? "$newAlertCount new alerts found and processed" : "No new alerts"));
        
        // Log active alerts
        error_log("Current active alerts: " . count($activeAlerts));
        foreach ($activeAlerts as $alert) {
            error_log("Active alert: " . $alert['title'] . " (Expires: " . $alert['expires'] . ")");
        }
    } else {
        error_log("Skipping alert fetch due to failed connection test");
    }
} catch (Exception $e) {
    error_log("Error in weather alert fetch: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
} finally {
    // Always clean up lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

error_log("=== End weather alert fetch ===\n");

// Exit with appropriate status code
exit(isset($newAlertCount) ? 0 : 1);