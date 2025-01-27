<?php
// public/stats.php

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WeatherAlertSystem.php';
require_once __DIR__ . '/../includes/WeatherAlertIcons.php';

$alertSystem = new WeatherAlertSystem();

// Get statistics from the database
$stats = [
    'total_alerts' => $alertSystem->getTotalAlertCount(),
    'alerts_by_severity' => $alertSystem->getAlertsBySeverity(),
    'alerts_by_type' => $alertSystem->getAlertsByType(),
    'alerts_by_month' => $alertSystem->getAlertsByMonth(),
    'avg_duration' => $alertSystem->getAverageAlertDuration(),
    'alerts_last_30_days' => $alertSystem->getAlertCountLastNDays(30),
    'active_alerts' => $alertSystem->getActiveAlertCount()
];

// Set page-specific variables for header
$pageTitle = 'Alert Statistics';
$headerIcon = WeatherAlertIcons::generateIconHTML('statistics', 'moderate');

$headerContent = '<div class="text-center mb-4">
    <h4 class="text-white mb-2">System Statistics</h4>
    <p class="text-white-50">Statistical analysis of weather alerts</p>
</div>';

$additionalStyles = '
    .stats-card {
        border: none;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: transform 0.2s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
    }
    
    .stats-number {
        font-size: 2.5rem;
        font-weight: bold;
        color: #1e3c72;
    }
    
    .stats-label {
        color: #6c757d;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .chart-container {
        position: relative;
        height: 300px;
        margin-bottom: 2rem;
    }
';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <!-- Overview Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <div class="stats-number"><?= number_format($stats['total_alerts']) ?></div>
                    <div class="stats-label">Total Alerts</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <div class="stats-number"><?= number_format($stats['active_alerts']) ?></div>
                    <div class="stats-label">Active Alerts</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <div class="stats-number"><?= number_format($stats['alerts_last_30_days']) ?></div>
                    <div class="stats-label">Last 30 Days</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stats-card">
                <div class="card-body text-center">
                    <div class="stats-number"><?= round($stats['avg_duration']) ?></div>
                    <div class="stats-label">Avg Duration (hrs)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row">
        <!-- Severity Distribution -->
        <div class="col-md-6 mb-4">
            <div class="card stats-card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Alerts by Severity</h5>
                </div>
                <div class="card-body">
                    <canvas id="severityChart" class="chart-container"></canvas>
                </div>
            </div>
        </div>

        <!-- Monthly Distribution -->
        <div class="col-md-6 mb-4">
            <div class="card stats-card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Alerts by Month</h5>
                </div>
                <div class="card-body">
                    <canvas id="monthlyChart" class="chart-container"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Types -->
    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card stats-card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Alert Types Distribution</h5>
                </div>
                <div class="card-body">
                    <canvas id="typeChart" class="chart-container"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Helper function to create charts
function createChart(elementId, type, labels, data, options = {}) {
    const ctx = document.getElementById(elementId).getContext('2d');
    return new Chart(ctx, {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: [
                    '#dc3545', // danger
                    '#ffc107', // warning
                    '#17a2b8', // info
                    '#6c757d', // secondary
                    '#28a745', // success
                    '#007bff'  // primary
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            ...options
        }
    });
}

// Create charts when document is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Severity Chart
    createChart(
        'severityChart',
        'doughnut',
        <?= json_encode(array_keys($stats['alerts_by_severity'])) ?>,
        <?= json_encode(array_values($stats['alerts_by_severity'])) ?>,
        {
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    );

    // Monthly Chart
    createChart(
        'monthlyChart',
        'bar',
        <?= json_encode(array_keys($stats['alerts_by_month'])) ?>,
        <?= json_encode(array_values($stats['alerts_by_month'])) ?>,
        {
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    );

    // Type Chart
    createChart(
        'typeChart',
        'pie',
        <?= json_encode(array_keys($stats['alerts_by_type'])) ?>,
        <?= json_encode(array_values($stats['alerts_by_type'])) ?>,
        {
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    );
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>