#!/bin/bash
# Ensure /data directory has correct ownership for www-data
# This runs at container startup to fix volume permissions

if [ -d "/data" ]; then
    chown -R www-data:www-data /data
    chmod -R 755 /data
fi

# Start Apache
exec apache2-foreground
