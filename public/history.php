<?php
/**
 * Weather Alert System - History Page
 * Last Modified: 2025-01-25
 * Modified By: KR8MER
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WeatherAlertSystem.php';

// Initialize the alert system
$alertSystem = new WeatherAlertSystem();

// Get filter parameters with defaults
$filters = [
    'search' => $_GET['search'] ?? '',
    'category' => $_GET['category'] ?? 'all',
    'severity' => $_GET['severity'] ?? 'all',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'status' => $_GET['status'] ?? 'all',
    'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 100
];

// Debug logging
error_log("Filters applied: " . json_encode($filters));

// Get historical alerts with filters
$historicalAlerts = $alertSystem->searchAlerts($filters['search'], $filters);

// Process alerts to ensure all required fields
$processedAlerts = array_map(function($alert) {
    return [
        'id' => $alert['id'],
        'title' => $alert['title'] ?? 'Untitled Alert',
        'event_type' => $alert['event_type'] ?? 'Unknown',
        'severity' => $alert['severity'] ?? 'Unknown',
        'status' => $alert['status'] ?? 'Unknown',
        'description' => $alert['description'] ?? 'No description available',
        'created_at' => $alert['created_at'] ?? date('Y-m-d H:i:s'),
        'effective' => $alert['effective'] ?? date('Y-m-d H:i:s'),
        'expires' => $alert['expires'] ?? date('Y-m-d H:i:s'),
        'first_seen' => $alert['created_at'] ?? $alert['effective'] ?? date('Y-m-d H:i:s'),
        'polygon' => $alert['polygon'] ?? null,
        'polygon_type' => $alert['polygon_type'] ?? 'NONE',
        'category' => determineCategory($alert['event_type'] ?? '')
    ];
}, $historicalAlerts);

// Helper function to determine category
function determineCategory($eventType) {
    $categories = [
        'SEVERE' => ['Tornado Warning', 'Severe Thunderstorm Warning', 'Flash Flood Warning'],
        'WINTER' => ['Winter Storm Warning', 'Ice Storm Warning', 'Blizzard Warning', 'Winter Weather Advisory'],
        'FLOOD' => ['Flood Warning', 'Flood Watch', 'Flood Advisory'],
        'HEAT' => ['Excessive Heat Warning', 'Heat Advisory'],
        'WIND' => ['High Wind Warning', 'Wind Advisory']
    ];

    foreach ($categories as $category => $events) {
        if (in_array($eventType, $events)) {
            return $category;
        }
    }
    
    return 'OTHER';
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="weather_alerts_history_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write CSV headers
    fputcsv($output, [
        'Date',
        'Event Type',
        'Category',
        'Severity',
        'Status',
        'Description',
        'Effective',
        'Expires'
    ]);
    
    // Write alert data
    foreach ($processedAlerts as $alert) {
        fputcsv($output, [
            date('Y-m-d H:i', strtotime($alert['created_at'])),
            $alert['event_type'],
            $alert['category'],
            $alert['severity'],
            $alert['status'],
            $alert['description'],
            date('Y-m-d H:i', strtotime($alert['effective'])),
            date('Y-m-d H:i', strtotime($alert['expires']))
        ]);
    }
    
    fclose($output);
    exit;
}

// Helper function for severity-based styling
function getSeverityClass($severity) {
    return match(strtolower($severity)) {
        'extreme' => 'bg-danger text-white',
        'severe' => 'bg-warning',
        'moderate' => 'bg-info text-white',
        'minor' => 'bg-light',
        default => 'bg-secondary text-white'
    };
}

// Page-specific variables
$pageTitle = 'Alert History';
$additionalStyles = file_get_contents(__DIR__ . '/../assets/css/history.css');

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Search and Filter Form -->
    <div class="row mb-4">
        <div class="col-12">
            <form method="GET" action="history.php" class="alert-filters">
                <div class="input-group">
                    <input type="search" 
                           name="search" 
                           class="form-control" 
                           placeholder="Search alerts..." 
                           value="<?= htmlspecialchars($filters['search']) ?>">
                           
                    <select name="category" class="form-select">
                        <option value="all">All Categories</option>
                        <?php
                        $categories = ['SEVERE', 'WINTER', 'FLOOD', 'HEAT', 'WIND', 'OTHER'];
                        foreach ($categories as $cat) {
                            $selected = $filters['category'] === $cat ? 'selected' : '';
                            echo "<option value=\"$cat\" $selected>$cat</option>";
                        }
                        ?>
                    </select>
                    
                    <select name="severity" class="form-select">
                        <option value="all">All Severities</option>
                        <?php
                        $severities = ['Extreme', 'Severe', 'Moderate', 'Minor'];
                        foreach ($severities as $sev) {
                            $selected = $filters['severity'] === $sev ? 'selected' : '';
                            echo "<option value=\"$sev\" $selected>$sev</option>";
                        }
                        ?>
                    </select>
                    
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    
                    <?php if (!empty($filters['search']) || $filters['category'] !== 'all' || $filters['severity'] !== 'all'): ?>
                        <a href="history.php" class="btn btn-secondary">Clear Filters</a>
                    <?php endif; ?>
                    
                    <a href="?<?= http_build_query(array_merge($filters, ['export' => 'csv'])) ?>" 
                       class="btn btn-success">
                        Export to CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Alerts Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <?php if (empty($processedAlerts)): ?>
                        <div class="text-center p-4">
                            <h4>No Alerts Found</h4>
                            <p class="text-muted">
                                <?php if (!empty($filters['search']) || $filters['category'] !== 'all' || $filters['severity'] !== 'all'): ?>
                                    No alerts match your search criteria. Try different filters.
                                <?php else: ?>
                                    No historical alerts are available.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Event</th>
                                        <th>Category</th>
                                        <th>Severity</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($processedAlerts as $alert): ?>
                                        <tr>
                                            <td><?= date('Y-m-d H:i', strtotime($alert['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($alert['event_type']) ?></td>
                                            <td><?= htmlspecialchars($alert['category']) ?></td>
                                            <td>
                                                <span class="badge <?= getSeverityClass($alert['severity']) ?>">
                                                    <?= htmlspecialchars($alert['severity']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($alert['status']) ?></td>
                                            <td>
                                                <button type="button" 
                                                        class="btn btn-sm btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#alert-<?= $alert['id'] ?>">
                                                    View Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alert Detail Modals -->
<?php foreach ($processedAlerts as $alert): ?>
    <div class="modal fade" id="alert-<?= $alert['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= htmlspecialchars($alert['title']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert-details">
                        <?= nl2br(htmlspecialchars($alert['description'])) ?>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>First Seen:</strong> 
                                <?= date('F j, Y g:i A', strtotime($alert['first_seen'])) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Expires:</strong> 
                                <?= date('F j, Y g:i A', strtotime($alert['expires'])) ?>
                            </div>
                        </div>
                        <?php if ($alert['polygon_type'] !== 'NONE'): ?>
                            <div class="mt-3">
                                <a href="map.php?alert=<?= $alert['id'] ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    View on Map
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>