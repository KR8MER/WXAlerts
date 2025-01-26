#!/bin/bash
# fix_permissions.sh

# Set the base path
BASE_PATH="/var/www/html/weewx/saratoga/weather-alerts"

# Create data directory if it doesn't exist
mkdir -p "$BASE_PATH/data"

# Set directory permissions
find "$BASE_PATH" -type d -exec chmod 755 {} \;

# Set file permissions
find "$BASE_PATH" -type f -exec chmod 644 {} \;

# Set ownership
chown -R www-data:www-data "$BASE_PATH"

# Specific check for boundary file
BOUNDARY_FILE="$BASE_PATH/data/putnam_county_boundary.json"
if [ -f "$BOUNDARY_FILE" ]; then
    chmod 644 "$BOUNDARY_FILE"
    chown www-data:www-data "$BOUNDARY_FILE"
    echo "Boundary file permissions set"
else
    echo "Warning: Boundary file not found at $BOUNDARY_FILE"
fi

echo "Permissions fixed"