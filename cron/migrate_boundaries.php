<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . "/../includes/WeatherAlertSystem.php";

$alertSystem = new WeatherAlertSystem();

function logBoundaryData($type) {
    global $alertSystem;
    $data = $alertSystem->getBoundaryData($type);
    error_log("Boundary Data for {$type}: " . json_encode($data));
}

function logActiveAlerts() {
    global $alertSystem;
    $alerts = $alertSystem->getActiveAlerts();
    error_log("Active Alerts: " . json_encode($alerts));
}

// Log data for different boundary types
logBoundaryData('county');
logBoundaryData('township');
logBoundaryData('electric');
logBoundaryData('fire');
logBoundaryData('ems');
logBoundaryData('school');
logBoundaryData('village');
logBoundaryData('telephone');

// Log active alerts
logActiveAlerts();
?>