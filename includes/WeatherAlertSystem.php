<?php
/**
 * WeatherAlertSystem.php
 * Last Modified: 2025-01-28 19:12:49 UTC
 * Modified By: KR8MER
 * 
 * Enhanced version with improved polygon handling and data validation
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
    
    // Alert categories with their associated event types
    private const ALERT_CATEGORIES = [
        'SEVERE' => [
            'Tornado Warning',
            'Severe Thunderstorm Warning',
            'Flash Flood Warning'
        ],
        'WINTER' => [
            'Winter Storm Warning',
            'Ice Storm Warning',
            'Blizzard Warning',
            'Winter Weather Advisory'
        ],
        'FLOOD' => [
            'Flood Warning',
            'Flood Watch',
            'Flood Advisory'
        ],
        'HEAT' => [
            'Excessive Heat Warning',
            'Heat Advisory'
        ],
        'WIND' => [
            'High Wind Warning',
            'Wind Advisory'
        ],
        'OTHER' => []
    ];

    // Required database columns and their definitions
    private const REQUIRED_COLUMNS = [
        'alert_id' => 'VARCHAR(255) NOT NULL',
        'event_type' => 'VARCHAR(255) NOT NULL',
        'severity' => 'VARCHAR(50)',
        'description' => 'TEXT',
        'expires' => 'DATETIME',
        'effective' => 'DATETIME',
        'ends' => 'DATETIME',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'title' => 'VARCHAR(255)',
        'message_type' => 'VARCHAR(50)',
        'category' => 'VARCHAR(50)',
        'certainty' => 'VARCHAR(50)',
        'urgency' => 'VARCHAR(50)',
        'response' => 'VARCHAR(50)',
        'status' => 'VARCHAR(50) DEFAULT "Active"',
        'polygon_type' => 'VARCHAR(20) DEFAULT "NONE"',
        'polygon_coordinates' => 'JSON',
        'polygon_valid' => 'BOOLEAN DEFAULT FALSE',
        'same_codes' => 'TEXT',
        'ugc_codes' => 'TEXT'
    ];

    public function __construct() {
        try {
            $config = require APP_PATH . '/config/config.php';
            $this->logError("Initializing WeatherAlertSystem...");
            
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
            
            $this->logError("Database connection established successfully");
            $this->verifyTableSchema();
        } catch (PDOException $e) {
            $this->logError("Database connection error: " . $e->getMessage());
            throw new PDOException("Database connection failed: " . $e->getMessage());
        }
    }

    private function logError(string $message): void {
        if ($this->logCounter < $this->logLimit || $this->debugMode) {
            error_log("[WeatherAlertSystem] " . $message);
            $this->logCounter++;
        }
    }

    public function setDebugMode(bool $mode): void {
        $this->debugMode = $mode;
        $this->logError("Debug mode " . ($mode ? "enabled" : "disabled"));
    }
	/**
     * Converts a date string to MySQL datetime format in UTC
     * @param string $dateStr The date string to convert
     * @return string MySQL formatted datetime string
     * @throws Exception If date parsing fails
     */
    private function convertToMySQLDateTime(string $dateStr): string {
        try {
            $date = new DateTime($dateStr);
            $date->setTimezone(new DateTimeZone('UTC'));
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $this->logError("Date conversion error for '$dateStr': " . $e->getMessage());
            throw new Exception("Invalid date format: $dateStr");
        }
    }

    /**
     * Fetches XML data from a URL with caching
     * @param string $url The URL to fetch from
     * @return SimpleXMLElement The parsed XML data
     * @throws Exception If fetching or parsing fails
     */
    private function fetchXMLWithCurl(string $url): SimpleXMLElement {
        $urlString = (string)$url;
        $cacheKey = md5($urlString);

        // Check cache first
        if (isset($this->cache[$cacheKey]) && 
            (time() - $this->cache[$cacheKey]['time'] < self::CACHE_DURATION)) {
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

        $xml = @simplexml_load_string($response);
        if (!$xml) {
            $this->logError("Failed to parse XML response");
            throw new Exception("Failed to parse XML response");
        }

        // Store in cache
        $this->cache[$cacheKey] = [
            'time' => time(),
            'data' => $xml
        ];

        return $xml;
    }

    /**
     * Checks if an alert is for Putnam County
     * @param SimpleXMLElement $cap The CAP alert data
     * @return bool True if alert is for Putnam County
     */
    private function isForPutnamCounty(SimpleXMLElement $cap): bool {
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

    /**
     * Processes and validates polygon data from an alert
     * @param mixed $alert The alert data containing geometry information
     * @return array Processed polygon data with type, coordinates, and validation status
     */
    private function processPolygon($alert): array {
        try {
            // Check for GeoJSON geometry
            if (isset($alert->geometry) && $alert->geometry !== null) {
                if ($alert->geometry->type === 'Polygon') {
                    $this->logError("Found GeoJSON polygon data");
                    return $this->processGeoJSONPolygon($alert->geometry->coordinates[0]);
                }
            }
            
            // Check for CAP format polygon as fallback
            if (isset($alert->info->area->polygon)) {
                $this->logError("Found CAP format polygon data");
                return $this->processCAPPolygon((string)$alert->info->area->polygon);
            }
            
            $this->logError("No valid polygon data found");
            return [
                'type' => 'NONE',
                'coordinates' => null,
                'valid' => false
            ];
            
        } catch (Exception $e) {
            $this->logError("Error processing polygon data: " . $e->getMessage());
            return [
                'type' => 'NONE',
                'coordinates' => null,
                'valid' => false
            ];
        }
    }

    /**
     * Processes GeoJSON format polygon coordinates
     * @param array $coordinates Array of coordinate pairs
     * @return array Processed polygon data
     */
    private function processGeoJSONPolygon(array $coordinates): array {
        $processedCoords = [];
        foreach ($coordinates as $point) {
            if (count($point) >= 2) {
                $lon = floatval($point[0]);
                $lat = floatval($point[1]);
                
                if ($this->validateCoordinates($lon, $lat)) {
                    $processedCoords[] = [$lon, $lat];
                } else {
                    $this->logError("Invalid coordinate values: lon=$lon, lat=$lat");
                }
            }
        }
        
        return $this->finalizePolygon($processedCoords);
    }
	/**
     * Processes CAP format polygon string
     * @param string $polygonStr Space-separated lat,lon pairs
     * @return array Processed polygon data
     */
    private function processCAPPolygon(string $polygonStr): array {
        $coordinates = [];
        $points = explode(' ', trim($polygonStr));
        
        foreach ($points as $point) {
            $parts = explode(',', trim($point));
            if (count($parts) === 2) {
                $lat = floatval($parts[0]);
                $lon = floatval($parts[1]);
                
                if ($this->validateCoordinates($lon, $lat)) {
                    $coordinates[] = [$lon, $lat];
                }
            }
        }
        
        return $this->finalizePolygon($coordinates);
    }

    /**
     * Validates coordinate pairs
     * @param float $lon Longitude
     * @param float $lat Latitude
     * @return bool True if coordinates are valid
     */
    private function validateCoordinates(float $lon, float $lat): bool {
        return $lon >= -180 && $lon <= 180 && $lat >= -90 && $lat <= 90;
    }

    /**
     * Finalizes polygon processing by ensuring it's closed and valid
     * @param array $coordinates Array of coordinate pairs
     * @return array Processed polygon data
     */
    private function finalizePolygon(array $coordinates): array {
        // Ensure polygon is closed
        if (count($coordinates) > 2 && 
            ($coordinates[0][0] !== end($coordinates)[0] || 
             $coordinates[0][1] !== end($coordinates)[1])) {
            $coordinates[] = $coordinates[0];
        }
        
        return [
            'type' => 'POLYGON',
            'coordinates' => json_encode($coordinates),
            'valid' => count($coordinates) >= 4  // Minimum 3 points plus closing point
        ];
    }

    /**
     * Fetches alerts from NWS feed
     * @return int Number of processed alerts
     * @throws Exception If alert fetching fails
     */
    public function fetchAlerts(): int {
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

                    if ($this->processAlert($alertXml, $entry)) {
                        $processedCount++;
                        $this->logError("Successfully processed alert: " . $entry->title);
                    }

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

    /**
     * Processes and stores an individual alert
     * @param SimpleXMLElement $alertXml The alert XML data
     * @param SimpleXMLElement $entry The feed entry data
     * @return bool True if processing was successful
     */
    private function processAlert(SimpleXMLElement $alertXml, SimpleXMLElement $entry): bool {
        try {
            // Extract basic alert information
            $alertId = (string)$alertXml->identifier;
            $event = (string)$alertXml->info->event;
            $severity = (string)$alertXml->info->severity;
            $description = (string)$alertXml->info->description;
            $expires = $this->convertToMySQLDateTime((string)$alertXml->info->expires);
            $effective = $this->convertToMySQLDateTime((string)$alertXml->info->effective);
            $ends = isset($alertXml->info->ends) ? 
                    $this->convertToMySQLDateTime((string)$alertXml->info->ends) : null;
            $title = (string)$alertXml->info->headline;

            // Extract additional fields
            $messageType = (string)$alertXml->messageType;
            $category = (string)$alertXml->info->category;
            $certainty = (string)$alertXml->info->certainty;
            $urgency = (string)$alertXml->info->urgency;
            $response = (string)$alertXml->info->response;
            
            // Process geocodes
            $sameCodes = [];
            $ugcCodes = [];
            if (isset($alertXml->info->area->geocode)) {
                foreach ($alertXml->info->area->geocode as $geocode) {
                    if ((string)$geocode->valueName === 'SAME') {
                        $sameCodes[] = (string)$geocode->value;
                    } elseif ((string)$geocode->valueName === 'UGC') {
                        $ugcCodes[] = (string)$geocode->value;
                    }
                }
            }

            // Process polygon data
            $polygonData = $this->processPolygon($alertXml);

            // Check if alert already exists
            $stmt = $this->db->prepare("SELECT id FROM alerts WHERE alert_id = ?");
            $stmt->execute([$alertId]);
            $existingAlert = $stmt->fetch();

            if ($existingAlert) {
                // Update existing alert
                return $this->updateExistingAlert(
                    $alertId,
                    $event,
                    $severity,
                    $description,
                    $expires,
                    $effective,
                    $ends,
                    $title,
                    $messageType,
                    $category,
                    $certainty,
                    $urgency,
                    $response,
                    $sameCodes,
                    $ugcCodes,
                    $polygonData
                );
            } else {
                // Insert new alert
                return $this->insertNewAlert(
                    $alertId,
                    $event,
                    $severity,
                    $description,
                    $expires,
                    $effective,
                    $ends,
                    $title,
                    $messageType,
                    $category,
                    $certainty,
                    $urgency,
                    $response,
                    $sameCodes,
                    $ugcCodes,
                    $polygonData
                );
            }

        } catch (Exception $e) {
            $this->logError("Error processing alert {$alertId}: " . $e->getMessage());
            return false;
        }
    }
	/**
     * Updates an existing alert in the database
     * @param string $alertId The unique identifier of the alert
     * @param array $data The alert data to update
     * @return bool True if update was successful
     */
    private function updateExistingAlert(
        string $alertId,
        string $event,
        string $severity,
        string $description,
        string $expires,
        string $effective,
        ?string $ends,
        string $title,
        string $messageType,
        string $category,
        string $certainty,
        string $urgency,
        string $response,
        array $sameCodes,
        array $ugcCodes,
        array $polygonData
    ): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE alerts SET
                    event_type = ?,
                    severity = ?,
                    description = ?,
                    expires = ?,
                    effective = ?,
                    ends = ?,
                    title = ?,
                    message_type = ?,
                    category = ?,
                    certainty = ?,
                    urgency = ?,
                    response = ?,
                    same_codes = ?,
                    ugc_codes = ?,
                    polygon_type = ?,
                    polygon_coordinates = ?,
                    polygon_valid = ?,
                    updated_at = UTC_TIMESTAMP()
                WHERE alert_id = ?
            ");

            return $stmt->execute([
                $event,
                $severity,
                $description,
                $expires,
                $effective,
                $ends,
                $title,
                $messageType,
                $category,
                $certainty,
                $urgency,
                $response,
                json_encode($sameCodes),
                json_encode($ugcCodes),
                $polygonData['type'],
                $polygonData['coordinates'],
                $polygonData['valid'],
                $alertId
            ]);

        } catch (PDOException $e) {
            $this->logError("Database error updating alert {$alertId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Inserts a new alert into the database
     * @param string $alertId The unique identifier of the alert
     * @param array $data The alert data to insert
     * @return bool True if insertion was successful
     */
    private function insertNewAlert(
        string $alertId,
        string $event,
        string $severity,
        string $description,
        string $expires,
        string $effective,
        ?string $ends,
        string $title,
        string $messageType,
        string $category,
        string $certainty,
        string $urgency,
        string $response,
        array $sameCodes,
        array $ugcCodes,
        array $polygonData
    ): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO alerts (
                    alert_id,
                    event_type,
                    severity,
                    description,
                    expires,
                    effective,
                    ends,
                    title,
                    message_type,
                    category,
                    certainty,
                    urgency,
                    response,
                    same_codes,
                    ugc_codes,
                    polygon_type,
                    polygon_coordinates,
                    polygon_valid,
                    created_at,
                    status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), 'Active'
                )
            ");

            return $stmt->execute([
                $alertId,
                $event,
                $severity,
                $description,
                $expires,
                $effective,
                $ends,
                $title,
                $messageType,
                $category,
                $certainty,
                $urgency,
                $response,
                json_encode($sameCodes),
                json_encode($ugcCodes),
                $polygonData['type'],
                $polygonData['coordinates'],
                $polygonData['valid']
            ]);

        } catch (PDOException $e) {
            $this->logError("Database error inserting alert {$alertId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gets active alerts for Putnam County
     * @param string|null $category Optional category filter
     * @return array Array of active alerts
     */
    public function getActiveAlerts(?string $category = null): array {
        try {
            $sql = "
                SELECT 
                    a.*,
                    CASE 
                        WHEN a.expires < UTC_TIMESTAMP() THEN 'Expired'
                        WHEN a.ends IS NOT NULL AND a.ends < UTC_TIMESTAMP() THEN 'Ended'
                        ELSE 'Active'
                    END as current_status
                FROM alerts a
                WHERE a.status = 'Active'
                AND (a.expires > UTC_TIMESTAMP() OR a.expires IS NULL)
            ";

            if ($category !== null && isset(self::ALERT_CATEGORIES[$category])) {
                $sql .= " AND a.event_type IN (" . 
                    implode(',', array_fill(0, count(self::ALERT_CATEGORIES[$category]), '?')) . 
                    ")";
                $params = self::ALERT_CATEGORIES[$category];
            } else {
                $params = [];
            }

            $sql .= " ORDER BY a.effective DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();

        } catch (PDOException $e) {
            $this->logError("Database error fetching active alerts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verifies and updates the database schema
     * @throws PDOException If schema verification fails
     */
    private function verifyTableSchema(): void {
        try {
            // Check if alerts table exists
            $tableExists = $this->db->query("
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                AND table_name = 'alerts'
            ")->fetchColumn();

            if (!$tableExists) {
                $this->createAlertsTable();
                return;
            }

            // Check for missing columns
            $result = $this->db->query("
                SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                AND table_name = 'alerts'
            ");

            $existingColumns = $result->fetchAll(PDO::FETCH_ASSOC);
            $existingColumnNames = array_column($existingColumns, 'COLUMN_NAME');

            foreach (self::REQUIRED_COLUMNS as $column => $definition) {
                if (!in_array($column, $existingColumnNames)) {
                    $this->logError("Adding missing column: $column");
                    $this->db->exec("ALTER TABLE alerts ADD COLUMN $column $definition");
                }
            }

        } catch (PDOException $e) {
            $this->logError("Schema verification failed: " . $e->getMessage());
            throw $e;
        }
    }
	/**
     * Creates the alerts table with all required columns
     * @throws PDOException If table creation fails
     */
    private function createAlertsTable(): void {
        try {
            $columns = [];
            foreach (self::REQUIRED_COLUMNS as $column => $definition) {
                $columns[] = "$column $definition";
            }

            $sql = "CREATE TABLE alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                " . implode(",\n                ", $columns) . ",
                INDEX idx_alert_id (alert_id),
                INDEX idx_status_dates (status, effective, expires),
                INDEX idx_event_type (event_type),
                INDEX idx_created_at (created_at),
                SPATIAL INDEX idx_polygon (polygon_geometry)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->db->exec($sql);
            $this->logError("Created alerts table successfully");

        } catch (PDOException $e) {
            $this->logError("Failed to create alerts table: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cleans up expired alerts
     * @param int $daysOld Number of days after which to remove expired alerts
     * @return int Number of alerts cleaned up
     */
    public function cleanupExpiredAlerts(int $daysOld = 7): int {
        try {
            $stmt = $this->db->prepare("
                UPDATE alerts 
                SET status = 'Expired'
                WHERE status = 'Active'
                AND (
                    (expires < UTC_TIMESTAMP())
                    OR (ends IS NOT NULL AND ends < UTC_TIMESTAMP())
                )
            ");
            $stmt->execute();
            $updatedCount = $stmt->rowCount();

            // Delete old expired alerts
            $stmt = $this->db->prepare("
                DELETE FROM alerts
                WHERE status = 'Expired'
                AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysOld]);
            $deletedCount = $stmt->rowCount();

            $this->logError("Cleaned up $updatedCount expired and $deletedCount old alerts");
            return $updatedCount + $deletedCount;

        } catch (PDOException $e) {
            $this->logError("Error cleaning up expired alerts: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Gets alert details by ID
     * @param string $alertId The unique identifier of the alert
     * @return array|null Alert details or null if not found
     */
    public function getAlertById(string $alertId): ?array {
        try {
            $stmt = $this->db->prepare("
                SELECT *,
                CASE 
                    WHEN expires < UTC_TIMESTAMP() THEN 'Expired'
                    WHEN ends IS NOT NULL AND ends < UTC_TIMESTAMP() THEN 'Ended'
                    ELSE status
                END as current_status
                FROM alerts 
                WHERE alert_id = ?
            ");
            $stmt->execute([$alertId]);
            $alert = $stmt->fetch();

            if ($alert) {
                // Convert JSON fields back to arrays
                $alert['same_codes'] = json_decode($alert['same_codes'], true) ?? [];
                $alert['ugc_codes'] = json_decode($alert['ugc_codes'], true) ?? [];
                if ($alert['polygon_coordinates']) {
                    $alert['polygon_coordinates'] = json_decode($alert['polygon_coordinates'], true);
                }
                return $alert;
            }

            return null;

        } catch (PDOException $e) {
            $this->logError("Error fetching alert $alertId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Gets alerts by event type
     * @param string $eventType The type of event to filter by
     * @return array Array of matching alerts
     */
    public function getAlertsByEventType(string $eventType): array {
        try {
            $stmt = $this->db->prepare("
                SELECT *,
                CASE 
                    WHEN expires < UTC_TIMESTAMP() THEN 'Expired'
                    WHEN ends IS NOT NULL AND ends < UTC_TIMESTAMP() THEN 'Ended'
                    ELSE status
                END as current_status
                FROM alerts 
                WHERE event_type = ?
                AND status = 'Active'
                ORDER BY effective DESC
            ");
            $stmt->execute([$eventType]);
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            $this->logError("Error fetching alerts for event type $eventType: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Gets alerts within a polygon
     * @param array $coordinates Array of coordinate pairs defining the polygon
     * @return array Array of alerts within the polygon
     */
    public function getAlertsInPolygon(array $coordinates): array {
        if (empty($coordinates) || !$this->isValidPolygon($coordinates)) {
            return [];
        }

        try {
            // Convert coordinates to MySQL polygon format
            $polygonPoints = array_map(function($coord) {
                return implode(' ', $coord);
            }, $coordinates);
            
            $polygonWKT = "POLYGON((" . implode(',', $polygonPoints) . "))";

            $stmt = $this->db->prepare("
                SELECT *,
                CASE 
                    WHEN expires < UTC_TIMESTAMP() THEN 'Expired'
                    WHEN ends IS NOT NULL AND ends < UTC_TIMESTAMP() THEN 'Ended'
                    ELSE status
                END as current_status
                FROM alerts 
                WHERE status = 'Active'
                AND ST_Contains(
                    ST_GeomFromText(?),
                    polygon_geometry
                )
                ORDER BY effective DESC
            ");
            $stmt->execute([$polygonWKT]);
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            $this->logError("Error fetching alerts in polygon: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Validates a polygon's coordinates
     * @param array $coordinates Array of coordinate pairs
     * @return bool True if polygon is valid
     */
    private function isValidPolygon(array $coordinates): bool {
        if (count($coordinates) < 3) {
            return false;
        }

        foreach ($coordinates as $coord) {
            if (!is_array($coord) || count($coord) !== 2) {
                return false;
            }
            if (!$this->validateCoordinates($coord[0], $coord[1])) {
                return false;
            }
        }

        // Check if polygon is closed
        $first = reset($coordinates);
        $last = end($coordinates);
        return $first[0] === $last[0] && $first[1] === $last[1];
    }
}

/**
 * Last Modified: 2025-01-28 19:16:44 UTC
 * Modified By: KR8MER
 */
?>
