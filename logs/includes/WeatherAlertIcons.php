<?php
/**
 * WeatherAlertIcons.php
 * Provides icon mappings and helper functions for weather alert types
 * Last Modified: 2025-01-27 21:52:36 UTC
 * Modified By: KR8MER
 */

class WeatherAlertIcons {
    /** All alert categories with their respective alerts */
    private static $categories = [
        'SEVERE' => [
            'Tornado Warning',
            'Severe Thunderstorm Warning',
            'Flash Flood Warning',
            'Severe Weather Statement'
        ],
        'WINTER' => [
            'Winter Storm Warning',
            'Ice Storm Warning',
            'Blizzard Warning',
            'Winter Weather Advisory',
            'Winter Storm Watch',
            'Freezing Rain Advisory',
            'Snow Squall Warning',
            'Lake Effect Snow Warning',
            'Lake Effect Snow Advisory',
            'Freeze Warning',
            'Freeze Watch'
        ],
        'FLOOD' => [
            'Flood Warning',
            'Flood Watch',
            'Flood Advisory',
            'Flash Flood Watch',
            'Flash Flood Statement',
            'River Flood Warning',
            'River Flood Watch',
            'Coastal Flood Warning',
            'Coastal Flood Watch',
            'Coastal Flood Advisory'
        ],
        'HEAT' => [
            'Excessive Heat Warning',
            'Excessive Heat Watch',
            'Heat Advisory'
        ],
        'WIND' => [
            'High Wind Warning',
            'High Wind Watch',
            'Wind Advisory',
            'Lake Wind Advisory',
            'Gale Warning',
            'Wind Chill Warning',
            'Wind Chill Watch',
            'Wind Chill Advisory'
        ],
        'FIRE' => [
            'Red Flag Warning',
            'Fire Weather Watch',
            'Fire Warning',
            'Extreme Fire Danger'
        ],
        'MARINE' => [
            'Small Craft Advisory',
            'Storm Warning',
            'Special Marine Warning',
            'Hurricane Force Wind Warning',
            'Hurricane Warning',
            'Tropical Storm Warning',
            'Tropical Storm Watch',
            'Hurricane Watch'
        ],
        'FOG' => [
            'Dense Fog Advisory',
            'Dense Smoke Advisory'
        ],
        'SPECIAL' => [
            'Special Weather Statement',
            'Short Term Forecast',
            'Hazardous Weather Outlook',
            'Local Area Emergency'
        ],
        'ADVISORY' => [
            'Air Quality Alert',
            'Air Stagnation Advisory',
            'Ashfall Advisory',
            'Beach Hazards Statement',
            'Coastal Advisory'
        ],
        'OTHER' => [] // Catch-all for undefined events
    ];

    /** Icon mappings for each category */
    private static $categoryIcons = [
        'SEVERE' => 'severe',
        'WINTER' => 'winter',
        'FLOOD' => 'flood',
        'HEAT' => 'heat',
        'WIND' => 'wind',
        'FIRE' => 'fire',
        'MARINE' => 'marine',
        'FOG' => 'fog',
        'SPECIAL' => 'special',
        'ADVISORY' => 'advisory',
        'OTHER' => 'default'
    ];

    /** Icon mappings for specific alerts */
    private static $alertIcons = [
        // SEVERE Category
        'Tornado Warning' => 'tornado',
        'Severe Thunderstorm Warning' => 'thunderstorm',
        'Flash Flood Warning' => 'flash-flood',
        
        // WINTER Category
        'Winter Storm Warning' => 'winter-storm',
        'Ice Storm Warning' => 'ice',
        'Blizzard Warning' => 'blizzard',
        'Snow Squall Warning' => 'snow',
        
        // FLOOD Category
        'Flood Warning' => 'flood',
        'Flash Flood Watch' => 'flood-watch',
        
        // HEAT Category
        'Excessive Heat Warning' => 'heat',
        'Heat Advisory' => 'heat-advisory',
        
        // WIND Category
        'High Wind Warning' => 'wind',
        'Wind Advisory' => 'wind-advisory',
        
        // FIRE Category
        'Red Flag Warning' => 'fire',
        'Fire Weather Watch' => 'fire-watch',
        
        // MARINE Category
        'Hurricane Warning' => 'hurricane',
        'Tropical Storm Warning' => 'tropical-storm',
        
        // FOG Category
        'Dense Fog Advisory' => 'fog',
        'Dense Smoke Advisory' => 'smoke'
    ];

    /**
     * Get the icon ID for a specific weather event
     * @param string $eventType The type of weather event
     * @return string The icon ID to use
     */
    public static function getIconId($eventType) {
        // First try specific alert icon
        if (isset(self::$alertIcons[trim($eventType)])) {
            return self::$alertIcons[trim($eventType)];
        }

        // Fall back to category icon
        $category = self::getCategory($eventType);
        if (isset(self::$categoryIcons[$category])) {
            return self::$categoryIcons[$category];
        }

        // Default fallback
        return 'default';
    }

    /**
     * Get the category for a specific event type
     * @param string $eventType The type of weather event
     * @return string The category name
     */
    public static function getCategory($eventType) {
        foreach (self::$categories as $category => $events) {
            if (in_array($eventType, $events)) {
                return $category;
            }
        }
        return 'OTHER';
    }

    /**
     * Get the severity class for styling
     * @param string $severity The severity level
     * @param string $eventType The type of event
     * @return string The CSS class to apply
     */
    public static function getSeverityClass($severity, $eventType = '') {
        $severity = strtolower($severity);
        
        // Force severe class for warnings
        if (stripos($eventType, 'warning') !== false) {
            return 'severe-severity';
        }

        return match($severity) {
            'extreme' => 'extreme-severity',
            'severe' => 'severe-severity',
            'moderate' => 'moderate-severity',
            'minor' => 'minor-severity',
            default => 'default-severity'
        };
    }

    /**
     * Generate HTML for an alert icon
     * @param string $eventType The type of weather event
     * @param string $severity The severity level
     * @return string The HTML for the icon
     */
    public static function generateIconHTML($eventType, $severity) {
        $iconId = self::getIconId($eventType);
        $severityClass = self::getSeverityClass($severity, $eventType);
        $escapedEvent = htmlspecialchars($eventType);

        return sprintf(
            '<div class="weather-icon-wrapper %s" title="%s">' .
            '<svg class="weather-icon"><use href="#%s"/></svg>' .
            '</div>',
            $severityClass,
            $escapedEvent,
            $iconId
        );
    }

    /**
     * Get all available icon IDs
     * @return array List of all available icon IDs
     */
    public static function getAllIconIds() {
        return array_merge(
            array_values(self::$alertIcons),
            array_values(self::$categoryIcons)
        );
    }

    /**
     * Check if an icon exists for the given event type
     * @param string $eventType The type of weather event
     * @return bool True if an icon exists
     */
    public static function hasIcon($eventType) {
        return isset(self::$alertIcons[trim($eventType)]) || 
               isset(self::$categoryIcons[self::getCategory($eventType)]);
    }

    /**
     * Get CSS classes for styling based on event type
     * @param string $eventType The type of weather event
     * @param string $severity The severity level
     * @return array Array of CSS classes to apply
     */
    public static function getStyleClasses($eventType, $severity) {
        $classes = ['weather-alert-icon'];
        $classes[] = self::getSeverityClass($severity, $eventType);
        $classes[] = 'icon-' . self::getIconId($eventType);
        $classes[] = 'category-' . strtolower(self::getCategory($eventType));
        
        return $classes;
    }

    /**
     * Get description for an icon
     * @param string $eventType The type of weather event
     * @return string Description of the icon/alert
     */
    public static function getIconDescription($eventType) {
        $category = self::getCategory($eventType);
        return sprintf(
            '%s - %s Category Alert',
            $eventType,
            $category
        );
    }
}

// Example usage:
/*
echo WeatherAlertIcons::generateIconHTML('Tornado Warning', 'extreme');
echo WeatherAlertIcons::generateIconHTML('Winter Storm Warning', 'severe');
echo WeatherAlertIcons::generateIconHTML('Heat Advisory', 'moderate');
*/