<?php
/**
 * WeatherIcons.php
 * Handles mapping of CAP event codes to appropriate icons
 */
class WeatherIcons {
    // Event code to icon mapping
    private const EVENT_ICONS = [
        // Tornado events
        'Tornado Warning' => 'tornado',
        'Tornado Watch' => 'tornado',
        'Tornado Emergency' => 'tornado',
        
        // Thunderstorm events
        'Severe Thunderstorm Warning' => 'thunderstorm',
        'Severe Thunderstorm Watch' => 'thunderstorm',
        'Special Marine Warning' => 'thunderstorm',
        
        // Winter events
        'Winter Storm Warning' => 'winter',
        'Winter Storm Watch' => 'winter',
        'Winter Weather Advisory' => 'winter',
        'Ice Storm Warning' => 'winter',
        'Blizzard Warning' => 'winter',
        'Snow Squall Warning' => 'winter',
        
        // Flood events
        'Flood Warning' => 'flood',
        'Flash Flood Warning' => 'flood',
        'Flood Watch' => 'flood',
        'Flash Flood Watch' => 'flood',
        'Coastal Flood Warning' => 'flood',
        'Coastal Flood Watch' => 'flood',
        
        // Heat events
        'Excessive Heat Warning' => 'heat',
        'Heat Advisory' => 'heat',
        'Excessive Heat Watch' => 'heat',
        
        // Wind events
        'High Wind Warning' => 'wind',
        'Wind Advisory' => 'wind',
        'High Wind Watch' => 'wind',
        'Extreme Wind Warning' => 'wind',
        
        // Freezing Rain events
        'Freezing Rain Advisory' => 'freezing-rain',
        'Ice Storm Warning' => 'freezing-rain',
        'Freezing Fog Advisory' => 'freezing-rain',
        
        // Fog events
        'Dense Fog Advisory' => 'fog',
        'Dense Smoke Advisory' => 'fog',
        
        // Hurricane events
        'Hurricane Warning' => 'hurricane',
        'Hurricane Watch' => 'hurricane',
        'Hurricane Force Wind Warning' => 'hurricane',
        'Tropical Storm Warning' => 'hurricane',
        'Tropical Storm Watch' => 'hurricane',
        
        // Fire Weather events
        'Fire Weather Warning' => 'fire',
        'Fire Warning' => 'fire',
        'Red Flag Warning' => 'fire',
        
        // Frost events
        'Frost Advisory' => 'frost',
        'Freeze Warning' => 'frost',
        'Freeze Watch' => 'frost',
        'Hard Freeze Warning' => 'frost',
        
        // Dust events
        'Dust Storm Warning' => 'dust',
        'Blowing Dust Advisory' => 'dust',
        
        // Avalanche events
        'Avalanche Warning' => 'avalanche',
        'Avalanche Watch' => 'avalanche',
        'Avalanche Advisory' => 'avalanche',
        
        // Tsunami events
        'Tsunami Warning' => 'tsunami',
        'Tsunami Watch' => 'tsunami',
        'Tsunami Advisory' => 'tsunami',
        
        // Volcanic events
        'Volcanic Ash Advisory' => 'volcanic',
        'Ashfall Warning' => 'volcanic',
        'Ashfall Advisory' => 'volcanic'
    ];

    // Severity-based colors
    private const SEVERITY_COLORS = [
        'Extreme' => '#dc3545',    // Red
        'Severe' => '#ffc107',     // Yellow
        'Moderate' => '#17a2b8',   // Cyan
        'Minor' => '#6c757d',      // Gray
        'Unknown' => '#6c757d'     // Gray
    ];

    /**
     * Gets the appropriate icon ID for a given event type
     */
    public static function getIconId($eventType): string {
        return self::EVENT_ICONS[$eventType] ?? 'default';
    }

    /**
     * Gets the color for a given severity level
     */
    public static function getSeverityColor($severity): string {
        return self::SEVERITY_COLORS[$severity] ?? self::SEVERITY_COLORS['Unknown'];
    }

    /**
     * Generates an SVG icon for the given event and severity
     */
    public static function generateIcon($eventType, $severity, $size = 40): string {
        $iconId = self::getIconId($eventType);
        $color = self::getSeverityColor($severity);
        
        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 60 60" style="color: {$color}">
            <use href="#{$iconId}" transform="translate(0,0)" />
        </svg>
        SVG;
    }
}