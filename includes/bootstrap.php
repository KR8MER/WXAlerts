<?php
/**
 * Weather Alert System - Bootstrap File
 * Last Modified: 2025-01-26 00:21:50
 * Modified By: KR8MER
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants
define('APP_ROOT', realpath(dirname(__FILE__, 2)));
define('CONFIG_PATH', APP_ROOT . '/config');
define('LOGS_PATH', APP_ROOT . '/logs');
define('CURRENT_USER', 'KR8MER');
define('CURRENT_UTC', '2025-01-26 00:21:50');

// Verify directory structure
$requiredDirs = [
    CONFIG_PATH,
    LOGS_PATH
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        die("Required directory not found: $dir");
    }
}

// Load configuration
if (!file_exists(CONFIG_PATH . '/config.php')) {
    die("Configuration file not found: " . CONFIG_PATH . '/config.php');
}

$config = require_once CONFIG_PATH . '/config.php';

// Initialize logging
function initializeLogging() {
    global $config;
    
    $logFile = LOGS_PATH . '/app.log';
    
    if (!is_writable(LOGS_PATH)) {
        die("Logs directory is not writable: " . LOGS_PATH);
    }
    
    ini_set('error_log', $logFile);
    error_log("Bootstrap started at " . CURRENT_UTC);
}

// Database connection function
function getDatabaseConnection() {
    global $config;
    
    try {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=utf8mb4",
            $config['db']['host'],
            $config['db']['database']
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $pdo = new PDO(
            $dsn,
            $config['db']['username'],
            $config['db']['password'],
            $options
        );
        
        // Test the connection
        $pdo->query("SELECT 1");
        
        error_log("Database connection established successfully");
        return $pdo;
        
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

// Database setup function
function setupDatabase($pdo) {
    try {
        // Create alerts table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS alerts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                alert_id VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                polygon TEXT,
                polygon_type ENUM('NONE', 'POLYGON', 'CIRCLE') DEFAULT 'NONE',
                severity ENUM('Extreme', 'Severe', 'Moderate', 'Minor', 'Unknown') NOT NULL DEFAULT 'Unknown',
                urgency VARCHAR(50),
                certainty VARCHAR(50),
                event_type VARCHAR(100),
                status ENUM('Active', 'Expired', 'Cancelled') NOT NULL DEFAULT 'Active',
                effective TIMESTAMP NOT NULL,
                expires TIMESTAMP NOT NULL,
                ends TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_alert` (alert_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Create boundaries table without SRID specification
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS boundaries (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                type ENUM('fire', 'ems', 'electric') NOT NULL,
                geometry GEOMETRY,
                properties TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                SPATIAL INDEX (geometry)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Create alert_categories table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS alert_categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Insert default categories
        $pdo->exec("
            INSERT IGNORE INTO alert_categories (name, description) VALUES
            ('SEVERE', 'Severe weather events including tornadoes, severe thunderstorms'),
            ('WINTER', 'Winter weather events including snow, ice, blizzards'),
            ('FLOOD', 'Flooding events including flash floods and river flooding'),
            ('HEAT', 'Excessive heat and heat-related events'),
            ('WIND', 'High wind events and wind-related hazards'),
            ('OTHER', 'Other weather events not fitting into specific categories')
        ");

        error_log("Database tables setup completed successfully");
        return true;
        
    } catch (PDOException $e) {
        error_log("Database setup error: " . $e->getMessage());
        throw new Exception("Database setup failed: " . $e->getMessage());
    }
}