<?php
// public/stats.php

// Include bootstrap first
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_PATH . '/includes/WeatherAlertSystem.php';

$alertSystem = new WeatherAlertSystem();
$stats = $alertSystem->getAlertStatistics();

// Set page-specific variables for header
$pageTitle = 'Alert Statistics';
$headerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" class="bi bi-graph-up" viewBox="0 0 16 16">
    <path fill-rule="evenodd" d="M0 0h1v15h15v1H0V0Zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07Z"/>
</svg>';

$additionalCss = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

$additionalStyles = '
    .stats-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        height: 100%;
    }
    
    .chart-container {
        position: relative;
        min-height: 300px;
        margin-bottom: 1rem;
    }
    
    .stats-value {
        font-size: 2rem;
        font-weight: bold;
        color: #1e3c72;
    }
    
    .stats-label {
        color: #666;
        font-size: 0.9rem;
    }
    
    .quick-stat {
        padding: 1rem;
        border-radius: 8px;
        background: rgba(30, 60, 114, 0.05);
        margin-bottom: 1rem;
    }
';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="row">
        <!-- Quick Stats -->
        <div class="col-md-3">
            <div class="stats-card">
                <h5>Quick Statistics</h5>
                <div class="quick-stat">
                    <div class="stats-value"><?= $stats['basic']['total_alerts'] ?? 0 ?></div>
                    <div class="stats-label">Total Alerts</div>
                </div>
                <div class="quick-stat">
                    <div class="stats-value"><?= $stats['basic']['unique_event_types'] ?? 0 ?></div>
                    <div class="stats-label">Unique Event Types</div>
                </div>
                <div class="quick-stat">
                    <div class="stats-value"><?= round($stats['basic']['avg_duration'] ?? 0, 1) ?></div>
                    <div class="stats-label">Average Duration (hours)</div>
                </div>
            </div>
        </div>

        <!-- Alert Types Distribution -->
        <div class="col-md-9">
            <div class="stats-card">
                <h5>Alert Categories Distribution</h5>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Severity Distribution -->
        <div class="col-md-6">
            <div class="stats-card">
                <h5>Severity Distribution</h5>
                <div class="chart-container">
                    <canvas id="severityChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Time of Day Distribution -->
        <div class="col-md-6">
            <div class="stats-card">
                <h5>Alerts by Time of Day</h5>
                <div class="chart-container">
                    <canvas id="timeDistributionChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$additionalScripts = '
<script>
    // Initialize Chart.js with defaults
    Chart.defaults.font.family = \'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif\';
    
    // Convert PHP data to JavaScript
    const stats = ' . json_encode($stats) . ';
    
    // Category Distribution Chart
    new Chart(document.getElementById("categoryChart"), {
        type: "bar",
        data: {
            labels: Object.keys(stats.categories),
            datasets: [{
                label: "Number of Alerts",
                data: Object.values(stats.categories),
                backgroundColor: [
                    "rgba(255, 99, 132, 0.7)",
                    "rgba(54, 162, 235, 0.7)",
                    "rgba(255, 206, 86, 0.7)",
                    "rgba(75, 192, 192, 0.7)",
                    "rgba(153, 102, 255, 0.7)"
                ],
                borderColor: [
                    "rgb(255, 99, 132)",
                    "rgb(54, 162, 235)",
                    "rgb(255, 206, 86)",
                    "rgb(75, 192, 192)",
                    "rgb(153, 102, 255)"
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    // Time Distribution Chart
    new Chart(document.getElementById("timeDistributionChart"), {
        type: "line",
        data: {
            labels: stats.hourly_distribution.map(item => `${item.hour}:00`),
            datasets: [{
                label: "Alerts by Hour",
                data: stats.hourly_distribution.map(item => item.count),
                borderColor: "rgb(54, 162, 235)",
                backgroundColor: "rgba(54, 162, 235, 0.1)",
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: "top"
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    // Severity Distribution Chart
    const severityData = stats.hourly_distribution.reduce((acc, curr) => {
        acc.extreme = (acc.extreme || 0) + (curr.extreme || 0);
        acc.severe = (acc.severe || 0) + (curr.severe || 0);
        acc.moderate = (acc.moderate || 0) + (curr.moderate || 0);
        return acc;
    }, {});

    new Chart(document.getElementById("severityChart"), {
        type: "doughnut",
        data: {
            labels: ["Extreme", "Severe", "Moderate"],
            datasets: [{
                data: [
                    severityData.extreme || 0,
                    severityData.severe || 0,
                    severityData.moderate || 0
                ],
                backgroundColor: [
                    "rgba(220, 53, 69, 0.7)",
                    "rgba(255, 193, 7, 0.7)",
                    "rgba(23, 162, 184, 0.7)"
                ],
                borderColor: [
                    "rgb(220, 53, 69)",
                    "rgb(255, 193, 7)",
                    "rgb(23, 162, 184)"
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: "top"
                }
            }
        }
    });
</script>';

require_once __DIR__ . '/../includes/footer.php';
?>