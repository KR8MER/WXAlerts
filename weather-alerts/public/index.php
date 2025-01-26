<?php
// public/index.php

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Fix include paths
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WeatherAlertSystem.php';

$alertSystem = new WeatherAlertSystem();
$activeAlerts = $alertSystem->getActiveAlerts();

// Helper function for severity-based styling
function getSeverityClass($severity) {
    return match(strtolower($severity)) {
        'extreme' => 'bg-danger text-white',
        'severe' => 'bg-warning',
        'moderate' => 'bg-info text-white',
        default => 'bg-light'
    };
}

// Helper function to format districts
function formatDistricts($districts) {
    $output = '';
    foreach (['fire', 'ems', 'electric'] as $type) {
        if (!empty($districts[$type])) {
            $output .= '<div class="district-group mb-2">';
            $output .= '<strong class="text-capitalize">' . $type . ':</strong> ';
            $output .= implode(', ', array_map('htmlspecialchars', $districts[$type]));
            $output .= '</div>';
        }
    }
    return $output;
}

// Set page-specific variables for header
$pageTitle = 'Putnam County Weather Alerts';
$headerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" class="bi bi-cloud-lightning-fill me-2" viewBox="0 0 16 16">
    <path d="M7.053 11.276A.5.5 0 0 1 7.5 11h1a.5.5 0 0 1 .474.658l-.28.842H9.5a.5.5 0 0 1 .39.812l-2 2.5a.5.5 0 0 1-.875-.433L7.36 14H6.5a.5.5 0 0 1-.447-.724l1-2z"/>
    <path d="M13.405 4.027a5.001 5.001 0 0 0-9.499-1.004A3.5 3.5 0 1 0 3.5 10H13a3 3 0 0 0 .405-5.973z"/>
</svg>';

$headerContent = '<div class="alert-count">
    ' . count($activeAlerts) . '
</div>
<div class="alert-count-text">
    Active Alert' . (count($activeAlerts) !== 1 ? 's' : '') . '
</div>
<div class="last-updated">
    Last updated: ' . date('F j, Y g:i A') . '
</div>';

$additionalStyles = '
    .alert-count {
        font-size: 3rem;
        font-weight: bold;
        text-align: center;
        color: #ff5722;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        margin: 1.5rem 0;
    }
    
    .alert-count-text {
        font-size: 1.2rem;
        color: rgba(255,255,255,0.9);
        text-align: center;
        margin-bottom: 2rem;
    }
    
    .alert-card {
        border: none;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: transform 0.2s ease;
    }
    
    .alert-card:hover {
        transform: translateY(-5px);
    }
    
    .alert-card .card-header {
        border-radius: 10px 10px 0 0;
        font-weight: bold;
        padding: 1rem;
    }
    
    .status-badge {
        padding: 0.35em 0.65em;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
    }
    
    .last-updated {
        font-size: 0.9em;
        color: rgba(255,255,255,0.8);
        text-align: center;
        margin-top: 1rem;
    }
    
    .info-section {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .badge {
        padding: 0.5em 1em;
        border-radius: 20px;
        font-weight: 500;
    }

    .districts-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
        border: 1px solid #dee2e6;
    }

    .district-group {
        padding: 0.5rem;
        border-bottom: 1px solid #dee2e6;
    }

    .district-group:last-child {
        border-bottom: none;
    }
';

// Include header with correct path
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <?php if (empty($activeAlerts)): ?>
        <div class="info-section text-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="#28a745" class="bi bi-check-circle-fill mb-3" viewBox="0 0 16 16">
                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
            </svg>
            <h4 class="text-success">No Active Alerts!</h4>
            <p class="text-muted">No weather alerts for Putnam County at this time.</p>
        </div>
    <?php else: ?>
        <?php foreach ($activeAlerts as $alert): ?>
            <div class="card alert-card">
                <div class="card-header <?= getSeverityClass($alert['severity']) ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <h5 class="card-title mb-0"><?= htmlspecialchars($alert['title']); ?></h5>
                        </div>
                        <span class="badge bg-light text-dark">
                            Active Alert
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <strong>Severity:</strong> 
                            <span class="badge <?= getSeverityClass($alert['severity']) ?>">
                                <?= htmlspecialchars($alert['severity']); ?>
                            </span>
                        </div>
                        <div class="col-md-4">
                            <strong>Event Type:</strong> 
                            <span class="badge bg-secondary">
                                <?= htmlspecialchars($alert['event_type']); ?>
                            </span>
                        </div>
                        <div class="col-md-4">
                            <strong>Urgency:</strong> 
                            <span class="badge bg-info text-white">
                                <?= htmlspecialchars($alert['urgency']); ?>
                            </span>
                        </div>
                    </div>
                    <p class="card-text"><?= nl2br(htmlspecialchars($alert['description'])); ?></p>
                    
                    <?php if (!empty($alert['districts'])): ?>
                        <div class="districts-section">
                            <h6 class="mb-3">Affected Districts:</h6>
                            <?= formatDistricts($alert['districts']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-muted mt-3">
                        <strong>Effective:</strong> <?= date('F j, Y g:i A', strtotime($alert['effective'])); ?><br>
                        <strong>Expires:</strong> <?= date('F j, Y g:i A', strtotime($alert['expires'])); ?>
                    </div>
                    <?php if ($alert['polygon_type'] !== 'NONE'): ?>
                        <div class="mt-2">
                            <a href="map.php" class="btn btn-sm btn-outline-primary">View on Map</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>