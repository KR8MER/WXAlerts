<?php
/**
 * Weather Alert System - Map View
 * Current Date: 2025-01-26 01:55:59 UTC
 * Modified By: KR8MER
 */

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . "/../includes/WeatherAlertSystem.php";

$alertSystem = new WeatherAlertSystem();

// Handle historical alert viewing
$historicalAlertId = $_GET['alert'] ?? null;
$isHistorical = isset($_GET['historical']) && $_GET['historical'] === 'true';

if ($isHistorical && $historicalAlertId) {
    $historicalAlert = $alertSystem->getAlert($historicalAlertId);
    $activeAlerts = $historicalAlert ? [$historicalAlert] : [];
} else {
    $activeAlerts = $alertSystem->getActiveAlerts();
}

// Fetch boundary data from the database
$townshipData = json_encode($alertSystem->getBoundaryData('township'));
$villageData = json_encode($alertSystem->getBoundaryData('city'));
$electricData = json_encode($alertSystem->getBoundaryData('electric'));
$fireData = json_encode($alertSystem->getBoundaryData('fire'));
$emsData = json_encode($alertSystem->getBoundaryData('ems'));
$schoolData = json_encode($alertSystem->getBoundaryData('school'));
$telephoneData = json_encode($alertSystem->getBoundaryData('telephone'));

// Debug section to verify GeoJSON data
echo "<!-- Debug GeoJSON Data -->\n";
echo "<script>\n";
echo "console.log('City Data:', " . $villageData . ");\n";
echo "console.log('Township Data:', " . $townshipData . ");\n";
echo "</script>\n";

// Convert alerts to GeoJSON
$alertData = json_encode($alertSystem->alertsToGeoJSON($activeAlerts));

// Set page variables
$pageTitle = $isHistorical ? "Historical Alert Map" : "Alert Map";
$additionalCss = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />';

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
    
    .legend-item.active {
        background-color: rgba(0,0,0,0.1);
    }
    
    .legend-color {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        border-radius: 4px;
        border: 1px solid rgba(0,0,0,0.2);
    }

    .districts-panel {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        max-height: 400px;
        overflow-y: auto;
    }

    .district-summary {
        font-size: 0.9rem;
        padding: 0.5rem;
        background: #f8f9fa;
        border-radius: 4px;
    }

    .all-districts-summary {
        font-size: 0.85rem;
        line-height: 1.4;
    }

    .all-districts-summary strong {
        font-size: 0.9rem;
    }

    .table td {
        vertical-align: middle;
    }

    .badge {
        font-weight: 500;
    }

    .table-light {
        background-color: #f8f9fa;
    }
';

require_once __DIR__ . "/../includes/header.php";
?>

<?php if ($isHistorical): ?>
<div class="container mt-3 mb-3">
    <a href="history.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to History
    </a>
</div>
<?php endif; ?>

<div class="container">
    <div class="row">
        <div class="col-md-9">
            <div id="map"></div>
        </div>
        <div class="col-md-3">
            <div class="map-controls">
                <h5 class="mb-3">Boundaries</h5>
                <div class="legend-item active" onclick="toggleBoundaries('townships')">
                    <div class="legend-color" style="background: rgba(108,117,125,0.05); border: 2px dashed #6c757d;"></div>
                    <span>Township Boundaries</span>
                </div>
                <div class="legend-item active" onclick="toggleBoundaries('villages')">
                    <div class="legend-color" style="background: rgba(0,0,0,0.1); border: 2px solid #000;"></div>
                    <span>Cities</span>
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
                <div class="legend-item" onclick="toggleBoundaries('school')">
                    <div class="legend-color" style="background: rgba(0,123,255,0.1); border: 2px solid #007bff;"></div>
                    <span>School Districts</span>
                </div>
                <div class="legend-item" onclick="toggleBoundaries('telephone')">
                    <div class="legend-color" style="background: rgba(111,66,193,0.1); border: 2px solid #6f42c1;"></div>
                    <span>Telephone Districts</span>
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

            <div class="districts-panel">
                <h5 class="mb-3">Affected Districts</h5>
                <div id="districtsTable">
                    <!-- Districts table will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="active-alerts-list">
                <h5 class="mb-3"><?= $isHistorical ? 'Historical Alert' : 'Active Alerts' ?></h5>
                <?php if (empty($activeAlerts)): ?>
                    <p class="text-muted">No <?= $isHistorical ? 'historical' : 'active' ?> alerts at this time.</p>
                <?php else: ?>
                    <?php foreach ($activeAlerts as $alert): ?>
                        <div class="alert alert-info severity-<?= strtolower($alert["severity"]) ?>" 
                             onclick="focusAlert('<?= $alert["id"] ?>')">
                            <strong><?= htmlspecialchars($alert["title"]) ?></strong><br>
                            <small class="d-block mt-1">
                                <strong>Type:</strong> <?= $alert["polygon_type"] !== "NONE" ? "Specific Area" : "County-wide" ?><br>
                                <strong>Expires:</strong> <?= date("g:i A", strtotime($alert["expires"])) ?>
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
    townshipData: <?= $townshipData ?>,
    villageData: <?= $villageData ?>,
    electricData: <?= $electricData ?>,
    fireData: <?= $fireData ?>,
    emsData: <?= $emsData ?>,
    schoolData: <?= $schoolData ?>,
    telephoneData: <?= $telephoneData ?>,
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