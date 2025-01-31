<?php
/**
 * WeatherAlertSystem.php
 * Last Modified: 2025-01-31 22:18:15 UTC
 * Modified By: KR8MER
 * 
 * Core class for managing weather alerts and related data
 */

declare(strict_types=1);

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

class WeatherAlertSystem {
    private PDO $db;
    private array $cache = [];
    private bool $debugMode = false;
    private int $logCounter = 0;
    private int $logLimit = 100;

    private const PUTNAM_FIPS = '039137';
    private const NWS_CAP_FEED = 'https://alerts.weather.gov/cap/oh.php?x=0';
    private const USER_AGENT = 'PutnamCountyAlertSystem/1.0';
    private const CACHE_DURATION = 300;

    // Database schema constants
    private const ALERT_COLUMNS = [
        'id' => 'bigint(20) AUTO_INCREMENT PRIMARY KEY',
        'alert_id' => 'varchar(255) NOT NULL UNIQUE',
        'title' => 'varchar(255) NOT NULL',
        'event_type' => 'varchar(100) NOT NULL',
        'severity' => 'varchar(50) NOT NULL',
        'urgency' => 'varchar(50) NOT NULL',
        'description' => 'text',
        'effective' => 'datetime NOT NULL',
        'expires' => 'datetime NOT NULL',
        'ends' => 'datetime NOT NULL',
        'polygon_type' => "enum('NONE','POLYGON','CIRCLE') NOT NULL DEFAULT 'NONE'",
        'polygon' => 'geometry NOT NULL',
        'event' => 'varchar(100) NOT NULL',
        'status' => "varchar(20) NOT NULL DEFAULT 'Active'",
        'is_county_wide' => 'tinyint(1) NOT NULL DEFAULT 0',
        'affected_county' => 'varchar(50)',
        'certainty' => 'varchar(50)',
        'message_type' => 'varchar(50)',
        'category' => 'varchar(50)',
        'response' => 'varchar(50)',
        'polygon_coordinates' => 'longtext',
        'polygon_valid' => 'tinyint(1) DEFAULT 0',
        'same_codes' => 'text',
        'ugc_codes' => 'text',
        'created_at' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];

    private const BOUNDARY_COLUMNS = [
        'id' => 'int(11) AUTO_INCREMENT PRIMARY KEY',
        'name' => 'varchar(255) NOT NULL',
        'type' => 'varchar(50) NOT NULL',
        'properties' => 'longtext',
        'geometry' => 'geometry NOT NULL',
        'created_at' => 'timestamp DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];

    private const DISTRICT_COLUMNS = [
        'id' => 'int(11) AUTO_INCREMENT PRIMARY KEY',
        'alert_id' => 'varchar(255) NOT NULL',
        'district_type' => "enum('fire','ems','electric','telephone','township','school','city') NOT NULL",
        'district_name' => 'varchar(255) NOT NULL',
        'created_at' => 'timestamp DEFAULT CURRENT_TIMESTAMP'
    ];
	/**
     * Initialize WeatherAlertSystem with database connection
     * @throws PDOException If database connection fails
     */
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

    /**
     * Log error messages with rate limiting
     * @param string $message Error message to log
     */
    private function logError(string $message): void {
        if ($this->logCounter < $this->logLimit || $this->debugMode) {
            error_log("[WeatherAlertSystem] " . $message);
            $this->logCounter++;
        }
    }

    /**
     * Set debug mode status
     * @param bool $mode Debug mode enabled/disabled
     */
    public function setDebugMode(bool $mode): void {
        $this->debugMode = $mode;
        error_log("Debug mode " . ($mode ? "enabled" : "disabled"));
    }

    /**
     * Verify and update database schema
     * @throws Exception If schema verification fails
     */
    private function verifyTableSchema(): void {
        try {
            // Verify alerts table
            $this->verifyTable('alerts', self::ALERT_COLUMNS);
            
            // Verify boundaries table
            $this->verifyTable('boundaries', self::BOUNDARY_COLUMNS);
            
            // Verify districts table
            $this->verifyTable('districts', self::DISTRICT_COLUMNS);
            
            $this->logError("Schema verification completed successfully");
        } catch (PDOException $e) {
            $this->logError("Schema verification failed: " . $e->getMessage());
            throw new Exception("Failed to verify database schema: " . $e->getMessage());
        }
    }

    /**
     * Verify and update specific table schema
     * @param string $tableName Name of table to verify
     * @param array $columns Expected columns
     */
    private function verifyTable(string $tableName, array $columns): void {
        // Check if table exists
        $tableExists = $this->db->query("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
            AND table_name = '$tableName'
        ")->fetchColumn();

        if (!$tableExists) {
            $this->createTable($tableName, $columns);
            return;
        }

        // Get existing columns
        $stmt = $this->db->query("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = '$tableName'
        ");
        
        $existingColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingColumnNames = array_column($existingColumns, 'COLUMN_NAME');

        // Check for missing columns
        foreach ($columns as $columnName => $definition) {
            if (!in_array($columnName, $existingColumnNames)) {
                $this->logError("Adding missing column: $columnName to $tableName");
                $this->db->exec("ALTER TABLE $tableName ADD COLUMN $columnName $definition");
            }
        }
    }

    /**
     * Create a new table with specified schema
     * @param string $tableName Name of table to create
     * @param array $columns Column definitions
     */
    private function createTable(string $tableName, array $columns): void {
        $columnDefs = array_map(
            function ($name, $definition) {
                return "$name $definition";
            },
            array_keys($columns),
            $columns
        );

        $sql = "CREATE TABLE $tableName (
            " . implode(",\n            ", $columnDefs) . "
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
        
        // Add indexes based on table type
        switch ($tableName) {
            case 'alerts':
                $this->db->exec("CREATE INDEX idx_alert_status ON alerts(status)");
                $this->db->exec("CREATE INDEX idx_alert_expires ON alerts(expires)");
                $this->db->exec("CREATE SPATIAL INDEX idx_alert_polygon ON alerts(polygon)");
                break;
                
            case 'boundaries':
                $this->db->exec("CREATE INDEX idx_boundary_type ON boundaries(type)");
                $this->db->exec("CREATE SPATIAL INDEX idx_boundary_geometry ON boundaries(geometry)");
                break;
                
            case 'districts':
                $this->db->exec("CREATE INDEX idx_district_alert ON districts(alert_id)");
                $this->db->exec("CREATE INDEX idx_district_type ON districts(district_type)");
                break;
        }

        $this->logError("Created $tableName table with schema version " . date('YmdHis'));
    }

    /**
     * Test database connection
     * @return array Connection status and message
     */
    /**
     * Test the connection to weather alert feed
     * @return array Connection test results
     */
    public function testConnection(): array {
        try {
            // First test database connection
            $this->db->query('SELECT 1');

            // Now test alert feed connection
            $context = stream_context_create([
                'http' => [
                    'user_agent' => self::USER_AGENT,
                    'timeout' => 30
                ]
            ]);

            $feed = file_get_contents(self::NWS_CAP_FEED, false, $context);
            if ($feed === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to NWS CAP feed',
                    'entry_count' => 0
                ];
            }

            $xml = simplexml_load_string($feed);
            if ($xml === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to parse NWS CAP feed',
                    'entry_count' => 0
                ];
            }

            // Count entries in the feed
            $entries = $xml->xpath('//entry');
            $entry_count = count($entries);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'entry_count' => $entry_count
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'entry_count' => 0
            ];
        }
    }
	/**
     * Get a specific alert by ID
     * @param string|int $id Alert ID or database ID
     * @return array|null Alert data or null if not found
     */
    public function getAlert($id): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT *,
                    ST_AsText(polygon) as polygon_text,
                    ST_AsGeoJSON(polygon) as polygon_geojson
                FROM alerts 
                WHERE id = ? OR alert_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $id]);
            $alert = $stmt->fetch();
            
            if (!$alert) {
                return null;
            }

            // Add affected districts
            $alert['affected_districts'] = $this->getAffectedDistricts($alert);
            
            return $alert;
        } catch (PDOException $e) {
            $this->logError("Error fetching alert $id: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all currently active alerts
     * @return array List of active alerts
     */
    public function getActiveAlerts(): array {
        try {
            $currentTime = gmdate('Y-m-d H:i:s');
            $stmt = $this->db->prepare("
                SELECT *,
                    ST_AsText(polygon) as polygon_text,
                    ST_AsGeoJSON(polygon) as polygon_geojson
                FROM alerts 
                WHERE status = 'Active' 
                AND effective <= ? 
                AND expires > ?
                ORDER BY 
                    FIELD(severity, 'Extreme', 'Severe', 'Moderate', 'Minor') DESC,
                    created_at DESC
            ");
            
            $stmt->execute([$currentTime, $currentTime]);
            $alerts = $stmt->fetchAll();
            
            // Add district information for each alert
            foreach ($alerts as &$alert) {
                $alert['affected_districts'] = $this->getAffectedDistricts($alert);
            }
            
            return $alerts;
        } catch (PDOException $e) {
            $this->logError("Error fetching active alerts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search alerts with filters
     * @param array|string $filters Filter parameters or search string
     * @return array Filtered alerts
     */
    public function searchAlerts(array|string $filters = []): array {
        try {
            // Convert string input to search filter array
            if (is_string($filters)) {
                $filters = ['search' => $filters];
            }

            // Ensure filters is an array
            $filters = is_array($filters) ? $filters : [];

            $where = ['1=1'];
            $params = [];

            // Apply text search
            if (!empty($filters['search'])) {
                $where[] = "(title LIKE ? OR description LIKE ? OR event_type LIKE ?)";
                $searchPattern = "%{$filters['search']}%";
                $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern]);
            }

            // Apply category filter
            if (!empty($filters['category'])) {
                $where[] = "category = ?";
                $params[] = $filters['category'];
            }

            // Apply severity filter
            if (!empty($filters['severity'])) {
                $where[] = "severity = ?";
                $params[] = $filters['severity'];
            }

            // Apply date range filters
            if (!empty($filters['start_date'])) {
                $where[] = "created_at >= ?";
                $params[] = $this->convertToMySQLDateTime($filters['start_date']);
            }
            if (!empty($filters['end_date'])) {
                $where[] = "created_at <= ?";
                $params[] = $this->convertToMySQLDateTime($filters['end_date']);
            }

            // Apply status filter
            if (!empty($filters['status'])) {
                $where[] = "status = ?";
                $params[] = $filters['status'];
            }

            // Apply county-wide filter
            if (isset($filters['is_county_wide'])) {
                $where[] = "is_county_wide = ?";
                $params[] = (int)$filters['is_county_wide'];
            }

            // Build and execute query
            $limit = isset($filters['limit']) ? (int)$filters['limit'] : 100;
            $sql = "SELECT *,
                    ST_AsText(polygon) as polygon_text,
                    ST_AsGeoJSON(polygon) as polygon_geojson
                   FROM alerts 
                   WHERE " . implode(" AND ", $where) . 
                   " ORDER BY created_at DESC LIMIT ?";
            
            $params[] = $limit;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $alerts = $stmt->fetchAll();
            
            // Add district information
            foreach ($alerts as &$alert) {
                $alert['affected_districts'] = $this->getAffectedDistricts($alert);
            }
            
            return $alerts;

        } catch (PDOException $e) {
            $this->logError("Search alerts error: " . $e->getMessage());
            return [];
        } catch (Exception $e) {
            $this->logError("Search parameter error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up expired alerts and update alert statuses
     * @param int $daysToKeep Number of days to keep expired alerts
     * @return array Results of cleanup operation
     */
    public function cleanupAlerts(int $daysToKeep = 30): array {
        try {
            // Update expired alerts
            $stmt = $this->db->prepare("
                UPDATE alerts 
                SET status = 'Expired'
                WHERE status = 'Active'
                AND expires < UTC_TIMESTAMP()
            ");
            $stmt->execute();
            $expiredCount = $stmt->rowCount();

            // Delete old expired alerts
            $stmt = $this->db->prepare("
                DELETE a FROM alerts a
                LEFT JOIN districts d ON a.alert_id = d.alert_id
                WHERE a.status = 'Expired'
                AND a.expires < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            $deletedCount = $stmt->rowCount();

            return [
                'expired' => $expiredCount,
                'deleted' => $deletedCount,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (PDOException $e) {
            $this->logError("Error cleaning up alerts: " . $e->getMessage());
            return [
                'expired' => 0,
                'deleted' => 0,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
	/**
     * Get comprehensive alert statistics
     * @return array Alert statistics
     */
    /**
     * Get alert statistics
     * @return array Alert statistics
     */
    public function getAlertStats(): array {
        try {
            return [
                'active' => $this->getActiveAlertCount(),
                'last24h' => [
                    'total_alerts' => $this->getTotalAlerts24h(),
                    'extreme_alerts' => $this->getExtremeAlerts24h(),
                    'unique_types' => $this->getUniqueAlertTypes24h()
                ]
            ];
        } catch (Exception $e) {
            $this->logError("Error getting alert stats: " . $e->getMessage());
            return [
                'active' => 0,
                'last24h' => [
                    'total_alerts' => 0,
                    'extreme_alerts' => 0,
                    'unique_types' => 0
                ]
            ];
        }
    }

    /**
     * Get alert trend data for specified period
     * @param string $period Period to analyze ('hourly', 'daily', 'weekly', 'monthly')
     * @param int $limit Number of periods to return
     * @return array Trend data
     */
    public function getAlertTrends(string $period = 'daily', int $limit = 30): array {
        try {
            $grouping = match($period) {
                'hourly' => 'DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00")',
                'daily' => 'DATE(created_at)',
                'weekly' => 'DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))',
                'monthly' => 'DATE_FORMAT(created_at, "%Y-%m-01")',
                default => throw new InvalidArgumentException("Invalid period: $period")
            };

            $sql = "
                SELECT 
                    $grouping as period,
                    COUNT(*) as total,
                    SUM(is_county_wide) as county_wide,
                    COUNT(DISTINCT event_type) as unique_types,
                    GROUP_CONCAT(DISTINCT event_type) as event_types
                FROM alerts 
                GROUP BY period
                ORDER BY period DESC
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$limit]);
            
            return [
                'period' => $period,
                'data' => $stmt->fetchAll(),
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (PDOException $e) {
            $this->logError("Error getting alert trends: " . $e->getMessage());
            return [
                'period' => $period,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
	/**
     * Get affected districts for an alert
     * @param array $alert Alert data
     * @return array Districts by type
     */
    public function getAffectedDistricts(array $alert): array {
        try {
            if (empty($alert['alert_id'])) {
                throw new Exception("Alert ID is required");
            }

            $stmt = $this->db->prepare("
                SELECT district_type, GROUP_CONCAT(district_name) as names
                FROM districts
                WHERE alert_id = ?
                GROUP BY district_type
                ORDER BY district_type
            ");
            
            $stmt->execute([$alert['alert_id']]);
            
            $districts = [
                'fire' => [],
                'ems' => [],
                'electric' => [],
                'telephone' => [],
                'township' => [],
                'school' => [],
                'city' => []
            ];

            while ($row = $stmt->fetch()) {
                $districts[$row['district_type']] = explode(',', $row['names']);
            }

            // If county-wide alert, include all districts
            if ($alert['is_county_wide']) {
                foreach ($districts as $type => &$names) {
                    if (empty($names)) {
                        $names = $this->getAllDistrictNames($type);
                    }
                }
            }

            return $districts;

        } catch (PDOException $e) {
            $this->logError("Error getting affected districts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all district names of a specific type
     * @param string $type District type
     * @return array District names
     */
    private function getAllDistrictNames(string $type): array {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT name 
                FROM boundaries 
                WHERE type = ?
                ORDER BY name
            ");
            $stmt->execute([$type]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $this->logError("Error getting district names: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get boundary data for mapping
     * @param string $type Boundary type
     * @return array GeoJSON formatted boundary data
     */
    public function getBoundaryData(string $type): array {
        try {
            // Validate boundary type
            $validTypes = ['fire', 'ems', 'electric', 'telephone', 'township', 'school', 'city'];
            if (!in_array($type, $validTypes)) {
                throw new InvalidArgumentException("Invalid boundary type: $type");
            }

            $stmt = $this->db->prepare("
                SELECT 
                    name,
                    ST_AsGeoJSON(geometry) as geometry,
                    properties
                FROM boundaries
                WHERE type = ?
                ORDER BY name ASC
            ");
            
            $stmt->execute([$type]);
            $features = [];
            
            while ($row = $stmt->fetch()) {
                $properties = json_decode($row['properties'] ?? '{}', true) ?: [];
                $properties['name'] = $row['name'];
                
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => json_decode($row['geometry']),
                    'properties' => $properties
                ];
            }

            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (PDOException $e) {
            $this->logError("Error fetching boundary data: " . $e->getMessage());
            return [
                'type' => 'FeatureCollection', 
                'features' => [],
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Fetch alerts from NWS CAP feed
     * @return array Result of fetch operation
     */
    
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
	
	public function fetchAlerts(): int {
    try {
        $context = stream_context_create([
            'http' => [
                'user_agent' => self::USER_AGENT,
                'timeout' => 30
            ]
        ]);

        // Fetch GeoJSON from NWS API
        $json = file_get_contents('https://api.weather.gov/alerts/active?zone=OHC137', false, $context);
        if ($json === false) {
            throw new Exception('Failed to fetch alerts from NWS API');
        }

        $data = json_decode($json, true);
        if (!isset($data['features'])) {
            throw new Exception('Invalid GeoJSON format');
        }

        $newAlertCount = 0;
        foreach ($data['features'] as $feature) {
            $alert = $this->processGeoJSONAlert($feature);
            
            // Check if alert already exists
            $stmt = $this->db->prepare("SELECT id FROM alerts WHERE cap_id = ?");
            $stmt->execute([$alert['id']]);
            
            if (!$stmt->fetch()) {
                // Insert new alert
                $this->insertAlert($alert);
                $newAlertCount++;

                // Process districts if geometry exists
                if ($alert['geometry'] !== null) {
                    $this->processAlertDistricts($alert);
                }
            }
        }

        return $newAlertCount;

    } catch (Exception $e) {
        $this->logError("Error fetching alerts: " . $e->getMessage());
        return 0;
    }
}

/**
 * Process GeoJSON alert data
 * @param array $feature GeoJSON feature object
 * @return array Processed alert data
 */
private function processGeoJSONAlert(array $feature): array {
    $props = $feature['properties'];
    $geom = $feature['geometry'];
    
    $alert = [
        'alert_id' => $props['id'],
        'title' => $props['headline'],
        'event_type' => $props['event'],
        'event' => $props['event'], // Duplicate for your schema
        'severity' => $props['severity'],
        'urgency' => $props['urgency'],
        'certainty' => $props['certainty'],
        'status' => $props['status'],
        'message_type' => $props['messageType'],
        'category' => $props['category'],
        'response' => $props['response'],
        'effective' => date('Y-m-d H:i:s', strtotime($props['effective'])),
        'expires' => date('Y-m-d H:i:s', strtotime($props['expires'])),
        'ends' => isset($props['ends']) ? date('Y-m-d H:i:s', strtotime($props['ends'])) : null,
        'description' => $props['description'],
        'polygon_type' => 'NONE',
        'is_county_wide' => 0,
        'affected_county' => null,
        'polygon_valid' => 0,
        'same_codes' => isset($props['geocode']['SAME']) ? json_encode($props['geocode']['SAME']) : null,
        'ugc_codes' => isset($props['geocode']['UGC']) ? json_encode($props['geocode']['UGC']) : null
    ];

    // Handle geometry if present
    if ($geom !== null && $geom['type'] === 'Polygon') {
        $alert['polygon_type'] = 'POLYGON';
        $alert['polygon_coordinates'] = json_encode($geom['coordinates']);
        $alert['polygon'] = $this->convertToWKT($geom);
        $alert['polygon_valid'] = 1;
    }

    // Check if alert is county-wide based on SAME codes or UGC codes
    if (!empty($props['geocode']['SAME']) || !empty($props['geocode']['UGC'])) {
        $alert['is_county_wide'] = 1;
        // Extract county from UGC code (e.g., "WVC001" -> "Barbour")
        if (!empty($props['geocode']['UGC'])) {
            $alert['affected_county'] = $this->getCountyFromUGC($props['geocode']['UGC'][0]);
        }
    }

    return $alert;
}

/**
 * Convert GeoJSON geometry to WKT format for database storage
 * @param array $geometry GeoJSON geometry object
 * @return string WKT geometry
 */
private function convertToWKT(array $geometry): string {
    if ($geometry['type'] !== 'Polygon') {
        return null;
    }

    $coords = $geometry['coordinates'][0];
    $points = [];
    
    foreach ($coords as $coord) {
        $points[] = $coord[0] . ' ' . $coord[1];
    }
    
    return 'POLYGON((' . implode(',', $points) . '))';
}

/**
 * Insert or update alert in database
 * @param array $alert Processed alert data
 * @return bool Success status
 */
private function insertAlert(array $alert): bool {
    try {
        $sql = "INSERT INTO alerts (
                    alert_id, title, event_type, event, severity, urgency, certainty,
                    status, message_type, category, response, effective, expires, ends,
                    description, polygon_type, polygon, is_county_wide, affected_county,
                    polygon_coordinates, polygon_valid, same_codes, ugc_codes
                ) VALUES (
                    :alert_id, :title, :event_type, :event, :severity, :urgency, :certainty,
                    :status, :message_type, :category, :response, :effective, :expires, :ends,
                    :description, :polygon_type, ST_GeomFromText(:polygon, 4326), :is_county_wide,
                    :affected_county, :polygon_coordinates, :polygon_valid, :same_codes, :ugc_codes
                ) ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    event_type = VALUES(event_type),
                    event = VALUES(event),
                    severity = VALUES(severity),
                    urgency = VALUES(urgency),
                    certainty = VALUES(certainty),
                    status = VALUES(status),
                    message_type = VALUES(message_type),
                    category = VALUES(category),
                    response = VALUES(response),
                    effective = VALUES(effective),
                    expires = VALUES(expires),
                    ends = VALUES(ends),
                    description = VALUES(description),
                    polygon_type = VALUES(polygon_type),
                    polygon = VALUES(polygon),
                    is_county_wide = VALUES(is_county_wide),
                    affected_county = VALUES(affected_county),
                    polygon_coordinates = VALUES(polygon_coordinates),
                    polygon_valid = VALUES(polygon_valid),
                    same_codes = VALUES(same_codes),
                    ugc_codes = VALUES(ugc_codes)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($alert);

    } catch (PDOException $e) {
        $this->logError("Error inserting/updating alert: " . $e->getMessage());
        return false;
    }
}

/**
 * Extract county name from UGC code
 * @param string $ugc UGC code (e.g., "WVC001")
 * @return string|null County name
 */
private function getCountyFromUGC(string $ugc): ?string {
    // You'll need to implement a lookup table or API call to convert UGC to county name
    // For now, we'll return the code
    return $ugc;
}

/**
 * Process districts affected by an alert
 * @param array $alert Alert data
 */
private function processAlertDistricts(array $alert): void {
    $sql = "SELECT type, name FROM boundaries 
            WHERE ST_Intersects(geometry, ST_GeomFromText(?, 4326))";
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$alert['geometry']]);
    
    $districts = [
        'fire' => [],
        'ems' => [],
        'electric' => []
    ];

    while ($row = $stmt->fetch()) {
        if (isset($districts[$row['type']])) {
            $districts[$row['type']][] = $row['name'];
        }
    }

    // Store district associations
    foreach ($districts as $type => $names) {
        if (!empty($names)) {
            foreach ($names as $name) {
                $sql = "INSERT INTO alert_districts (alert_id, district_type, district_name) 
                        VALUES (?, ?, ?)";
                $this->db->prepare($sql)->execute([$alert['id'], $type, $name]);
            }
        }
    }
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



    /**
     * Convert alerts to GeoJSON format for mapping
     * @param array $alerts Array of alerts to convert
     * @return array GeoJSON formatted alert data
     */
    public function alertsToGeoJSON(array $alerts): array {
        try {
            $features = [];

            foreach ($alerts as $alert) {
                // Skip alerts without geometry
                if (empty($alert['polygon']) || $alert['polygon_type'] === 'NONE') {
                    continue;
                }

                // Use existing GeoJSON if available
                $geometry = !empty($alert['polygon_geojson']) 
                    ? json_decode($alert['polygon_geojson'], true)
                    : null;

                // If no GeoJSON available, try to parse polygon_coordinates
                if (!$geometry && !empty($alert['polygon_coordinates'])) {
                    $geometry = json_decode($alert['polygon_coordinates'], true);
                }

                // Skip if no valid geometry found
                if (!$geometry) {
                    continue;
                }

                // Build properties for the feature
                $properties = [
                    'id' => $alert['id'],
                    'alert_id' => $alert['alert_id'],
                    'title' => $alert['title'],
                    'event_type' => $alert['event_type'],
                    'severity' => $alert['severity'],
                    'urgency' => $alert['urgency'],
                    'expires' => $alert['expires'],
                    'status' => $alert['status'],
                    'description' => $alert['description'] ?? '',
                    'is_county_wide' => (bool)($alert['is_county_wide'] ?? false),
                    'created_at' => $alert['created_at'],
                    'updated_at' => $alert['updated_at']
                ];

                // Add the feature to our collection
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => $geometry,
                    'properties' => $properties
                ];
            }

            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logError("Error converting alerts to GeoJSON: " . $e->getMessage());
            return [
                'type' => 'FeatureCollection',
                'features' => [],
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    /**
     * Get system status information
     * @return array System status details
     */
    public function getSystemStatus(): array {
        try {
            $currentTime = date('Y-m-d H:i:s');
            
            // Get district coverage
            $districtData = $this->getDistrictCoverage();
            
            // First, get all the alert counts
            $activeAlerts = $this->getActiveAlertCount();
            $totalAlerts24h = $this->getTotalAlerts24h();
            $extremeAlerts24h = $this->getExtremeAlerts24h();
            $uniqueTypes24h = $this->getUniqueAlertTypes24h();
            
            // Build district data with all required fields
            $districtTypes = ['fire', 'ems', 'electric'];
            $districts = [];
            
            foreach ($districtTypes as $type) {
                $districts[$type] = [
                    'total' => 0,
                    'affected' => 0,
                    'percentage' => 0,
                    'area' => $this->getDistrictArea($type),
                    'districts' => [],
                    'status' => 'normal', // Add status field
                    'coverage' => 0       // Add coverage field
                ];
                
                // If we have actual district data, merge it
                if (isset($districtData['coverage'][$type])) {
                    $districts[$type] = array_merge(
                        $districts[$type],
                        $districtData['coverage'][$type]
                    );
                }
            }

            // Build the complete status array matching status.php expectations
            $status = [
                'system' => [
                    'database' => $this->testConnection()['success'],
                    'last_update' => $currentTime,
                    'version' => '1.0.0',
                    'debug_mode' => $this->debugMode
                ],
                'alerts' => [
                    'active' => $activeAlerts,          // For line 188
                    'total_alerts' => $totalAlerts24h,  // For line 194
                    'extreme_alerts' => $extremeAlerts24h, // For line 202
                    'unique_types' => $uniqueTypes24h,  // For line 210
                    'total_all_time' => $this->getTotalAlertCount(),
                    'current' => []  // Add empty array for current alerts
                ],
                'districts' => $districts,
                'fire' => $districts['fire'],       // For line 281
                'ems' => $districts['ems'],         // For line 291
                'electric' => $districts['electric'] // For line 301
            ];

            return $status;

        } catch (Exception $e) {
            $this->logError("Error getting system status: " . $e->getMessage());
            
            // Return a complete structure even in case of error
            $defaultDistrict = [
                'total' => 0,
                'affected' => 0,
                'percentage' => 0,
                'area' => 0,
                'districts' => [],
                'status' => 'normal',
                'coverage' => 0
            ];

            return [
                'system' => [
                    'database' => false,
                    'last_update' => date('Y-m-d H:i:s'),
                    'version' => '1.0.0',
                    'debug_mode' => false,
                    'error' => $e->getMessage()
                ],
                'alerts' => [
                    'active' => 0,
                    'total_alerts' => 0,
                    'extreme_alerts' => 0,
                    'unique_types' => 0,
                    'total_all_time' => 0,
                    'current' => []
                ],
                'districts' => [
                    'fire' => $defaultDistrict,
                    'ems' => $defaultDistrict,
                    'electric' => $defaultDistrict
                ],
                'fire' => $defaultDistrict,
                'ems' => $defaultDistrict,
                'electric' => $defaultDistrict
            ];
        }
    }

    /**
     * Get total area for a district type
     * @param string $type District type
     * @return float Total area in square miles
     */
    private function getDistrictArea(string $type): float {
        try {
            $stmt = $this->db->prepare("
                SELECT SUM(ST_Area(geometry) * 0.000000386102) as total_area
                FROM boundaries
                WHERE type = ?
            ");
            $stmt->execute([$type]);
            return round((float)$stmt->fetchColumn(), 2);
        } catch (PDOException $e) {
            $this->logError("Error calculating district area: " . $e->getMessage());
            return 0.0;
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


    /**
     * Log debug information
     * @param string $message Debug message
     */
    private function logDebug(string $message): void {
        if ($this->debugMode) {
            error_log("[DEBUG] " . $message);
        }
    }

    /**
     * Convert various date formats to MySQL datetime
     * @param string $date Date string to convert
     * @return string MySQL formatted datetime
     */
    private function convertToMySQLDateTime(string $date): string {
        try {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                throw new Exception("Invalid date format: $date");
            }
            return date('Y-m-d H:i:s', $timestamp);
        } catch (Exception $e) {
            $this->logError("Date conversion error: " . $e->getMessage());
            return date('Y-m-d H:i:s'); // Return current date/time as fallback
        }
    }

    /**
     * Get total number of alerts in last 24 hours
     * @return int Number of alerts
     */
    private function getTotalAlerts24h(): int {
        try {
            return (int)$this->db->query("
                SELECT COUNT(*) 
                FROM alerts 
                WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            )->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Error getting 24h alert count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get number of extreme alerts in last 24 hours
     * @return int Number of extreme alerts
     */
    private function getExtremeAlerts24h(): int {
        try {
            return (int)$this->db->query("
                SELECT COUNT(*) 
                FROM alerts 
                WHERE severity = 'Extreme'
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            )->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Error getting extreme alert count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get number of unique alert types in last 24 hours
     * @return int Number of unique alert types
     */
    private function getUniqueAlertTypes24h(): int {
        try {
            return (int)$this->db->query("
                SELECT COUNT(DISTINCT event_type) 
                FROM alerts 
                WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            )->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Error getting unique alert types: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get active alert count
     * @return int Number of active alerts
     */
    private function getActiveAlertCount(): int {
        try {
            return (int)$this->db->query("
                SELECT COUNT(*) 
                FROM alerts 
                WHERE status = 'Active' 
                AND effective <= UTC_TIMESTAMP() 
                AND expires > UTC_TIMESTAMP()"
            )->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Error getting active alert count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total alert count
     * @return int Total number of alerts
     */
    private function getTotalAlertCount(): int {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Error getting total alert count: " . $e->getMessage());
            return 0;
        }
    }
}
