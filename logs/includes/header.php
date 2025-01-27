<?php
// includes/header.php

// Determine current page for active nav highlighting
$currentPage = basename($_SERVER['PHP_SELF']);

// Ensure WeatherAlertIcons is loaded if not already
if (!class_exists('WeatherAlertIcons')) {
    require_once __DIR__ . '/WeatherAlertIcons.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Weather Alerts' ?> - Putnam County</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if (isset($additionalCss)) echo $additionalCss; ?>
    
    <style>
        body {
            background-color: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .header-section {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .nav-pills .nav-link {
            color: white;
            margin: 0 0.5rem;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .nav-pills .nav-link.active {
            background-color: #ff5722;
            box-shadow: 0 4px 8px rgba(255,87,34,0.3);
        }

        .main-content {
            flex: 1 0 auto;
        }
        
        .footer {
            flex-shrink: 0;
            margin-top: auto;
        }

        /* Weather Icon Styles */
        .weather-icon-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            margin-right: 8px;
            background: rgba(255,255,255,0.1);
        }

        .weather-icon {
            width: 24px;
            height: 24px;
        }

        .extreme-severity .weather-icon {
            color: #dc3545;
        }

        .severe-severity .weather-icon {
            color: #ffc107;
        }

        .moderate-severity .weather-icon {
            color: #17a2b8;
        }

        .minor-severity .weather-icon {
            color: #6c757d;
        }

        .default-severity .weather-icon {
            color: #212529;
        }

        /* Card header with icon alignment */
        .card-header .d-flex.align-items-center .weather-icon-wrapper {
            margin-right: 12px;
        }

        /* Make icons more visible on dark backgrounds */
        .bg-danger .weather-icon,
        .bg-warning .weather-icon,
        .bg-info .weather-icon {
            filter: drop-shadow(1px 1px 1px rgba(0,0,0,0.2));
        }
        
        <?php if (isset($additionalStyles)) echo $additionalStyles; ?>
    </style>
<!-- Load Weather Icons SVG Sprite -->
<div style="display: none">
    <?php 
    $svgPath = __DIR__ . '/../assets/weather-icons.svg';
    if (file_exists($svgPath)) {
        echo file_get_contents($svgPath);
    } else {
        error_log("Weather icons SVG file not found at: $svgPath");
    }
    ?>
</div>
</head>
<body>
    <div class="header-section">
        <div class="container">
            <h1 class="text-center mb-4">
                <?php if (isset($headerIcon)) echo $headerIcon; ?>
                <?= $pageTitle ?? 'Putnam County Weather Alerts' ?>
            </h1>
            
            <ul class="nav nav-pills justify-content-center">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" 
                       href="index.php">Active Alerts</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'history.php' ? 'active' : '' ?>" 
                       href="history.php">Alert History</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'map.php' ? 'active' : '' ?>" 
                       href="map.php">Alert Map</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'stats.php' ? 'active' : '' ?>" 
                       href="stats.php">Statistics</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'status.php' ? 'active' : '' ?>" 
                       href="status.php">System Status</a>
                </li>
            </ul>
            
            <?php if (isset($headerContent)) echo $headerContent; ?>
        </div>
    </div>
    <div class="main-content">