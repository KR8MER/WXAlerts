<?php
// public/test_boundary.php

$results = [];

// Test 1: Directory existence and permissions
$dataDir = __DIR__ . '/../data';
$results['data_directory'] = [
    'exists' => is_dir($dataDir),
    'path' => $dataDir,
    'permissions' => decoct(fileperms($dataDir) & 0777),
    'owner' => posix_getpwuid(fileowner($dataDir))['name'],
    'group' => posix_getgrgid(filegroup($dataDir))['name']
];

// Test 2: File existence and permissions
$boundaryFile = $dataDir . '/putnam_county_boundary.json';
$results['boundary_file'] = [
    'exists' => file_exists($boundaryFile),
    'path' => $boundaryFile,
    'permissions' => decoct(fileperms($boundaryFile) & 0777),
    'owner' => posix_getpwuid(fileowner($boundaryFile))['name'],
    'group' => posix_getgrgid(filegroup($boundaryFile))['name'],
    'size' => file_exists($boundaryFile) ? filesize($boundaryFile) : 'N/A'
];

// Test 3: JSON validity
if (file_exists($boundaryFile)) {
    $jsonContent = file_get_contents($boundaryFile);
    $results['json_test'] = [
        'can_read' => ($jsonContent !== false),
        'is_valid' => json_decode($jsonContent) !== null,
        'error' => json_last_error_msg(),
        'preview' => substr($jsonContent, 0, 100) . '...'
    ];
}

// Test 4: Coordinate validation
if (isset($results['json_test']['is_valid']) && $results['json_test']['is_valid']) {
    $data = json_decode($jsonContent, true);
    $results['coordinate_test'] = [
        'has_coordinates' => isset($data['geometry']['coordinates']),
        'coordinate_count' => isset($data['geometry']['coordinates'][0]) ? count($data['geometry']['coordinates'][0]) : 0,
        'first_coordinate' => isset($data['geometry']['coordinates'][0][0]) ? $data['geometry']['coordinates'][0][0] : null,
        'is_closed_polygon' => isset($data['geometry']['coordinates'][0]) ? 
            ($data['geometry']['coordinates'][0][0] === end($data['geometry']['coordinates'][0])) : false
    ];
}

// Display results
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Boundary File Test Results</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        .success { color: green; }
        .warning { color: orange; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Boundary File Test Results</h1>
    <?php foreach ($results as $test => $data): ?>
        <h2><?= ucwords(str_replace('_', ' ', $test)) ?></h2>
        <pre><?php print_r($data); ?></pre>
    <?php endforeach; ?>
</body>
</html>