#!/bin/bash
#
# C-Can Sam Update Script
# Usage: sudo bash update.sh
#
# Pulls latest changes and rebuilds the site
#

set -e

INSTALL_DIR="/var/www/ccan"
BRANCH="storage-containers"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}[INFO]${NC} Updating C-Can Sam site..."

cd "$INSTALL_DIR"

# Fix "dubious ownership" error if running as different user
git config --global --add safe.directory "$INSTALL_DIR" 2>/dev/null || true

echo -e "${GREEN}[INFO]${NC} Fetching latest changes..."
git fetch origin

# Check for local changes that would be overwritten
if ! git diff --quiet HEAD || ! git diff --cached --quiet; then
    echo -e "${YELLOW}[WARN]${NC} Local changes detected that would be overwritten."
    echo ""
    git status --short
    echo ""
    read -p "Discard local changes and sync with remote? (y/N): " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${GREEN}[INFO]${NC} Resetting to remote..."
        git reset --hard origin/"$BRANCH"
    else
        echo -e "${RED}[ERROR]${NC} Update aborted. Please commit or stash your changes first."
        exit 1
    fi
else
    git checkout "$BRANCH"
    git pull origin "$BRANCH"
fi

echo -e "${GREEN}[INFO]${NC} Installing dependencies..."
npm ci --production=false

echo -e "${GREEN}[INFO]${NC} Building site..."
npm run build

echo -e "${GREEN}[INFO]${NC} Setting permissions..."
chown -R www-data:www-data "$INSTALL_DIR"
chmod 664 "$INSTALL_DIR/config.yaml"  # Writable by www-data for tag-reviews

echo -e "${GREEN}[INFO]${NC} Reloading nginx..."
systemctl reload nginx

echo ""
echo -e "${GREEN}[INFO]${NC} Update complete!"
