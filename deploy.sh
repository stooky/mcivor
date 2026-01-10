#!/bin/bash
#
# C-Can Sam Deployment Script for Ubuntu 24
# Usage: sudo bash deploy.sh
#
# This script will:
# 1. Install required dependencies (Node.js, nginx, certbot)
# 2. Clone/pull the repository
# 3. Build the static site
# 4. Configure nginx with SSL
# 5. Set up firewall rules
#

set -e

# Logging
LOG_FILE="/var/log/ccan-deploy.log"
exec > >(tee -a "$LOG_FILE") 2>&1
echo ""
echo "============================================"
echo "Deployment started at $(date)"
echo "============================================"

# Configuration
REPO_URL="https://github.com/stooky/ccan.git"
BRANCH="storage-containers"
INSTALL_DIR="/var/www/ccan"
NGINX_CONF="/etc/nginx/sites-available/ccan"
NGINX_ENABLED="/etc/nginx/sites-enabled/ccan"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [[ $EUID -ne 0 ]]; then
    log_error "This script must be run as root (use sudo)"
    exit 1
fi

# Get domain name
if [[ -z "$DOMAIN" ]]; then
    read -p "Enter your domain name (e.g., ccansam.com): " DOMAIN
fi

if [[ -z "$DOMAIN" ]]; then
    log_error "Domain name is required"
    exit 1
fi

# Get email for certbot
if [[ -z "$EMAIL" ]]; then
    read -p "Enter email for SSL certificate notifications: " EMAIL
fi

if [[ -z "$EMAIL" ]]; then
    log_error "Email is required for SSL certificate"
    exit 1
fi

log_info "Starting deployment for $DOMAIN"

# ============================================
# Step 1: Update system and install dependencies
# ============================================
log_info "Updating system packages..."
apt-get update
apt-get upgrade -y

# Install required packages
log_info "Installing dependencies..."
apt-get install -y curl git nginx certbot python3-certbot-nginx ufw php-fpm php-yaml php-curl

# Install Node.js 20.x if not installed
if ! command -v node &> /dev/null; then
    log_info "Installing Node.js 20.x..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
else
    NODE_VERSION=$(node -v)
    log_info "Node.js already installed: $NODE_VERSION"
fi

# ============================================
# Step 2: Clone or pull repository
# ============================================
if [[ -d "$INSTALL_DIR/.git" ]]; then
    log_info "Repository exists, pulling latest changes..."
    cd "$INSTALL_DIR"
    git fetch origin
    git checkout "$BRANCH"
    git pull origin "$BRANCH"
else
    log_info "Cloning repository..."
    mkdir -p "$(dirname $INSTALL_DIR)"
    git clone -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
    cd "$INSTALL_DIR"
fi

# ============================================
# Step 3: Install npm dependencies and build
# ============================================
log_info "Installing npm dependencies..."
cd "$INSTALL_DIR"
npm ci --production=false

log_info "Building static site..."
npm run build

# ============================================
# Step 4: Set up data directory for form submissions
# ============================================
log_info "Setting up data directory..."
mkdir -p "$INSTALL_DIR/data"
touch "$INSTALL_DIR/data/submissions.json"
echo "[]" > "$INSTALL_DIR/data/submissions.json"

# Set proper ownership
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod 775 "$INSTALL_DIR/data"
chmod 664 "$INSTALL_DIR/data/submissions.json"
chmod 664 "$INSTALL_DIR/config.yaml"  # Writable by www-data for tag-reviews

# ============================================
# Step 5: Configure nginx
# ============================================
log_info "Configuring nginx..."

# Generate redirects from config.yaml
log_info "Reading redirects from config.yaml..."
REDIRECTS=""
in_redirects=false
while IFS= read -r line; do
    # Check if we're entering the redirects section
    if [[ "$line" =~ ^redirects: ]]; then
        in_redirects=true
        continue
    fi
    # Check if we've left the redirects section (new top-level key)
    if [[ "$in_redirects" == true ]] && [[ "$line" =~ ^[a-z] ]] && [[ ! "$line" =~ ^[[:space:]] ]]; then
        in_redirects=false
        continue
    fi
    # Parse redirect lines (format: "  /old/path/: /new/path/")
    if [[ "$in_redirects" == true ]] && [[ "$line" =~ ^[[:space:]]+(/[^:]*):\ (.+)$ ]]; then
        old_path="${BASH_REMATCH[1]}"
        new_path="${BASH_REMATCH[2]}"
        REDIRECTS+="    location = $old_path { return 301 $new_path; }"$'\n'
    fi
done < "$INSTALL_DIR/config.yaml"
log_info "Loaded $(echo "$REDIRECTS" | grep -c 'location') redirects from config.yaml"

# Create nginx configuration
cat > "$NGINX_CONF" << EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    root $INSTALL_DIR/dist;
    index index.html;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml application/javascript application/json image/svg+xml;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|webp|woff|woff2|ttf|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # PHP API endpoints
    location ~ ^/api/.*\.php$ {
        root $INSTALL_DIR;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block direct access to data directory
    location /data {
        deny all;
        return 404;
    }

    # Block access to config file
    location = /config.yaml {
        deny all;
        return 404;
    }

    # ============================================
    # URL Redirects (from config.yaml)
    # ============================================
$REDIRECTS
    # Handle Astro routes (clean URLs)
    location / {
        try_files \$uri \$uri/ \$uri.html /index.html;
    }

    # Error pages
    error_page 404 /404.html;
    location = /404.html {
        internal;
    }
}
EOF

# Enable the site
ln -sf "$NGINX_CONF" "$NGINX_ENABLED"

# Remove default site if it exists
rm -f /etc/nginx/sites-enabled/default

# Test nginx configuration
log_info "Testing nginx configuration..."
if nginx -t; then
    log_info "Nginx config test passed"
else
    log_error "Nginx config test FAILED"
    cat "$NGINX_CONF"
    exit 1
fi

# ============================================
# Step 5: Configure firewall
# ============================================
log_info "Configuring firewall..."
ufw --force enable
ufw allow ssh
ufw allow 'Nginx Full'
ufw status

# ============================================
# Step 6: Set up SSL with Certbot
# ============================================
log_info "Checking SSL certificate..."

# Check if certificate already exists
if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
    # Get certificate expiry date
    CERT_EXPIRY=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$DOMAIN/cert.pem" 2>/dev/null | cut -d= -f2)
    log_info "SSL certificate already exists for $DOMAIN"
    echo "  Expires: $CERT_EXPIRY"
    echo ""
    read -p "Do you want to recreate the SSL certificate? (y/N): " RECREATE_SSL
    if [[ "$RECREATE_SSL" =~ ^[Yy]$ ]]; then
        log_info "Recreating SSL certificate..."
        certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "$EMAIL" --redirect --force-renewal
    else
        log_info "Keeping existing SSL certificate"
    fi
else
    log_info "No SSL certificate found, creating one..."
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --email "$EMAIL" --redirect; then
        log_info "SSL certificate created successfully"
    else
        log_error "SSL certificate creation FAILED"
        log_warn "Site will be available on HTTP only. Common causes:"
        echo "  - Domain DNS not pointing to this server"
        echo "  - Using Cloudflare proxy (use Cloudflare SSL instead)"
        echo "  - Firewall blocking port 80"
        echo ""
        echo "Check: /var/log/letsencrypt/letsencrypt.log"
    fi
fi

# ============================================
# Step 8: Start/restart services
# ============================================
log_info "Starting services..."
systemctl enable nginx

# Find and enable PHP-FPM (version varies by distro)
PHP_FPM_SERVICE=$(systemctl list-unit-files | grep -o 'php[0-9.]*-fpm.service' | head -1)
if [[ -n "$PHP_FPM_SERVICE" ]]; then
    log_info "Found PHP-FPM service: $PHP_FPM_SERVICE"
    systemctl enable "$PHP_FPM_SERVICE"
    systemctl restart "$PHP_FPM_SERVICE"
else
    log_warn "PHP-FPM service not found, trying common names..."
    systemctl enable php8.3-fpm 2>/dev/null || systemctl enable php8.1-fpm 2>/dev/null || true
    systemctl restart php8.3-fpm 2>/dev/null || systemctl restart php8.1-fpm 2>/dev/null || true
fi

systemctl restart nginx

# ============================================
# Step 9: Set up auto-renewal for SSL
# ============================================
log_info "Setting up SSL auto-renewal..."
systemctl enable certbot.timer
systemctl start certbot.timer

# ============================================
# Step 10: Verify deployment
# ============================================
log_info "Verifying deployment..."
echo ""

# Test HTTP response
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost)
if [[ "$HTTP_STATUS" == "200" ]]; then
    log_info "HTTP check: OK (status $HTTP_STATUS)"
else
    log_error "HTTP check: FAILED (status $HTTP_STATUS)"
fi

# Test HTTPS if cert exists
if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
    HTTPS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -k https://localhost 2>/dev/null || echo "000")
    if [[ "$HTTPS_STATUS" == "200" ]]; then
        log_info "HTTPS check: OK (status $HTTPS_STATUS)"
    else
        log_warn "HTTPS check: status $HTTPS_STATUS (may need nginx reload)"
    fi
fi

# Test PHP-FPM
PHP_TEST=$(curl -s -X POST http://localhost/api/contact.php -H "Content-Type: application/json" -d '{"test":true}' 2>/dev/null)
if [[ "$PHP_TEST" == *"error"* ]] || [[ "$PHP_TEST" == *"success"* ]]; then
    log_info "PHP-FPM check: OK"
else
    log_warn "PHP-FPM check: May need attention"
    echo "  Response: $PHP_TEST"
fi

# Show listening ports
echo ""
log_info "Services listening:"
ss -tlnp | grep -E ':(80|443)\s' | head -5

# ============================================
# Done!
# ============================================
echo ""
echo "========================================"
log_info "Deployment complete at $(date)"
echo "========================================"
echo ""
echo "Your site should now be available at:"
echo "  http://$DOMAIN"
if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
    echo "  https://$DOMAIN"
fi
echo ""
echo "Admin panel:"
echo "  https://$DOMAIN/api/admin.php?key=YOUR_SECRET_KEY"
echo "  (Change secret_path in config.yaml)"
echo ""
echo "Logs:"
echo "  - Deploy log:         cat $LOG_FILE"
echo "  - Nginx access:       tail -f /var/log/nginx/access.log"
echo "  - Nginx errors:       tail -f /var/log/nginx/error.log"
echo "  - Certbot:            cat /var/log/letsencrypt/letsencrypt.log"
echo ""
echo "Commands:"
echo "  - Restart nginx:      systemctl restart nginx"
echo "  - Update site:        cd $INSTALL_DIR && sudo bash update.sh"
echo "  - View submissions:   cat $INSTALL_DIR/data/submissions.json"
echo ""
