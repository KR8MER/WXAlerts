<?php
/**
 * WeatherAlertSystem.php
 * Last Modified: 2025-01-26 15:00:00 UTC
 * Modified By: KR8MER
 */

declare(strict_types=1);

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

class WeatherAlertSystem {
    private $db;
    private $cache = [];
    private $debugMode = false;
    private $logCounter = 0;
    private $logLimit = 100;

    private const PUTNAM_FIPS = '039137';
    private const NWS_CAP_FEED = 'https://alerts.weather.gov/cap/oh.php?x=0';
    private const USER_AGENT = 'PutnamCountyAlertSystem/1.0';
    private const CACHE_DURATION = 300;

    private const ALERT_CATEGORIES = [
        'SEVERE' => ['Tornado Warning', 'Severe Thunderstorm Warning', 'Flash Flood Warning'],
        'WINTER' => ['Winter Storm Warning', 'Ice Storm Warning', 'Blizzard Warning', 'Winter Weather Advisory'],
        'FLOOD' => ['Flood Warning', 'Flood Watch', 'Flood Advisory'],
        'HEAT' => ['Excessive Heat Warning', 'Heat Advisory'],
        'WIND' => ['High Wind Warning', 'Wind Advisory'],
        'OTHER' => []
    ];

    public function __construct() {
        try {
            $config = require APP_PATH . '/config/config.php';
            error_log("Initializing WeatherAlertSystem...");
            
            $this->db = new PDO(
                "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            error_log("Database connection established successfully");
            $this->verifyTableSchema();
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new PDOException("Database connection failed: " . $e->getMessage());
        }
    }

    private function logError($message) {
        if ($this->logCounter < $this->logLimit || $this->debugMode) {
            error_log("[WeatherAlertSystem] " . $message);
            $this->logCounter++;
        }
    }

    public function setDebugMode($mode) {
        $this->debugMode = (bool)$mode;
        error_log("Debug mode " . ($mode ? "enabled" : "disabled"));
    }

    private function convertToMySQLDateTime($dateStr) {
        try {
            $date = new DateTime($dateStr);
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $this->logError("Date conversion error: " . $e->getMessage());
            throw $e;
        }
    }

    private function fetchXMLWithCurl($url) {
        $urlString = (string)$url;
        $cacheKey = md5($urlString);

        if (isset($this->cache[$cacheKey]) && (time() - $this->cache[$cacheKey]['time'] < self::CACHE_DURATION)) {
            $this->logError("Using cached data for URL: " . $urlString);
            return $this->cache[$cacheKey]['data'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $urlString,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'Accept: application/xml',
                'Cache-Control: no-cache'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $this->logError("Fetching data from URL: " . $urlString);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logError("cURL Error fetching data: " . $error);
            throw new Exception("cURL Error: $error");
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logError("HTTP Error fetching data: " . $httpCode);
            throw new Exception("HTTP Error: $httpCode");
        }

        if ($response === false) {
            $this->logError("cURL returned false response");
            throw new Exception("Failed to fetch data from URL: " . $urlString);
        }

        $xml = simplexml_load_string($response);
        if (!$xml) {
            $this->logError("Failed to parse XML response");
            throw new Exception("Failed to parse XML response");
        }

        $this->cache[$cacheKey] = [
            'time' => time(),
            'data' => $xml
        ];

        return $xml;
    }

    private function isForPutnamCounty($cap) {
        $this->logError("Checking if alert is for Putnam County...");
        
        if (!isset($cap->info->area->geocode)) {
            $this->logError("No geocode found in alert");
            return false;
        }

        foreach ($cap->info->area->geocode as $geocode) {
            if ((string)$geocode->valueName === 'SAME' && 
                (string)$geocode->value === self::PUTNAM_FIPS) {
                $this->logError("Match found! Alert is for Putnam County");
                return true;
            }
        }

        $this->logError("No match found - alert is not for Putnam County");
        return false;
    }

    private function processPolygon($cap) {
        if (!isset($cap->info)) {
            $this->logError("No info section found in CAP");
            return ['type' => 'NONE', 'coordinates' => null];
        }

        $info = $cap->info;

        if (isset($info->area->polygon)) {
            $this->logError("Found polygon data");
            return [
                'type' => 'POLYGON',
                'coordinates' => (string)$info->area->polygon
            ];
        }

        if (isset($info->area->circle)) {
            $this->logError("Found circle data");
            return [
                'type' => 'CIRCLE',
                'coordinates' => (string)$info->area->circle
            ];
        }

        $this->logError("No geometry data found");
        return [
            'type' => 'NONE',
            'coordinates' => null
        ];
    }

    public function fetchAlerts() {
        try {
            $this->logError("Starting to fetch alerts from NWS feed");
            $xml = $this->fetchXMLWithCurl(self::NWS_CAP_FEED);
            $this->logError("Successfully fetched XML feed. Processing entries...");

            $processedCount = 0;
            $totalEntries = count($xml->entry);
            $this->logError("Found {$totalEntries} entries in feed");

            foreach ($xml->entry as $entry) {
                try {
                    $this->logError("Processing alert: " . $entry->title);
                    $alertXml = $this->fetchXMLWithCurl($entry->link['href']);

                    if (!$this->isForPutnamCounty($alertXml)) {
                        $this->logError("Alert skipped - not for Putnam County: " . $entry->title);
                        continue;
                    }

                    $this->processAlert($alertXml, $entry);
                    $processedCount++;
                    $this->logError("Successfully processed alert: " . $entry->title);

                    usleep(100000); // 100ms delay between requests

                } catch (Exception $e) {
                    $this->logError("Error processing individual alert: " . $e->getMessage());
                    continue;
                }
            }

            $this->logError("Processing complete. Processed $processedCount alerts");
            return $processedCount;

        } catch (Exception $e) {
            $this->logError("Error fetching alerts: " . $e->getMessage());
            throw $e;
        }
    }

private function processAlert($alertXml, $entry) {
    try {
        // Extract and log relevant information
        $alertId = (string) $alertXml->identifier;
        $event = (string) $alertXml->info->event;
        $severity = (string) $alertXml->info->severity;
        $description = (string) $alertXml->info->description;
        $expires = $this->convertToMySQLDateTime((string) $alertXml->info->expires);
        $effective = $this->convertToMySQLDateTime((string) $alertXml->info->effective);
        $ends = $this->convertToMySQLDateTime((string) $alertXml->info->ends);
        $title = (string) $alertXml->info->headline;
        $polygon = (string) $alertXml->info->area->polygon;
        $polygonType = !empty($polygon) ? 'POLYGON' : 'NONE';

        // Log the extracted information
        $this->logError("Event: $event, Severity: $severity, Expires: $expires, Effective: $effective, Ends: $ends");

        // Example database insertion (you can modify based on your schema)
        $stmt = $this->db->prepare("
            INSERT INTO alerts (alert_id, event, severity, description, expires, effective, ends, created_at, title, polygon, polygon_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([$alertId, $event, $severity, $description, $expires, $effective, $ends, $title, $polygon, $polygonType]);

        $this->logError("Alert processed and stored in database");
    } catch (PDOException $e) {
        $this->logError("Error processing individual alert: " . $e->getMessage());
    }
}
    private function verifyTableSchema() {
        $requiredColumns = [
            'event' => 'VARCHAR(255) NOT NULL',
            'severity' => 'VARCHAR(50)',
            'description' => 'TEXT',
            'expires' => 'DATETIME',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ];

        foreach ($requiredColumns as $column => $definition) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM alerts LIKE '$column'");
                if ($stmt->rowCount() == 0) {
                    $this->logError("Column '$column' not found in 'alerts' table. Adding column...");
                    $this->db->exec("ALTER TABLE alerts ADD COLUMN $column $definition");
                    $this->logError("Successfully added column '$column' to 'alerts' table.");
                }
            } catch (PDOException $e) {
                $this->logError("Error verifying table schema: " . $e->getMessage());
                throw $e;
            }
        }
    }

    public function searchAlerts($criteria = '', $filters = []) {
        try {
            $this->logError("=== Starting searchAlerts ===");
            $this->logError("Criteria: " . print_r($criteria, true));
            $this->logError("Filters: " . print_r($filters, true));
            
            // Basic query to get all alerts
            $query = "SELECT * FROM alerts ORDER BY created_at DESC";
            
            $this->logError("Executing query: " . $query);
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->logError("Found " . count($results) . " alerts");
            foreach ($results as $index => $alert) {
                $this->logError(sprintf(
                    "Alert %d: ID=%s, Status=%s, Type=%s, Severity=%s, Created=%s",
                    $index + 1,
                    $alert['id'],
                    $alert['status'],
                    $alert['event_type'],
                    $alert['severity'],
                    $alert['created_at']
                ));
            }
            
            return $results;
            
        } catch (PDOException $e) {
            $this->logError("Database error in searchAlerts: " . $e->getMessage());
            $this->logError("Stack trace: " . $e->getTraceAsString());
            return [];
        } catch (Exception $e) {
            $this->logError("General error in searchAlerts: " . $e->getMessage());
            $this->logError("Stack trace: " . $e->getTraceAsString());
            return [];
        }
    }

    public function getHistoricalAlerts($limit = 100) {
        try {
            $this->logError("Retrieving historical alerts, limit: $limit");
            
            $stmt = $this->db->prepare("
                SELECT *
                FROM alerts
                WHERE created_at <= NOW()
                ORDER BY created_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$limit]);
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->logError("Number of historical alerts retrieved: " . count($alerts));
            return $alerts;

        } catch (PDOException $e) {
            $this->logError("Error retrieving historical alerts: " . $e->getMessage());
            return [];
        }
    }

    public function getAlertStats() {
        try {
            $stats = [];
            
            // Get active alerts count
            $activeCount = count($this->getActiveAlerts());
            $stats['active'] = $activeCount;
            
            // Get 24-hour statistics
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_alerts,
                    COUNT(DISTINCT event_type) as unique_types,
                    SUM(CASE WHEN severity = 'Extreme' THEN 1 ELSE 0 END) as extreme_alerts,
                    SUM(CASE WHEN severity = 'Severe' THEN 1 ELSE 0 END) as severe_alerts,
                    MAX(created_at) as latest_alert
                FROM alerts 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            
            $stmt->execute();
            $stats['last24h'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get monthly statistics
            $stmt = $this->db->prepare("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as alert_count
                FROM alerts
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month DESC
            ");
            
            $stmt->execute();
            $stats['monthly'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->logError("Alert stats generated successfully");
            return $stats;

        } catch (PDOException $e) {
            $this->logError("Error getting alert stats: " . $e->getMessage());
            return [
                'active' => 0,
                'last24h' => [
                    'total_alerts' => 0,
                    'unique_types' => 0,
                    'extreme_alerts' => 0,
                    'severe_alerts' => 0,
                    'latest_alert' => null
                ],
                'monthly' => []
            ];
        }
    }

    public function getBoundaryData($type) {
        try {
            $this->logError("Fetching boundaries for type: $type");
            
            // First, check if we have any boundaries of this type
            $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM boundaries WHERE type = ?");
            $checkStmt->execute([$type]);
            $count = $checkStmt->fetchColumn();
            
            if ($count == 0) {
                $this->logError("No boundaries found for type: $type");
                return ['type' => 'FeatureCollection', 'features' => []];
            }

            // Get boundaries using MariaDB's spatial functions
            $stmt = $this->db->prepare("
                SELECT 
                    id,
                    name,
                    type,
                    properties,
                    ST_AsGeoJSON(geometry) as geojson
                FROM boundaries 
                WHERE type = ?
                AND geometry IS NOT NULL
            ");
            
            if (!$stmt->execute([$type])) {
                $this->logError("Database error: " . json_encode($stmt->errorInfo()));
                return ['type' => 'FeatureCollection', 'features' => []];
            }

            $features = [];
            while ($row = $stmt->fetch()) {
                try {
                    $this->logError("Processing boundary: {$row['id']} - {$row['name']}");
                    
                    $geojson = json_decode($row['geojson'], true);
                    $properties = json_decode($row['properties'] ?: '{}', true);

                    if (!$geojson) {
                        $this->logError("Invalid GeoJSON for boundary ID {$row['id']}: " . json_last_error_msg());
                        continue;
                    }

                    $properties = array_merge($properties ?: [], [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'type' => $row['type']
                    ]);

                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => $geojson,
                        'properties' => $properties
                    ];
                    
                    $this->logError("Successfully processed boundary {$row['id']}");
                } catch (Exception $e) {
                    $this->logError("Error processing boundary ID {$row['id']}: " . $e->getMessage());
                    continue;
                }
            }

            $this->logError("Total features processed: " . count($features));

            return [
                'type' => 'FeatureCollection',
                'features' => $features
            ];

        } catch (PDOException $e) {
            $this->logError("Database error in getBoundaryData: " . $e->getMessage());
            return ['type' => 'FeatureCollection', 'features' => []];
        }
    }

    public function alertsToGeoJSON($alerts) {
        $this->logError("Converting alerts to GeoJSON...");
        $features = [];

        foreach ($alerts as $alert) {
            try {
                // Base properties that all alerts should have
                $baseProperties = [
                    "id" => $alert["id"],
                    "title" => $alert["title"],
                    "severity" => $alert["severity"] ?: "Unknown",
                    "description" => $alert["description"],
                    "expires" => $alert["expires"],
                    "urgency" => $alert["urgency"],
                    "effective" => $alert["effective"],
                    "status" => $alert["status"]
                ];

                if ($alert["polygon_type"] !== "NONE" && !empty($alert["polygon"])) {
                    // Process specific area alert
                    $coordinates = [];
                    $polygonPoints = explode(" ", trim($alert["polygon"]));
                    
                    foreach ($polygonPoints as $point) {
                        $parts = explode(",", trim($point));
                        if (count($parts) === 2) {
                            $lat = filter_var($parts[0], FILTER_VALIDATE_FLOAT);
                            $lon = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
                            if ($lat !== false && $lon !== false) {
                                $coordinates[] = [$lon, $lat];
                            }
                        }
                    }

                    if (count($coordinates) > 2) {
                        // Close the polygon if needed
                        if ($coordinates[0] !== end($coordinates)) {
                            $coordinates[] = $coordinates[0];
                        }

                        $features[] = [
                            "type" => "Feature",
                            "properties" => array_merge($baseProperties, [
                                "type" => "specific",
                                "districts" => $alert["districts"] ?? []
                            ]),
                            "geometry" => [
                                "type" => "Polygon",
                                "coordinates" => [$coordinates]
                            ]
                        ];
                    }
                } else {
                    // Process county-wide alert
                    $features[] = [
                        "type" => "Feature",
                        "properties" => array_merge($baseProperties, [
                            "type" => "county-wide",
                            "districts" => [
                                'fire' => ['All Fire Districts'],
                                'ems' => ['All EMS Districts'],
                                'electric' => ['All Electric Providers']
                            ]
                        ])
                    ];
                }
            } catch (Exception $e) {
                $this->logError("Error processing alert to GeoJSON: " . $e->getMessage());
                continue;
            }
        }

        $result = ["type" => "FeatureCollection", "features" => $features];
        $this->logError("Generated GeoJSON with " . count($features) . " features");
        return $result;
    }

    private function getAffectedDistricts($rawPolygon) {
        error_log("[DEBUG] Starting getAffectedDistricts with polygon: " . substr($rawPolygon, 0, 50) . "...");

        try {
            // First check if we have any boundaries at all
            $count = $this->db->query("SELECT COUNT(*) FROM boundaries")->fetchColumn();
            error_log("[DEBUG] Found $count total boundaries in database");

            $coordinates = array_map(function($pair) {
                $parts = explode(',', trim($pair));
                // Add safety check for array parts
                if (count($parts) >= 2) {
                    $lat = $parts[0];
                    $lon = $parts[1];
                } else {
                    error_log("[DEBUG] Invalid coordinate pair: " . $pair);
                    return "0 0"; // Default coordinates if pair is invalid
                }
                return "$lon $lat";
            }, explode(' ', trim($rawPolygon)));

            if (empty($coordinates)) {
                error_log("[DEBUG] No valid coordinates found in polygon");
                return ['fire' => [], 'ems' => [], 'electric' => []];
            }

            $wkt = "POLYGON((" . implode(',', $coordinates) . "))";
            error_log("[DEBUG] Created WKT polygon: " . substr($wkt, 0, 50) . "...");

            // Modified query to remove duplicates and sort
            $testQuery = "
                SELECT DISTINCT b.id, b.name, b.type, ST_AsText(b.geometry) as geom
                FROM boundaries b
                WHERE ST_Intersects(
                    b.geometry, 
                    ST_GeomFromText(?)
                )
                GROUP BY b.type, b.name
                ORDER BY b.type, b.name";

            $stmt = $this->db->prepare($testQuery);
            $stmt->execute([$wkt]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("[DEBUG] Spatial query found " . count($results) . " intersecting boundaries");
            error_log("[DEBUG] Query results: " . print_r($results, true));

            $affected = ['fire' => [], 'ems' => [], 'electric' => []];
            foreach ($results as $row) {
                if (isset($row['type']) && isset($row['name']) && isset($affected[$row['type']])) {
                    // Only add if not already present
                    if (!in_array($row['name'], $affected[$row['type']], true)) {
                        $affected[$row['type']][] = $row['name'];
                    }
                }
            }

            // Sort each district array
            foreach ($affected as &$districts) {
                sort($districts, SORT_STRING);
            }
            unset($districts); // Break the reference

            error_log("[DEBUG] Final affected districts (sorted, deduplicated): " . print_r($affected, true));
            return $affected;

        } catch (Exception $e) {
            error_log("[ERROR] Error in getAffectedDistricts: " . $e->getMessage());
            error_log("[ERROR] Stack trace: " . $e->getTraceAsString());
            return ['fire' => [], 'ems' => [], 'electric' => []];
        }
    }

    public function getActiveAlerts($district = null) {
        try {
            $currentUTC = gmdate('Y-m-d H:i:s');
            error_log("========= DEBUG: getActiveAlerts() =========");
            error_log("Current UTC Time: " . $currentUTC);

            $query = "SELECT * FROM alerts 
                      WHERE status = 'Active'
                      AND effective <= ?
                      AND expires > ?
                      ORDER BY created_at DESC";

            error_log("Executing query: " . $query);

            $stmt = $this->db->prepare($query);
            $stmt->execute([$currentUTC, $currentUTC]);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Log the timestamps for debugging
            foreach ($results as $alert) {
                error_log("Alert ID: " . $alert['id'] . ", Effective: " . $alert['effective'] . ", Expires: " . $alert['expires']);
            }

            // Process each alert to get district information
            foreach ($results as &$alert) {
                if ($alert['polygon_type'] !== 'NONE' && !empty($alert['polygon'])) {
                    $alert['districts'] = $this->getAffectedDistricts($alert['polygon']);
                } else {
                    $alert['districts'] = [
                        'fire' => ['All Fire Districts'],
                        'ems' => ['All EMS Districts'],
                        'electric' => ['All Electric Providers']
                    ];
                }
            }

            return $results;

        } catch (PDOException $e) {
            error_log("DATABASE ERROR in getActiveAlerts: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [];
        }
    }

    public function setCountyWideAlert($alertId, $county) {
        try {
            // Update the alert to mark it as county-wide
            $query = "UPDATE alerts 
                      SET is_county_wide = TRUE, 
                          affected_county = :county 
                      WHERE id = :alert_id";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':alert_id', $alertId, PDO::PARAM_INT);
            $stmt->bindValue(':county', $county, PDO::PARAM_STR);
            $stmt->execute();

            // Get all districts in the affected county
            $query = "SELECT code FROM districts WHERE county = :county";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':county', $county, PDO::PARAM_STR);
            $stmt->execute();
            $districts = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Add alert-district mappings for all districts in the county
            foreach ($districts as $districtCode) {
                $query = "INSERT IGNORE INTO alert_districts 
                          (alert_id, district_code, is_county_wide) 
                          VALUES (:alert_id, :district_code, TRUE)";
                $stmt = $this->db->prepare($query);
                $stmt->bindValue(':alert_id', $alertId, PDO::PARAM_INT);
                $stmt->bindValue(':district_code', $districtCode, PDO::PARAM_STR);
                $stmt->execute();
            }

            return true;
        } catch (PDOException $e) {
            $this->logError("Error setting county-wide alert: " . $e->getMessage());
            return false;
        }
    }

    public function getDistrictCoverage() {
        try {
            $coverage = [
                'fire' => ['count' => 0, 'area' => 0],
                'ems' => ['count' => 0, 'area' => 0],
                'electric' => ['count' => 0, 'area' => 0]
            ];

            // Get counts and areas for each type
            $query = "SELECT 
                        type,
                        COUNT(*) as count,
                        COALESCE(SUM(ST_Area(geometry) * POW(69.172, 2)), 0) as area_sqmi
                     FROM boundaries 
                     GROUP BY type";

            $stmt = $this->db->query($query);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                if (isset($coverage[$row['type']])) {
                    $coverage[$row['type']]['count'] = (int)$row['count'];
                    $coverage[$row['type']]['area'] = (float)$row['area_sqmi'];
                }
            }

            $this->logError("District coverage data: " . json_encode($coverage));
            return $coverage;

        } catch (Exception $e) {
            $this->logError("Error getting district coverage: " . $e->getMessage());
            return [
                'fire' => ['count' => 0, 'area' => 0],
                'ems' => ['count' => 0, 'area' => 0],
                'electric' => ['count' => 0, 'area' => 0]
            ];
        }
    }

    public function getAlert($id) {
        try {
            $stmt = $this->db->prepare("
                SELECT *
                FROM alerts
                WHERE id = ?
            ");

            $stmt->execute([$id]);
            $alert = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($alert) {
                if ($alert['polygon_type'] !== 'NONE') {
                    $alert['districts'] = $this->getAffectedDistricts($alert['polygon']);
                }
            }

            return $alert;

        } catch (PDOException $e) {
            $this->logError("Error getting alert details: " . $e->getMessage());
            return null;
        }
    }

    public function testConnection() {
        try {
            $this->logError("Testing connection to NWS feed...");
            $xml = $this->fetchXMLWithCurl(self::NWS_CAP_FEED);
            $entryCount = count($xml->entry);
            $this->logError("Connection test successful. Found $entryCount entries");

            return [
                'success' => true,
                'message' => "Successfully connected to NWS feed",
                'entry_count' => $entryCount,
                'database_status' => $this->testDatabaseConnection()
            ];
        } catch (Exception $e) {
            $this->logError("Connection test failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'entry_count' => 0,
                'database_status' => $this->testDatabaseConnection()
            ];
        }
    }

    private function testDatabaseConnection() {
        try {
            $this->db->query("SELECT 1");
            return ['connected' => true, 'error' => null];
        } catch (PDOException $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    public function cleanup() {
        try {
            // Log before cleanup
            $countBefore = $this->db->query("SELECT COUNT(*) FROM alerts WHERE expires < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
            $this->logError("Alerts to be cleaned up: " . $countBefore);

            // Remove expired alerts
            $stmt = $this->db->prepare("
                DELETE FROM alerts 
                WHERE expires < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute();

            // Log after cleanup
            $countAfter = $this->db->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
            $this->logError("Total alerts after cleanup: " . $countAfter);

            // Clear cache
            $this->cache = [];

            return true;
        } catch (Exception $e) {
            $this->logError("Cleanup error: " . $e->getMessage());
            return false;
        }
    }
}