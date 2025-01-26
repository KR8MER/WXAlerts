<?php
// includes/bootstrap.php

// Define application paths
define('APP_PATH', realpath(__DIR__ . '/..'));
define('CONFIG_PATH', APP_PATH . '/config');
define('INCLUDES_PATH', APP_PATH . '/includes');
define('PUBLIC_PATH', APP_PATH . '/public');
define('LOGS_PATH', APP_PATH . '/logs');
define('TMP_PATH', APP_PATH . '/tmp');

// Set timezone
date_default_timezone_set('America/New_York');

// Error reporting settings
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOGS_PATH . '/error.log');

// Create required directories if they don't exist
$requiredDirs = [
    LOGS_PATH,
    TMP_PATH
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            error_log("Failed to create directory: $dir");
        } else {
            error_log("Created directory: $dir");
        }
    }
}

// Verify critical files exist
$requiredFiles = [
    INCLUDES_PATH . '/WeatherAlertSystem.php',
    CONFIG_PATH . '/database.php'
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Critical file missing: $file");
        die("Required file not found: " . basename($file));
    }
}

// Initialize any global settings or configurations here
error_log("Bootstrap completed successfully");