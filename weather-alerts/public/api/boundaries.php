<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/WeatherAlertSystem.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';
if (!in_array($type, ['fire', 'ems', 'electric'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid boundary type']);
    exit;
}

$weatherSystem = new WeatherAlertSystem();
$boundaries = $weatherSystem->getBoundaryData($type);

echo json_encode($boundaries);