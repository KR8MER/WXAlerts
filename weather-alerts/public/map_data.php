<?php
/**
 * map_data.php
 * Last Modified: 2025-01-25 23:13:13 UTC
 * Modified By: KR8MER
 */

// Define the application root path
define('APP_PATH', dirname(__DIR__));

// Include the bootstrap file
require_once APP_PATH . '/includes/bootstrap.php';

// Ensure WeatherAlertSystem is loaded
require_once APP_PATH . '/includes/WeatherAlertSystem.php';

header('Content-Type: application/json');

try {
    $weatherSystem = new WeatherAlertSystem();
    $action = $_GET['action'] ?? '';
    $type = $_GET['type'] ?? '';

    switch ($action) {
        case 'boundaries':
            if (empty($type)) {
                throw new Exception('Boundary type is required');
            }
            $data = $weatherSystem->getBoundaryData($type);
            break;

        case 'active_alerts':
            $data = $weatherSystem->getActiveAlerts();
            break;

        default:
            throw new Exception('Invalid action specified');
    }

    echo json_encode($data);

} catch (Exception $e) {
    error_log("Error in map_data.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}