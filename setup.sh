#!/usr/bin/env bash
# setup.sh — Easy Builders Merchant Pro VPS setup script
# Run as root on Ubuntu 22.04 LTS
# Usage: sudo bash setup.sh

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Colour

WEB_DIR="/var/www/ebmpro"
BACKUPS_DIR="${WEB_DIR}/backups"
PHP_INI="/etc/php/8.1/apache2/php.ini"

echo -e "${YELLOW}=== Easy Builders Merchant Pro — VPS Setup ===${NC}"
echo ""

# ── 1. Update system ──────────────────────────────────────────────────────────
echo -e "${GREEN}[1/8] Updating system packages…${NC}"
apt-get update -qq
apt-get upgrade -y -qq

# ── 2. Install Apache, PHP 8.1, MySQL ────────────────────────────────────────
echo -e "${GREEN}[2/8] Installing Apache, PHP 8.1, MySQL…${NC}"
apt-get install -y -qq \
    apache2 \
    mysql-server \
    php8.1 \
    php8.1-mysql \
    php8.1-mbstring \
    php8.1-zip \
    php8.1-xml \
    php8.1-curl \
    php8.1-json \
    php8.1-bcmath \
    libapache2-mod-php8.1

# Fallback to php if 8.1 not available
if ! php --version &>/dev/null; then
    apt-get install -y -qq php php-mysql php-mbstring php-zip php-xml php-curl php-bcmath libapache2-mod-php
fi

# ── 3. Enable Apache modules ──────────────────────────────────────────────────
echo -e "${GREEN}[3/8] Enabling Apache modules…${NC}"
a2enmod rewrite
a2enmod headers
a2enmod ssl
systemctl enable apache2
systemctl start apache2

# ── 4. Create web directory ───────────────────────────────────────────────────
echo -e "${GREEN}[4/8] Creating web directory…${NC}"
mkdir -p "${WEB_DIR}"
mkdir -p "${BACKUPS_DIR}"

# ── 5. PHP configuration (increase limits for large imports) ──────────────────
echo -e "${GREEN}[5/8] Configuring PHP limits…${NC}"
if [ -f "${PHP_INI}" ]; then
    sed -i 's/^upload_max_filesize.*/upload_max_filesize = 64M/'   "${PHP_INI}"
    sed -i 's/^post_max_size.*/post_max_size = 64M/'               "${PHP_INI}"
    sed -i 's/^max_execution_time.*/max_execution_time = 300/'     "${PHP_INI}"
    sed -i 's/^memory_limit.*/memory_limit = 256M/'                "${PHP_INI}"
    echo "PHP limits updated."
else
    echo -e "${YELLOW}  Warning: ${PHP_INI} not found — set limits manually.${NC}"
fi

# ── 6. Set file permissions ───────────────────────────────────────────────────
echo -e "${GREEN}[6/8] Setting file permissions…${NC}"
find "${WEB_DIR}" -type f -exec chmod 644 {} \; 2>/dev/null || true
find "${WEB_DIR}" -type d -exec chmod 755 {} \; 2>/dev/null || true

# Backups dir writable by PHP (www-data)
chown -R www-data:www-data "${BACKUPS_DIR}"
chmod 750 "${BACKUPS_DIR}"

# Protect config.php if it exists
if [ -f "${WEB_DIR}/ebmpro_api/config.php" ]; then
    chmod 640 "${WEB_DIR}/ebmpro_api/config.php"
    chown www-data:www-data "${WEB_DIR}/ebmpro_api/config.php"
fi

# ── 7. Restart services ───────────────────────────────────────────────────────
echo -e "${GREEN}[7/8] Restarting services…${NC}"
systemctl restart apache2
systemctl enable mysql
systemctl start mysql

# ── 8. Health check ───────────────────────────────────────────────────────────
echo -e "${GREEN}[8/8] Running health check…${NC}"
echo ""

PASS=0
FAIL=0

check() {
    local label="$1"
    local cmd="$2"
    if eval "$cmd" &>/dev/null; then
        echo -e "  ${GREEN}✓${NC}  $label"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}✗${NC}  $label"
        FAIL=$((FAIL + 1))
    fi
}

check "Apache is running"              "systemctl is-active apache2"
check "MySQL is running"               "systemctl is-active mysql"
check "PHP CLI available"              "php --version"
check "PHP PDO MySQL extension"        "php -m | grep -i pdo_mysql"
check "PHP mbstring extension"         "php -m | grep -i mbstring"
check "PHP zip extension"              "php -m | grep -i zip"
check "PHP xml extension"              "php -m | grep -i xml"
check "PHP curl extension"             "php -m | grep -i curl"
check "PHP bcmath extension"           "php -m | grep -i bcmath"
check "Web directory exists"           "test -d ${WEB_DIR}"
check "Backups directory exists"       "test -d ${BACKUPS_DIR}"
check "Backups directory writable"     "test -w ${BACKUPS_DIR}"

# Check DB connection if config.php is already in place
if [ -f "${WEB_DIR}/ebmpro_api/config.php" ]; then
    if php -r "
        require '${WEB_DIR}/ebmpro_api/config.php';
        \$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
        echo 'ok';
    " 2>/dev/null | grep -q 'ok'; then
        echo -e "  ${GREEN}✓${NC}  Database connection"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}✗${NC}  Database connection (check config.php credentials)"
        FAIL=$((FAIL + 1))
    fi
fi

echo ""
echo -e "${YELLOW}Health check complete: ${GREEN}${PASS} passed${NC}, ${RED}${FAIL} failed${NC}"
echo ""

if [ "$FAIL" -eq 0 ]; then
    echo -e "${GREEN}✅ Setup complete! Next steps:${NC}"
    echo "   1. Upload your application files to ${WEB_DIR}"
    echo "   2. Create the database: mysql -u root -p"
    echo "   3. Import schema: mysql -u USER -p DBNAME < ${WEB_DIR}/install/schema.sql"
    echo "   4. Edit ${WEB_DIR}/ebmpro_api/config.php with your DB credentials"
    echo "   5. Configure Apache virtual host (see INSTALL.md)"
    echo "   6. Install SSL: certbot --apache -d yourdomain.ie"
else
    echo -e "${RED}⚠️  Some checks failed. See above for details.${NC}"
    echo "   Refer to INSTALL.md for troubleshooting guidance."
fi
