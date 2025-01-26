<?php
// public/history.php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WeatherAlertSystem.php';

$alertSystem = new WeatherAlertSystem();

// Handle search and filtering
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? null;
$severity = $_GET['severity'] ?? null;

// Fetch alerts with optional filtering
$historicalAlerts = $alertSystem->searchAlerts($search, [
    'category' => $category,
    'severity' => $severity
]);

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="weather_alerts_history.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    $headers = ['Date', 'Event', 'Category', 'Severity', 'Status', 'Description', 'Effective', 'Expires'];
    fputcsv($output, $headers);
    
    // Write data
    foreach ($historicalAlerts as $alert) {
        fputcsv($output, [
            date('Y-m-d H:i', strtotime($alert['created_at'])),
            $alert['event_type'],
            $alert['category'],
            $alert['severity'],
            $alert['status'],
            $alert['description'],
            $alert['effective'],
            $alert['expires']
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
        default => 'bg-light'
    };
}

// Set page-specific variables
$pageTitle = 'Alert History';
$headerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" class="bi bi-clock-history me-2" viewBox="0 0 16 16">
    <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022l-.074.997zm2.004.45a7.003 7.003 0 0 0-.985-.299l.219-.976c.383.086.76.2 1.126.342l-.36.933zm1.37.71a7.01 7.01 0 0 0-.439-.27l.493-.87a8.025 8.025 0 0 1 .979.654l-.615.789a6.996 6.996 0 0 0-.418-.302zm1.834 1.79a6.99 6.99 0 0 0-.653-.796l.724-.69c.27.285.52.59.747.91l-.818.576zm.744 1.352a7.08 7.08 0 0 0-.214-.468l.893-.45a7.976 7.976 0 0 1 .45 1.088l-.95.313a7.023 7.023 0 0 0-.179-.483zm.53 2.507a6.991 6.991 0 0 0-.1-1.025l.985-.17c.067.386.106.778.116 1.17l-1 .025zm-.131 1.538c.033-.17.06-.339.081-.51l.993.123a7.957 7.957 0 0 1-.23 1.155l-.964-.267c.046-.165.086-.332.12-.501zm-.952 2.379c.184-.29.346-.594.486-.908l.914.405c-.16.36-.345.706-.555 1.038l-.845-.535zm-.964 1.205c.122-.122.239-.248.35-.378l.758.653a8.073 8.073 0 0 1-.401.432l-.707-.707z"/>
    <path d="M8 1a7 7 0 1 0 4.95 11.95l.707.707A8.001 8.001 0 1 1 8 0v1z"/>
    <path d="M7.5 3a.5.5 0 0 1 .5.5v5.21l3.248 1.856a.5.5 0 0 1-.496.868l-3.5-2A.5.5 0 0 1 7 9V3.5a.5.5 0 0 1 .5-.5z"/>
</svg>';

$headerContent = '<div class="d-flex justify-content-center align-items-center gap-3">
    <form method="GET" action="history.php" class="mb-0">
        <div class="input-group">
            <input type="search" 
                   name="search" 
                   class="form-control search-box" 
                   placeholder="Search alerts..." 
                   value="' . htmlspecialchars($search) . '">
            <select name="category" class="form-select" style="max-width: 150px;">
                <option value="">All Categories</option>
                <option value="SEVERE" ' . ($category === 'SEVERE' ? 'selected' : '') . '>Severe</option>
                <option value="WINTER" ' . ($category === 'WINTER' ? 'selected' : '') . '>Winter</option>
                <option value="FLOOD" ' . ($category === 'FLOOD' ? 'selected' : '') . '>Flood</option>
                <option value="HEAT" ' . ($category === 'HEAT' ? 'selected' : '') . '>Heat</option>
                <option value="WIND" ' . ($category === 'WIND' ? 'selected' : '') . '>Wind</option>
                <option value="OTHER" ' . ($category === 'OTHER' ? 'selected' : '') . '>Other</option>
            </select>
            <select name="severity" class="form-select" style="max-width: 150px;">
                <option value="">All Severities</option>
                <option value="Extreme" ' . ($severity === 'Extreme' ? 'selected' : '') . '>Extreme</option>
                <option value="Severe" ' . ($severity === 'Severe' ? 'selected' : '') . '>Severe</option>
                <option value="Moderate" ' . ($severity === 'Moderate' ? 'selected' : '') . '>Moderate</option>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>
    <a href="?export=csv&search=' . urlencode($search) . '&category=' . urlencode($category ?? '') . '&severity=' . urlencode($severity ?? '') . '" class="btn btn-export">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download me-2" viewBox="0 0 16 16">
            <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/>
            <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
        </svg>
        Export Filtered
    </a>
</div>';

$additionalStyles = '
    .search-box {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 25px;
        padding: 0.5rem 1rem;
        color: white;
        width: 100%;
        max-width: 400px;
        margin: 0;
    }
    
    .search-box:focus {
        outline: none;
        background: rgba(255,255,255,0.2);
        border-color: rgba(255,255,255,0.3);
        color: white;
    }
    
    .search-box::placeholder {
        color: rgba(255,255,255,0.6);
    }
    
    .table-container {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        padding: 1rem;
        margin-bottom: 2rem;
    }
    
    .btn-export {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.2);
        color: white;
        border-radius: 25px;
        padding: 0.5rem 1.5rem;
        transition: all 0.3s ease;
    }
    
    .btn-export:hover {
        background: rgba(255,255,255,0.2);
        border-color: rgba(255,255,255,0.3);
        color: white;
        text-decoration: none;
    }
';

// Include header
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="table-container">
        <?php if (empty($historicalAlerts)): ?>
            <div class="text-center p-4">
                <h4>No Alerts Found</h4>
                <?php if ($search || $category || $severity): ?>
                    <p class="text-muted">No alerts match your search criteria. Try different filters.</p>
                    <a href="history.php" class="btn btn-primary">Clear Filters</a>
                <?php else: ?>
                    <p class="text-muted">No historical alerts are available.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
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
                    <?php foreach ($historicalAlerts as $alert): ?>
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
                                <button type="button" class="btn btn-sm btn-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#alert-<?= $alert['id'] ?>">
                                    View Details
                                </button>

                                <!-- Modal -->
                                <div class="modal fade" id="alert-<?= $alert['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title"><?= htmlspecialchars($alert['title']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
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
                                                        <a href="map.php" class="btn btn-sm btn-outline-primary">
                                                            View on Map
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>