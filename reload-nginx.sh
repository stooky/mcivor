#!/bin/bash
#
# Reload Nginx Redirects
# Usage: sudo bash reload-nginx.sh
#
# Updates ONLY the redirects section in nginx config from config.yaml.
# Preserves SSL and all other settings added by Certbot.
#

set -e

INSTALL_DIR="/var/www/ccan"
NGINX_CONF="/etc/nginx/sites-available/ccan"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check if running as root
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} This script must be run as root (use sudo)"
    exit 1
fi

echo -e "${GREEN}[INFO]${NC} Updating nginx redirects from config.yaml..."

# Generate redirects from config.yaml
REDIRECTS=""
in_redirects=false
while IFS= read -r line; do
    if [[ "$line" =~ ^redirects: ]]; then
        in_redirects=true
        continue
    fi
    if [[ "$in_redirects" == true ]] && [[ "$line" =~ ^[a-z] ]] && [[ ! "$line" =~ ^[[:space:]] ]]; then
        in_redirects=false
        continue
    fi
    if [[ "$in_redirects" == true ]] && [[ "$line" =~ ^[[:space:]]+(/[^:]*):\ (.+)$ ]]; then
        old_path="${BASH_REMATCH[1]}"
        new_path="${BASH_REMATCH[2]}"
        REDIRECTS+="    location = $old_path { return 301 $new_path; }\n"
    fi
done < "$INSTALL_DIR/config.yaml"

redirect_count=$(echo -e "$REDIRECTS" | grep -c 'location' || echo "0")
echo -e "${GREEN}[INFO]${NC} Loaded $redirect_count redirects from config.yaml"

# Backup existing config
cp "$NGINX_CONF" "$NGINX_CONF.bak"
echo -e "${GREEN}[INFO]${NC} Backed up config to $NGINX_CONF.bak"

# Create a temp file with updated redirects
TEMP_CONF=$(mktemp)

# Use awk to replace only the redirects section
awk -v redirects="$REDIRECTS" '
    /# URL Redirects/ {
        print $0
        print "    # ============================================"
        printf "%s", redirects
        # Skip until we hit the next section
        while (getline && !/# Handle Astro routes/) {}
        print ""
        print $0
        next
    }
    { print }
' "$NGINX_CONF" > "$TEMP_CONF"

# Check if the replacement worked
if grep -q "location = /homepage/" "$TEMP_CONF"; then
    mv "$TEMP_CONF" "$NGINX_CONF"
else
    echo -e "${YELLOW}[WARN]${NC} Could not find redirects section - config may have different format"
    echo -e "${YELLOW}[WARN]${NC} Restoring backup and exiting"
    rm -f "$TEMP_CONF"
    cp "$NGINX_CONF.bak" "$NGINX_CONF"
    exit 1
fi

# Test nginx configuration
echo -e "${GREEN}[INFO]${NC} Testing nginx configuration..."
if nginx -t 2>&1; then
    echo -e "${GREEN}[INFO]${NC} Nginx config test passed"

    # Reload nginx
    systemctl reload nginx

    echo ""
    echo -e "${GREEN}[INFO]${NC} Nginx reloaded successfully!"
    echo "  Redirects updated: $redirect_count"
else
    echo -e "${RED}[ERROR]${NC} Nginx config test FAILED - restoring backup"
    cp "$NGINX_CONF.bak" "$NGINX_CONF"
    systemctl reload nginx
    exit 1
fi
