<?php
// public/map.php
error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . "/../includes/WeatherAlertSystem.php";

$alertSystem = new WeatherAlertSystem();

// Debug: Log alert fetch attempt
error_log("Attempting to fetch active alerts...");
$activeAlerts = $alertSystem->getActiveAlerts();
error_log("Active alerts fetched. Count: " . count($activeAlerts));

// Debug: Log paths
$boundaryFile = __DIR__ . "/../data/boundaries/putnam_county_boundary.json";
$townshipFile = __DIR__ . "/../data/boundaries/townships.json";
$electricFile = __DIR__ . "/../data/boundaries/electric_providers.json";
$fireFile = __DIR__ . "/../data/boundaries/fire_districts.json";
$emsFile = __DIR__ . "/../data/boundaries/ems_districts.json";

error_log("Looking for boundary files...");

$boundaryData = "null";
$townshipData = "null";
$electricData = "null";
$fireData = "null";
$emsData = "null";

if (file_exists($boundaryFile)) {
    $boundaryData = file_get_contents($boundaryFile);
    error_log("County boundary data loaded");
} else {
    error_log("County boundary file not found at: " . $boundaryFile);
}

if (file_exists($townshipFile)) {
    $townshipData = file_get_contents($townshipFile);
    error_log("Township data loaded");
} else {
    error_log("Township file not found at: " . $townshipFile);
}

if (file_exists($electricFile)) {
    $electricData = file_get_contents($electricFile);
    error_log("Electric provider data loaded");
} else {
    error_log("Electric provider file not found at: " . $electricFile);
}

if (file_exists($fireFile)) {
    $fireData = file_get_contents($fireFile);
    error_log("Fire district data loaded");
} else {
    error_log("Fire district file not found at: " . $fireFile);
}

if (file_exists($emsFile)) {
    $emsData = file_get_contents($emsFile);
    error_log("EMS district data loaded");
} else {
    error_log("EMS district file not found at: " . $emsFile);
}

// Convert alerts to GeoJSON
function alertsToGeoJSON($alerts)
{
    error_log("Converting alerts to GeoJSON...");
    $features = [];

    foreach ($alerts as $alert) {
        if ($alert["polygon_type"] !== "NONE" && !empty($alert["polygon"])) {
            error_log("Processing alert: " . $alert["title"]);
            $coordinates = [];
            $polygonPoints = explode(" ", trim($alert["polygon"]));

            foreach ($polygonPoints as $point) {
                $parts = explode(",", trim($point));
                if (count($parts) === 2) {
                    $lat = filter_var($parts[0], FILTER_VALIDATE_FLOAT);
                    $lng = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
                    if ($lat !== false && $lng !== false) {
                        $coordinates[] = [$lng, $lat];
                    }
                }
            }

            if (count($coordinates) > 2) {
                if ($coordinates[0] !== end($coordinates)) {
                    $coordinates[] = $coordinates[0];
                }

                $features[] = [
                    "type" => "Feature",
                    "properties" => [
                        "id" => $alert["id"],
                        "title" => $alert["title"],
                        "severity" => $alert["severity"],
                        "description" => $alert["description"],
                        "expires" => $alert["expires"],
                        "urgency" => $alert["urgency"],
                        "type" => "specific",
                    ],
                    "geometry" => [
                        "type" => "Polygon",
                        "coordinates" => [$coordinates],
                    ],
                ];
                error_log("Alert processed successfully");
            }
        }
    }

    error_log(
        "GeoJSON conversion complete. Features count: " . count($features),
    );
    return ["type" => "FeatureCollection", "features" => $features];
}

$alertData = json_encode(alertsToGeoJSON($activeAlerts));

// Set page variables
$pageTitle = "Alert Map";
$headerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" class="bi bi-map me-2" viewBox="0 0 16 16">
    <path fill-rule="evenodd" d="M15.817.113A.5.5 0 0 1 16 .5v14a.5.5 0 0 1-.402.49l-5 1a.502.502 0 0 1-.196 0L5.5 15.01l-4.902.98A.5.5 0 0 1 0 15.5v-14a.5.5 0 0 1 .402-.49l5-1a.5.5 0 0 1 .196 0L10.5.99l4.902-.98a.5.5 0 0 1 .415.103zM10 1.91l-4-.8v12.98l4 .8V1.91zm1 12.98 4-.8V1.11l-4 .8v12.98zm-6-.8V1.11l-4 .8v12.98l4-.8z"/>
</svg>';

$additionalCss =
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />';

$additionalStyles = '
    #map {
        height: 600px;
        width: 100%;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        z-index: 1;
    }
    
    .map-controls {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .alert-legend {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        margin-bottom: 0.5rem;
        padding: 0.5rem;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }
    
    .legend-item:hover {
        background-color: rgba(0,0,0,0.05);
    }
    
    .legend-color {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        border-radius: 4px;
        border: 1px solid rgba(0,0,0,0.2);
    }
    
    .boundary-control {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(0,0,0,0.1);
    }
    
    .active-alerts-list {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        max-height: 400px;
        overflow-y: auto;
    }
    
    .alert-info {
        border-left-width: 4px;
        cursor: pointer;
        transition: transform 0.2s ease;
    }
    
    .alert-info:hover {
        transform: translateX(5px);
    }
    
    .severity-extreme { border-left-color: #dc3545; }
    .severity-severe { border-left-color: #ffc107; }
    .severity-moderate { border-left-color: #17a2b8; }
';

require_once __DIR__ . "/../includes/header.php";
?>

<div class="container">
    <div class="row">
        <div class="col-md-9">
            <div id="map"></div>
        </div>
        <div class="col-md-3">
            <div class="map-controls">
                <h5 class="mb-3">Boundaries</h5>
                <div class="legend-item" onclick="toggleBoundaries('county')">
                    <div class="legend-color" style="background: rgba(108,117,125,0.1); border: 2px solid #6c757d;"></div>
                    <span>County Boundary</span>
                </div>
                <div class="legend-item" onclick="toggleBoundaries('townships')">
                    <div class="legend-color" style="background: rgba(108,117,125,0.05); border: 2px dashed #6c757d;"></div>
                    <span>Township Boundaries</span>
                </div>
                <div class="legend-item" onclick="toggleBoundaries('electric')">
                    <div class="legend-color" style="background: rgba(255,153,0,0.1); border: 2px solid #ff9900;"></div>
                    <span>Electric Providers</span>
                </div>
                <div class="legend-item" onclick="toggleBoundaries('fire')">
                    <div class="legend-color" style="background: rgba(220,53,69,0.1); border: 2px solid #dc3545;"></div>
                    <span>Fire Districts</span>
                </div>
                <div class="legend-item" onclick="toggleBoundaries('ems')">
                    <div class="legend-color" style="background: rgba(40,167,69,0.1); border: 2px solid #28a745;"></div>
                    <span>EMS Districts</span>
                </div>
                <div class="boundary-control">
                    <small class="text-muted d-block mb-2">Boundary Style:</small>
                    <select class="form-select form-select-sm" onchange="changeBoundaryStyle(this.value)">
                        <option value="solid">Solid</option>
                        <option value="dashed" selected>Dashed</option>
                        <option value="dotted">Dotted</option>
                    </select>
                </div>
            </div>
            
            <div class="alert-legend">
                <h5 class="mb-3">Alert Types</h5>
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(220,53,69,0.3); border: 2px solid #dc3545;"></div>
                    <span>Extreme Alert</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(255,193,7,0.3); border: 2px solid #ffc107;"></div>
                    <span>Severe Alert</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: rgba(23,162,184,0.3); border: 2px solid #17a2b8;"></div>
                    <span>Moderate Alert</span>
                </div>
            </div>
            
            <div class="active-alerts-list">
                <h5 class="mb-3">Active Alerts</h5>
                <?php if (empty($activeAlerts)): ?>
                    <p class="text-muted">No active alerts at this time.</p>
                <?php else: ?>
                    <?php foreach ($activeAlerts as $alert): ?>
                        <div class="alert alert-info severity-<?= strtolower(
                            $alert["severity"],
                        ) ?>" 
                             onclick="focusAlert('<?= $alert["id"] ?>')">
                            <strong><?= htmlspecialchars(
                                $alert["title"],
                            ) ?></strong><br>
                            <small class="d-block mt-1">
                                <strong>Type:</strong> <?= $alert[
                                    "polygon_type"
                                ] !== "NONE"
                                    ? "Specific Area"
                                    : "County-wide" ?><br>
                                <strong>Expires:</strong> <?= date(
                                    "g:i A",
                                    strtotime($alert["expires"]),
                                ) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Initialize map data -->
<script>
window.mapData = {
    boundaryData: <?= $boundaryData ?>,
    townshipData: <?= $townshipData ?>,
    electricData: <?= $electricData ?>,
    fireData: <?= $fireData ?>,
    emsData: <?= $emsData ?>,
    alertData: <?= $alertData ?>
};
</script>

<!-- Include Leaflet and map script -->
<?php
$additionalScripts = '
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="./js/map.js"></script>
';
require_once __DIR__ . "/../includes/footer.php";

?>
