<?php
// Load database configuration
$config = require '/var/www/html/weewx/saratoga/weather-alerts/config/database.php';

if (!isset($config['dbname'])) {
    die("Database configuration is missing\n");
}

// Database connection
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}", $config['username'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to the database\n";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Create a new table for testing
$tableName = 'boundaries_test';
$createTableSql = "
CREATE TABLE IF NOT EXISTS $tableName (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('county', 'township', 'fire', 'ems', 'electric', 'telephone', 'voting', 'school', 'city') NOT NULL,
    name VARCHAR(255) NOT NULL,
    properties JSON,
    geometry GEOMETRY NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$pdo->exec($createTableSql);
echo "Table $tableName created successfully\n";

// Directory containing the boundary files
$dataDirectory = '/var/www/html/weewx/saratoga/weather-alerts/data/boundaries';

// Function to process JSON files and insert data into the database
function processJsonFile($pdo, $filePath, $type, $tableName) {
    $jsonData = file_get_contents($filePath);
    $data = json_decode($jsonData, true);

    foreach ($data['features'] as $feature) {
        $name = $feature['properties']['TELNAME'] ?? $feature['properties']['District'] ?? $feature['properties']['DEPT'] ?? $feature['properties']['TOWNSHIP_N'] ?? $feature['properties']['CORPORATIO'] ?? $feature['properties']['COMPNAME'];
        $properties = json_encode($feature['properties']);
        $geometry = json_encode($feature['geometry']);

        $geometryWkt = jsonToWkt($feature['geometry']);
        if ($geometryWkt === null) {
            echo "Skipping feature with invalid geometry in file: $filePath\n";
            continue;
        }

        // Insert data into the testing table
        $stmt = $pdo->prepare("INSERT INTO $tableName (type, name, properties, geometry) VALUES (:type, :name, :properties, ST_GeomFromText(:geometry))");
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':properties', $properties);
        $stmt->bindParam(':geometry', $geometryWkt);

        try {
            $stmt->execute();
            echo "Inserted data from file: $filePath\n";
        } catch (PDOException $e) {
            echo "Failed to insert data from file: $filePath - " . $e->getMessage() . "\n";
        }
    }
}

function jsonToWkt($geometry) {
    $type = strtoupper($geometry['type']);
    $coordinates = $geometry['coordinates'];

    switch ($type) {
        case 'POINT':
            return sprintf('POINT(%s %s)', $coordinates[0], $coordinates[1]);
        case 'LINESTRING':
            return sprintf('LINESTRING(%s)', implode(', ', array_map(function ($point) {
                return sprintf('%s %s', $point[0], $point[1]);
            }, $coordinates)));
        case 'POLYGON':
            return sprintf('POLYGON((%s))', implode(', ', array_map(function ($point) {
                return sprintf('%s %s', $point[0], $point[1]);
            }, $coordinates[0])));
        case 'MULTIPOLYGON':
            return sprintf('MULTIPOLYGON(((%s)))', implode('), (', array_map(function ($polygon) {
                return implode(', ', array_map(function ($point) {
                    return sprintf('%s %s', $point[0], $point[1]);
                }, $polygon[0]));
            }, $coordinates)));
        default:
            return null; // Invalid geometry type
    }
}

// Function to verify if spatial queries work correctly
function verifyOverlap($pdo, $alertId) {
    $query = "
    SELECT b.type, b.name
    FROM boundaries_test b
    JOIN alerts a ON ST_Intersects(b.geometry, a.polygon)
    WHERE a.alert_id = :alertId";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':alertId', $alertId);
    try {
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Overlap check results:\n";
        print_r($results);
    } catch (PDOException $e) {
        echo "Failed to perform overlap check - " . $e->getMessage() . "\n";
    }
}

// Process each JSON file
$jsonFiles = [
    'telephone_providers.json' => 'telephone',
    'school_districts.json' => 'school',
    'fire_districts.json' => 'fire',
    'ems_districts.json' => 'ems',
    'townships.json' => 'township',
    'villages.json' => 'city',
    'electric_providers.json' => 'electric'
];

foreach ($jsonFiles as $filename => $type) {
    $filePath = $dataDirectory . '/' . $filename;
    processJsonFile($pdo, $filePath, $type, $tableName);
}

// Perform an overlap check with a sample alert ID
verifyOverlap($pdo, 'alert1');

echo "Data insertion and verification completed\n";