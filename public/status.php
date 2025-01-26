<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/WeatherAlertSystem.php';
require_once __DIR__ . '/../includes/LogParser.php';

$alertSystem = new WeatherAlertSystem();
$activeAlerts = $alertSystem->getActiveAlerts();

// Get alert statistics
$alertStats = $alertSystem->getAlertStats();

// Get district coverage
$coverage = $alertSystem->getDistrictCoverage();

// Get recent logs
$logParser = new LogParser(__DIR__ . '/../logs/cron.log', 20);
$recentLogs = $logParser->getRecentLogs();

// Get last cron run
function getLastCronRun() {
    $logFile = __DIR__ . '/../logs/cron.log';
    if (!file_exists($logFile)) {
        return ['time' => 'No recent updates', 'status' => 'Unknown'];
    }
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);
    
    $lastRunTime = null;
    $lastStatus = null;
    
    foreach ($lines as $line) {
        if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})[^\]]*\]/', $line, $timeMatches)) {
            $lastRunTime = $timeMatches[1];
        }
        
        if (preg_match('/Status: (.+)/', $line, $statusMatches)) {
            $lastStatus = $statusMatches[1];
        }
        
        if ($lastRunTime && $lastStatus) {
            return [
                'time' => $lastRunTime,
                'status' => $lastStatus
            ];
        }
    }
    
    return ['time' => 'No recent updates', 'status' => 'Unknown'];
}
function getDiskSpace() {
    $path = __DIR__;
    return [
        'free' => disk_free_space($path),
        'total' => disk_total_space($path),
        'used_percent' => round((1 - (disk_free_space($path) / disk_total_space($path))) * 100, 1)
    ];
}

function getSystemMemoryUsage() {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        return $load[0];
    }
    return null;
}

function getHealthStatus($metric, $warning = 80, $critical = 90) {
    if ($metric >= $critical) return 'danger';
    if ($metric >= $warning) return 'warning';
    return 'success';
}

$lastRun = getLastCronRun();
$diskSpace = getDiskSpace();
$systemLoad = getSystemMemoryUsage();

// Test database connection
try {
    $dbStatus = $alertSystem->testConnection();
    $dbConnected = $dbStatus['success'];
    $dbMessage = $dbStatus['message'];
} catch (Exception $e) {
    $dbConnected = false;
    $dbMessage = $e->getMessage();
}

// Set page variables
$pageTitle = 'System Status';
$headerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="currentColor" class="bi bi-speedometer me-2" viewBox="0 0 16 16">
    <path d="M8 2a.5.5 0 0 1 .5.5V4a.5.5 0 0 1-1 0V2.5A.5.5 0 0 1 8 2M3.732 3.732a.5.5 0 0 1 .707 0l.915.914a.5.5 0 1 1-.708.708l-.914-.915a.5.5 0 0 1 0-.707M2 8a.5.5 0 0 1 .5-.5h1.586a.5.5 0 0 1 0 1H2.5A.5.5 0 0 1 2 8m9.5 0a.5.5 0 0 1 .5-.5h1.5a.5.5 0 0 1 0 1H12a.5.5 0 0 1-.5-.5m.754-4.246a.39.39 0 0 0-.527-.02L7.547 7.31A.91.91 0 1 0 8.85 8.569l3.434-4.297a.39.39 0 0 0-.029-.518z"/>
    <path fill-rule="evenodd" d="M6.664 15.889A8 8 0 1 1 9.336.11a8 8 0 0 1-2.672 15.78zm-4.665-4.283A11.95 11.95 0 0 1 8 10c2.186 0 4.236.585 6.001 1.606a7 7 0 1 0-12.002 0z"/>
</svg>';

$additionalStyles = '
    .metric-box {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .metric-value {
        font-size: 1.5rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
    }
    
    .metric-label {
        color: #6c757d;
        margin-bottom: 0;
    }
    
    .progress {
        height: 8px;
        margin-top: 0.5rem;
    }
    
    .status-card {
        background: white;
        border-radius: 10px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .log-table {
        font-size: 0.875rem;
    }

    .log-table td {
        vertical-align: middle;
    }
    
    .badge {
        padding: 0.5em 0.8em;
    }
';

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <!-- System Health Row -->
    <div class="row">
        <div class="col-md-6">
            <div class="status-card">
                <h5>Last Cron Run</h5>
                <div class="metric-box">
                    <p class="metric-value"><?= htmlspecialchars($lastRun['time']); ?></p>
                    <p class="metric-label">Status: <?= htmlspecialchars($lastRun['status']); ?></p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="status-card">
                <h5>Disk Usage</h5>
                <div class="metric-box">
                    <p class="metric-value"><?= round($diskSpace['free'] / 1024 / 1024 / 1024, 2); ?> GB free of <?= round($diskSpace['total'] / 1024 / 1024 / 1024, 2); ?> GB</p>
                    <div class="progress">
                        <div class="progress-bar bg-<?= getHealthStatus($diskSpace['used_percent']); ?>" 
                             style="width: <?= $diskSpace['used_percent']; ?>%;"
                             title="<?= $diskSpace['used_percent']; ?>% used"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Statistics Row -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="status-card">
                <h5>Alert System Status</h5>
                <div class="row">
                    <div class="col-md-3">
                        <div class="metric-box">
                            <p class="metric-label">Active Alerts</p>
                            <p class="metric-value"><?= $alertStats['active']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-box">
                            <p class="metric-label">Alerts (Last 24h)</p>
                            <p class="metric-value"><?= $alertStats['last24h']['total_alerts']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-box">
                            <p class="metric-label">Extreme Alerts (24h)</p>
                            <p class="metric-value">
                                <span class="<?= $alertStats['last24h']['extreme_alerts'] > 0 ? 'text-danger' : ''; ?>">
                                    <?= $alertStats['last24h']['extreme_alerts']; ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-box">
                            <p class="metric-label">Alert Types (24h)</p>
                            <p class="metric-value"><?= $alertStats['last24h']['unique_types']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<!-- Active Alerts Detail Row -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="status-card">
                <h5>Active Alert Details</h5>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Alert</th>
                                <th>Coverage</th>
                                <th>Fire District(s)</th>
                                <th>EMS District(s)</th>
                                <th>Electric Provider(s)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activeAlerts)): ?>
                            <tr>
                                <td colspan="5" class="text-center">No active alerts</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($activeAlerts as $alert): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($alert['title']) ?>
                                        <span class="badge bg-<?= strtolower($alert['severity']) === 'extreme' ? 'danger' : 
                                            (strtolower($alert['severity']) === 'severe' ? 'warning' : 'info') ?>">
                                            <?= htmlspecialchars($alert['severity']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($alert['coverage']) ?></td>
                                    <td><?= htmlspecialchars(implode(', ', $alert['districts']['fire'])) ?></td>
                                    <td><?= htmlspecialchars(implode(', ', $alert['districts']['ems'])) ?></td>
                                    <td><?= htmlspecialchars(implode(', ', $alert['districts']['electric'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- District Coverage Row -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="status-card">
                <h5>District Coverage</h5>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>District Type</th>
                                <th>Count</th>
                                <th>Total Area</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Fire Districts</td>
                                <td><?= $coverage['fire']['count']; ?></td>
                                <td><?= number_format($coverage['fire']['area'], 2); ?> sq mi</td>
                                <td>
                                    <span class="badge bg-<?= $coverage['fire']['count'] > 0 ? 'success' : 'danger'; ?>">
                                        <?= $coverage['fire']['count'] > 0 ? 'Active' : 'Check Coverage'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>EMS Districts</td>
                                <td><?= $coverage['ems']['count']; ?></td>
                                <td><?= number_format($coverage['ems']['area'], 2); ?> sq mi</td>
                                <td>
                                    <span class="badge bg-<?= $coverage['ems']['count'] > 0 ? 'success' : 'danger'; ?>">
                                        <?= $coverage['ems']['count'] > 0 ? 'Active' : 'Check Coverage'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Electric Providers</td>
                                <td><?= $coverage['electric']['count']; ?></td>
                                <td><?= number_format($coverage['electric']['area'], 2); ?> sq mi</td>
                                <td>
                                    <span class="badge bg-<?= $coverage['electric']['count'] > 0 ? 'success' : 'danger'; ?>">
                                        <?= $coverage['electric']['count'] > 0 ? 'Active' : 'Check Coverage'; ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<!-- System Logs Row -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="status-card">
                <h5>Recent System Events</h5>
                <div class="table-responsive">
                    <table class="table table-sm log-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?= date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                                <td>
                                    <span class="badge bg-<?= $log['level'] === 'ERROR' ? 'danger' : 
                                        ($log['level'] === 'WARNING' ? 'warning' : 'info'); ?>">
                                        <?= htmlspecialchars($log['level']); ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($log['message']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>