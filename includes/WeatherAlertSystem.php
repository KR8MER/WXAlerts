<?php
/**
 * WeatherAlertSystem.php
 *
 * Weather Alert System for Putnam County, Ohio
 * This class handles fetching, processing, and managing weather alerts
 * from the National Weather Service CAP feeds.
 *
 * @package WeatherAlerts
 * @author Your Name
 * @version 1.1
 */

declare(strict_types=1);

if (!defined('APP_PATH')) {
    exit('Direct script access denied.');
}

class WeatherAlertSystem {
    private $db;
    private $cache = [];
    
    // Core configuration constants
    private const PUTNAM_FIPS = '039137'; // FIPS code for Putnam County, Ohio
    private const NWS_CAP_FEED = 'https://alerts.weather.gov/cap/oh.php?x=0';
    private const USER_AGENT = 'PutnamCountyAlertSystem/1.0 (your@email.com)';
    private const CACHE_DURATION = 300; // 5 minutes in seconds
    
    // Alert categorization system
    private const ALERT_CATEGORIES = [
        'SEVERE' => ['Tornado Warning', 'Severe Thunderstorm Warning', 'Flash Flood Warning'],
        'WINTER' => ['Winter Storm Warning', 'Ice Storm Warning', 'Blizzard Warning', 'Winter Weather Advisory'],
        'FLOOD' => ['Flood Warning', 'Flood Watch', 'Flood Advisory'],
        'HEAT' => ['Excessive Heat Warning', 'Heat Advisory'],
        'WIND' => ['High Wind Warning', 'Wind Advisory'],
        'OTHER' => []
    ];

    public function __construct() {
        $config = require __DIR__ . '/../config/database.php';
        
        try {
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
        } catch(PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
public function getDb() {
        return $this->db;
    }

    private function convertToMySQLDateTime($dateStr) {
        try {
            $date = new DateTime($dateStr);
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log("Date conversion error for: " . $dateStr . " - " . $e->getMessage());
            throw $e;
        }
    }

    private function fetchXMLWithCurl($url) {
        $urlString = (string)$url;
        $cacheKey = md5($urlString);
        
        if (isset($this->cache[$cacheKey]) && 
            (time() - $this->cache[$cacheKey]['time'] < self::CACHE_DURATION)) {
            error_log("Using cached data for URL: " . $urlString);
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
        
        error_log("Fetching data from URL: " . $urlString);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log("cURL Error fetching data: " . $error);
            throw new Exception("cURL Error: $error");
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("HTTP Error fetching data: " . $httpCode);
            throw new Exception("HTTP Error: $httpCode");
        }
        
        $xml = simplexml_load_string($response);
        if (!$xml) {
            error_log("Failed to parse XML response");
            throw new Exception("Failed to parse XML response");
        }
        
        $this->cache[$cacheKey] = [
            'time' => time(),
            'data' => $xml
        ];
        
        return $xml;
    }

    private function isForPutnamCounty($cap) {
        error_log("Checking if alert is for Putnam County...");
        error_log("Looking for FIPS code: " . self::PUTNAM_FIPS);
        
        $info = $cap->info;
        foreach ($info->area->geocode as $geocode) {
            error_log("Found geocode - valueName: " . $geocode->valueName . ", value: " . $geocode->value);
            if ((string)$geocode->valueName === 'SAME' && 
                (string)$geocode->value === self::PUTNAM_FIPS) {
                error_log("Match found! Alert is for Putnam County");
                return true;
            }
        }
        
        error_log("No match found - alert is not for Putnam County");
        return false;
    }
private function processPolygon($cap) {
        $info = $cap->info;
        
        if (isset($info->area->polygon)) {
            return [
                'type' => 'POLYGON',
                'coordinates' => (string)$info->area->polygon
            ];
        }
        
        if (isset($info->area->circle)) {
            return [
                'type' => 'CIRCLE',
                'coordinates' => (string)$info->area->circle
            ];
        }
        
        return [
            'type' => 'NONE',
            'coordinates' => null
        ];
    }

    public function fetchAlerts() {
        try {
            error_log("Starting to fetch alerts from NWS feed");
            $xml = $this->fetchXMLWithCurl(self::NWS_CAP_FEED);
            error_log("Successfully fetched XML feed. Processing entries...");
            
            $processedCount = 0;
            
            foreach ($xml->entry as $entry) {
                try {
                    error_log("Processing alert: " . $entry->title);
                    $alertXml = $this->fetchXMLWithCurl($entry->link['href']);
                    
                    if (!$this->isForPutnamCounty($alertXml)) {
                        error_log("Alert skipped - not for Putnam County: " . $entry->title);
                        continue;
                    }
                    
                    $this->processAlert($alertXml, $entry);
                    $processedCount++;
                    error_log("Successfully processed alert: " . $entry->title);
                    
                    usleep(100000); // 100ms delay between requests
                    
                } catch (Exception $e) {
                    error_log("Error processing individual alert: " . $e->getMessage());
                    continue;
                }
            }
            
            error_log("Processing complete. Processed $processedCount alerts");
            return $processedCount;
            
        } catch (Exception $e) {
            error_log("Error fetching alerts: " . $e->getMessage());
            throw $e;
        }
    }

    private function getAffectedDistricts($polygon) {
        $districts = [
            'fire' => [],
            'ems' => [],
            'electric' => []
        ];
        
        // Parse polygon coordinates
        $coordinates = [];
        $points = explode(' ', trim($polygon));
        foreach ($points as $point) {
            $parts = explode(',', trim($point));
            if (count($parts) === 2) {
                $coordinates[] = [
                    'lat' => floatval($parts[0]),
                    'lon' => floatval($parts[1])
                ];
            }
        }
        
        // Load district GeoJSON files
        try {
            $fireData = json_decode(file_get_contents(__DIR__ . '/../data/boundaries/fire_districts.json'), true);
            $emsData = json_decode(file_get_contents(__DIR__ . '/../data/boundaries/ems_districts.json'), true);
            $electricData = json_decode(file_get_contents(__DIR__ . '/../data/boundaries/electric_providers.json'), true);
            
            // Check each coordinate against district polygons
            foreach ($coordinates as $coord) {
                // Check Fire Districts
                foreach ($fireData['features'] as $feature) {
                    if ($this->pointInPolygon($coord, $feature['geometry']['coordinates'][0])) {
                        $districts['fire'][] = $feature['properties']['DEPT'];
                    }
                }
                
                // Check EMS Districts
                foreach ($emsData['features'] as $feature) {
                    if ($this->pointInPolygon($coord, $feature['geometry']['coordinates'][0])) {
                        $districts['ems'][] = $feature['properties']['DEPT'];
                    }
                }
                
                // Check Electric Providers
                foreach ($electricData['features'] as $feature) {
                    if ($this->pointInPolygon($coord, $feature['geometry']['coordinates'][0])) {
                        $districts['electric'][] = $feature['properties']['COMPNAME'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error loading district data: " . $e->getMessage());
        }
        
        // Remove duplicates
        $districts['fire'] = array_unique($districts['fire']);
        $districts['ems'] = array_unique($districts['ems']);
        $districts['electric'] = array_unique($districts['electric']);
        
        return $districts;
    }
private function pointInPolygon($point, $polygon) {
        $c = false;
        $nvert = count($polygon);
        $j = $nvert - 1;
        
        for ($i = 0; $i < $nvert; $i++) {
            if ((($polygon[$i][1] > $point['lon']) != ($polygon[$j][1] > $point['lon'])) &&
                ($point['lat'] < ($polygon[$j][0] - $polygon[$i][0]) * ($point['lon'] - $polygon[$i][1]) /
                ($polygon[$j][1] - $polygon[$i][1]) + $polygon[$i][0])) {
                $c = !$c;
            }
            $j = $i;
        }
        
        return $c;
    }

    private function processAlert($cap, $entry) {
        $info = $cap->info;
        $polygon = $this->processPolygon($cap);
        $alertId = (string)$entry->id;
        
        try {
            error_log("Processing alert: " . (string)$info->headline . " (ID: $alertId)");
            
            // Convert dates to MySQL format
            $effectiveDate = $this->convertToMySQLDateTime((string)$info->effective);
            $expiresDate = $this->convertToMySQLDateTime((string)$info->expires);
            $endsDate = isset($info->ends) ? $this->convertToMySQLDateTime((string)$info->ends) : $expiresDate;
            
            // Check if alert exists and needs update
            $stmt = $this->db->prepare("
                SELECT id, effective, ends 
                FROM alerts 
                WHERE alert_id = ?
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            
            $stmt->execute([$alertId]);
            $existingAlert = $stmt->fetch();
            
            if ($existingAlert) {
                // Compare dates to see if this is an update
                if ($effectiveDate != $existingAlert['effective'] || 
                    $endsDate != $existingAlert['ends']) {
                    
                    // Update existing alert
                    $stmt = $this->db->prepare("
                        UPDATE alerts SET
                            title = ?,
                            description = ?,
                            polygon = ?,
                            polygon_type = ?,
                            severity = ?,
                            urgency = ?,
                            certainty = ?,
                            event_type = ?,
                            effective = ?,
                            expires = ?,
                            ends = ?,
                            status = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        (string)$info->headline,
                        (string)$info->description,
                        $polygon['coordinates'],
                        $polygon['type'],
                        (string)$info->severity,
                        (string)$info->urgency,
                        (string)$info->certainty,
                        (string)$info->event,
                        $effectiveDate,
                        $expiresDate,
                        $endsDate,
                        (string)$cap->status,
                        $existingAlert['id']
                    ]);
                    
                    error_log("Alert updated successfully");
                } else {
                    error_log("Alert exists and is current - no update needed");
                }
            } else {
                // Insert new alert
                $stmt = $this->db->prepare("
                    INSERT INTO alerts 
                    (alert_id, title, description, polygon, polygon_type, severity, 
                    urgency, certainty, event_type, effective, expires, ends, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $alertId,
                    (string)$info->headline,
                    (string)$info->description,
                    $polygon['coordinates'],
                    $polygon['type'],
                    (string)$info->severity,
                    (string)$info->urgency,
                    (string)$info->certainty,
                    (string)$info->event,
                    $effectiveDate,
                    $expiresDate,
                    $endsDate,
                    (string)$cap->status
                ]);
                
                error_log("New alert saved successfully");
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Error processing alert: " . $e->getMessage());
            throw $e;
        }
    }
public function getActiveAlerts() {
        try {
            error_log("Fetching active alerts");
            $stmt = $this->db->prepare("
                SELECT a.* 
                FROM alerts a
                INNER JOIN (
                    SELECT event_type, MAX(id) as latest_id
                    FROM alerts
                    WHERE ends > NOW()
                    GROUP BY event_type
                ) latest ON a.id = latest.latest_id
                ORDER BY a.created_at DESC
            ");
            
            $stmt->execute();
            $alerts = $stmt->fetchAll();
            
            // Add district information for each alert
            foreach ($alerts as &$alert) {
                if ($alert['polygon_type'] === 'NONE') {
                    $alert['coverage'] = 'County-Wide';
                    $alert['districts'] = [
                        'fire' => ['All Fire Districts'],
                        'ems' => ['All EMS Districts'],
                        'electric' => ['All Electric Providers']
                    ];
                } else {
                    $alert['coverage'] = 'Specific Area';
                    $alert['districts'] = $this->getAffectedDistricts($alert['polygon']);
                }
            }
            
            error_log("Found " . count($alerts) . " active alerts");
            return $alerts;
        } catch (PDOException $e) {
            error_log("Error fetching active alerts: " . $e->getMessage());
            return [];
        }
    }

    public function getDistrictCoverage() {
        $coverage = [
            'fire' => ['count' => 0, 'area' => 0],
            'ems' => ['count' => 0, 'area' => 0],
            'electric' => ['count' => 0, 'area' => 0]
        ];

        // Process fire districts
        try {
            $firePath = __DIR__ . '/../data/boundaries/fire_districts.json';
            if (file_exists($firePath)) {
                $fireData = json_decode(file_get_contents($firePath), true);
                if ($fireData && isset($fireData['features'])) {
                    $coverage['fire']['count'] = count($fireData['features']);
                    foreach ($fireData['features'] as $feature) {
                        if (isset($feature['properties']['Shape_Area'])) {
                            // Convert from sq ft to sq miles
                            $coverage['fire']['area'] += ($feature['properties']['Shape_Area'] / 43560) / 640;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error processing fire districts: " . $e->getMessage());
        }

        // Process EMS districts
        try {
            $emsPath = __DIR__ . '/../data/boundaries/ems_districts.json';
            if (file_exists($emsPath)) {
                $emsData = json_decode(file_get_contents($emsPath), true);
                if ($emsData && isset($emsData['features'])) {
                    $coverage['ems']['count'] = count($emsData['features']);
                    foreach ($emsData['features'] as $feature) {
                        if (isset($feature['properties']['Shape_Area'])) {
                            // Convert from sq ft to sq miles
                            $coverage['ems']['area'] += ($feature['properties']['Shape_Area'] / 43560) / 640;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error processing EMS districts: " . $e->getMessage());
        }
// Process electric providers
        try {
            $electricPath = __DIR__ . '/../data/boundaries/electric_providers.json';
            if (file_exists($electricPath)) {
                $electricData = json_decode(file_get_contents($electricPath), true);
                if ($electricData && isset($electricData['features'])) {
                    $coverage['electric']['count'] = count($electricData['features']);
                    foreach ($electricData['features'] as $feature) {
                        if (isset($feature['properties']['area_sqmi'])) {
                            $coverage['electric']['area'] += $feature['properties']['area_sqmi'];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error processing electric providers: " . $e->getMessage());
        }

        return $coverage;
    }

    public function getAlertStats() {
        try {
            $stats = [];
            
            // Get active alerts
            $stats['active'] = count($this->getActiveAlerts());
            
            // Get last 24 hours stats
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
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Error getting alert stats: " . $e->getMessage());
            return [
                'active' => 0,
                'last24h' => [
                    'total_alerts' => 0,
                    'unique_types' => 0,
                    'extreme_alerts' => 0,
                    'severe_alerts' => 0,
                    'latest_alert' => null
                ]
            ];
        }
    }

    public function testConnection() {
        try {
            error_log("Testing connection to NWS feed...");
            $xml = $this->fetchXMLWithCurl(self::NWS_CAP_FEED);
            error_log("Connection test successful. Found " . count($xml->entry) . " entries");
            return [
                'success' => true,
                'message' => 'Successfully connected to NWS feed',
                'entry_count' => count($xml->entry)
            ];
        } catch (Exception $e) {
            error_log("Connection test failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'entry_count' => 0
            ];
        }
    }
}