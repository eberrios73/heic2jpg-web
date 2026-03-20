#!/bin/bash
# heic2jpg-web installer
# Installs all dependencies and configures a self-service HEIC→JPG converter
# Tested on Ubuntu 22.04 / 24.04

set -euo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info()  { echo -e "${CYAN}[INFO]${NC}  $1"; }
log_ok()    { echo -e "${GREEN}[OK]${NC}    $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
log_fail()  { echo -e "${RED}[FAIL]${NC}  $1"; }

echo ""
echo -e "${CYAN}heic2jpg-web installer${NC}"
echo "====================="
echo ""

# Check root
if [[ $EUID -ne 0 ]]; then
    echo "This script must be run as root (sudo)."
    exit 1
fi

# Detect OS
if ! command -v apt-get &>/dev/null; then
    log_fail "This installer requires apt (Ubuntu/Debian). For other distros, install manually."
    exit 1
fi

# -------------------------------------------------------
# 1. Install base packages
# -------------------------------------------------------
log_info "Installing base packages..."
apt-get update -qq
apt-get install -y -qq apache2 php libapache2-mod-php php-zip imagemagick libheif-examples ffmpeg unzip > /dev/null 2>&1
log_ok "Base packages installed"

# -------------------------------------------------------
# 2. Upgrade libheif (fixes iPhone HEIC compatibility)
# -------------------------------------------------------
log_info "Checking libheif version..."
HEIF_VERSION=$(heif-convert --version 2>&1 | head -1 | grep -oP '[\d.]+' | head -1)
HEIF_MINOR=$(echo "$HEIF_VERSION" | cut -d. -f2)

if [[ "$HEIF_MINOR" -lt 18 ]]; then
    log_warn "libheif $HEIF_VERSION detected — upgrading to fix iPhone HEIC support..."
    add-apt-repository -y ppa:strukturag/libheif > /dev/null 2>&1
    apt-get update -qq
    apt-get install -y -qq --only-upgrade libheif1 libheif-examples > /dev/null 2>&1
    NEW_VERSION=$(heif-convert --version 2>&1 | head -1 | grep -oP '[\d.]+' | head -1)
    log_ok "libheif upgraded to $NEW_VERSION"
else
    log_ok "libheif $HEIF_VERSION — no upgrade needed"
fi

# -------------------------------------------------------
# 3. Fix ImageMagick security policy
# -------------------------------------------------------
log_info "Configuring ImageMagick policy..."
POLICY_FILE="/etc/ImageMagick-6/policy.xml"
if [[ ! -f "$POLICY_FILE" ]]; then
    POLICY_FILE="/etc/ImageMagick-7/policy.xml"
fi

if [[ -f "$POLICY_FILE" ]]; then
    # Backup original
    cp "$POLICY_FILE" "${POLICY_FILE}.bak.$(date +%s)" 2>/dev/null || true

    # Add HEIC/HEIF to allowed coders
    if grep -q 'pattern="{GIF,JPEG,PNG,WEBP}"' "$POLICY_FILE"; then
        sed -i 's/pattern="{GIF,JPEG,PNG,WEBP}"/pattern="{GIF,JPEG,PNG,WEBP,HEIC,HEIF}"/' "$POLICY_FILE"
        log_ok "Added HEIC/HEIF to ImageMagick allowed coders"
    elif grep -q 'HEIC' "$POLICY_FILE"; then
        log_ok "HEIC already allowed in ImageMagick policy"
    else
        log_warn "Could not auto-configure ImageMagick policy — check $POLICY_FILE manually"
    fi
else
    log_warn "ImageMagick policy file not found — skipping"
fi

# -------------------------------------------------------
# 4. Configure PHP upload limits
# -------------------------------------------------------
log_info "Configuring PHP upload limits..."
PHP_INI=$(php -r "echo php_ini_loaded_file();" 2>/dev/null)
APACHE_PHP_INI=$(find /etc/php -name "php.ini" -path "*/apache2/*" 2>/dev/null | head -1)

configure_php_ini() {
    local ini="$1"
    if [[ -f "$ini" ]]; then
        sed -i 's/^upload_max_filesize.*/upload_max_filesize = 500M/' "$ini"
        sed -i 's/^post_max_size.*/post_max_size = 500M/' "$ini"
        sed -i 's/^max_execution_time.*/max_execution_time = 300/' "$ini"
        log_ok "Configured $ini (500M uploads, 300s timeout)"
    fi
}

configure_php_ini "$PHP_INI"
if [[ -n "$APACHE_PHP_INI" && "$APACHE_PHP_INI" != "$PHP_INI" ]]; then
    configure_php_ini "$APACHE_PHP_INI"
fi

# -------------------------------------------------------
# 5. Deploy web files
# -------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WEB_DIR="/var/www/html/heic2jpg"

log_info "Deploying to $WEB_DIR..."
mkdir -p "$WEB_DIR"
cp "$SCRIPT_DIR/index.html" "$WEB_DIR/"
cp "$SCRIPT_DIR/heic_convert.php" "$WEB_DIR/"
cp "$SCRIPT_DIR/heic_download.php" "$WEB_DIR/"
chown -R www-data:www-data "$WEB_DIR"
log_ok "Files deployed to $WEB_DIR"

# -------------------------------------------------------
# 6. Restart Apache
# -------------------------------------------------------
log_info "Restarting Apache..."
systemctl restart apache2
log_ok "Apache restarted"

# -------------------------------------------------------
# 7. Verify
# -------------------------------------------------------
echo ""
echo "---"
log_info "Verification:"

verify() {
    if command -v "$1" &>/dev/null; then
        log_ok "$1 found at $(which $1)"
    else
        log_fail "$1 not found"
    fi
}

verify heif-convert
verify convert
verify ffmpeg
verify php
verify apache2

echo ""
echo "---"
IP=$(hostname -I | awk '{print $1}')
echo -e "${GREEN}Done!${NC} HEIC Converter is live at:"
echo ""
echo -e "  ${CYAN}http://${IP}/heic2jpg/${NC}"
echo ""
echo "Users can open this URL in their browser to convert HEIC files to JPG."
echo ""
