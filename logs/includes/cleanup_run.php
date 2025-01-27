<?php
define('APP_PATH', '/var/www/html/weewx/saratoga/weather-alerts');

require_once APP_PATH . '/includes/WeatherAlertSystem.php';

try {
    $alertSystem = new WeatherAlertSystem();
    
    // First, let's see what alerts we have
    echo "<h3>Before Cleanup:</h3>";
    $beforeAlerts = $alertSystem->getActiveAlerts();
    foreach ($beforeAlerts as $alert) {
        echo "<p>Alert ID: " . $alert['id'] . "<br>";
        echo "Event: " . $alert['event_type'] . "<br>";
        echo "Created: " . $alert['created_at'] . "<br>";
        echo "Expires: " . $alert['expires'] . "</p>";
    }
    
    // Run cleanup
    $removedCount = $alertSystem->cleanupDuplicateAlerts();
    echo "<h3>Cleanup Results:</h3>";
    echo "<p>Removed $removedCount duplicate alerts</p>";
    
    // Check alerts after cleanup
    echo "<h3>After Cleanup:</h3>";
    $afterAlerts = $alertSystem->getActiveAlerts();
    foreach ($afterAlerts as $alert) {
        echo "<p>Alert ID: " . $alert['id'] . "<br>";
        echo "Event: " . $alert['event_type'] . "<br>";
        echo "Created: " . $alert['created_at'] . "<br>";
        echo "Expires: " . $alert['expires'] . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}