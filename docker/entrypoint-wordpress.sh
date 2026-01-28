#!/bin/bash
set -e

# --- WordPress Setup with SQLite ---

# Copy WordPress core files if not present
if [ ! -f /var/www/html/wp-includes/version.php ]; then
    cp -a /usr/src/wordpress/. /var/www/html/
    chown -R www-data:www-data /var/www/html
fi

# Set up SQLite integration plugin
if [ ! -d /var/www/html/wp-content/plugins/sqlite-database-integration ]; then
    cp -a /usr/src/sqlite-plugin /var/www/html/wp-content/plugins/sqlite-database-integration
    chown -R www-data:www-data /var/www/html/wp-content/plugins/sqlite-database-integration
fi

# Place db.php drop-in for SQLite support
if [ ! -f /var/www/html/wp-content/db.php ]; then
    cp /var/www/html/wp-content/plugins/sqlite-database-integration/db.copy \
       /var/www/html/wp-content/db.php
    chown www-data:www-data /var/www/html/wp-content/db.php
fi

# Create wp-config.php if it doesn't exist
if [ ! -f /var/www/html/wp-config.php ]; then
    cat > /var/www/html/wp-config.php << 'WPCONFIG'
<?php
define('DB_NAME', 'wordpress');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

// SQLite configuration
define('DB_DIR', '/var/www/html/wp-content/database/');
define('DB_FILE', 'wordpress.sqlite');

// Authentication keys (generated for Docker testing only)
define('AUTH_KEY',         'docker-test-key-1');
define('SECURE_AUTH_KEY',  'docker-test-key-2');
define('LOGGED_IN_KEY',    'docker-test-key-3');
define('NONCE_KEY',        'docker-test-key-4');
define('AUTH_SALT',        'docker-test-salt-1');
define('SECURE_AUTH_SALT', 'docker-test-salt-2');
define('LOGGED_IN_SALT',   'docker-test-salt-3');
define('NONCE_SALT',       'docker-test-salt-4');

$table_prefix = 'wp_';

define('WP_DEBUG', false);

if ( ! defined('ABSPATH') ) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
WPCONFIG
    mkdir -p /var/www/html/wp-content/database
    chown -R www-data:www-data /var/www/html/wp-content/database
    chown www-data:www-data /var/www/html/wp-config.php
fi

# Install WordPress via WP-CLI
if ! su -s /bin/bash www-data -c "wp --path=/var/www/html core is-installed" 2>/dev/null; then
    su -s /bin/bash www-data -c "wp --path=/var/www/html core install \
        --url=http://localhost \
        --title='CashuPay Test Store' \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email"
fi

# Install and activate WooCommerce
if ! su -s /bin/bash www-data -c "wp --path=/var/www/html plugin is-installed woocommerce" 2>/dev/null; then
    su -s /bin/bash www-data -c "wp --path=/var/www/html plugin install woocommerce --activate"
fi

# Install and activate BTCPay Greenfield for WooCommerce
if ! su -s /bin/bash www-data -c "wp --path=/var/www/html plugin is-installed btcpay-greenfield-for-woocommerce" 2>/dev/null; then
    su -s /bin/bash www-data -c "wp --path=/var/www/html plugin install btcpay-greenfield-for-woocommerce --activate"
fi

# Install CashuPay plugin (copy from staging area)
if [ ! -d /var/www/html/wp-content/plugins/cashupay ]; then
    cp -a /usr/src/cashupay-plugin /var/www/html/wp-content/plugins/cashupay
    chown -R www-data:www-data /var/www/html/wp-content/plugins/cashupay
fi

# Activate CashuPay plugin
su -s /bin/bash www-data -c "wp --path=/var/www/html plugin activate cashupay" 2>/dev/null || true

# Enable pretty permalinks (required for CashuPay rewrite rules)
su -s /bin/bash www-data -c "wp --path=/var/www/html rewrite structure '/%postname%/' --hard" 2>/dev/null || true
su -s /bin/bash www-data -c "wp --path=/var/www/html rewrite flush --hard" 2>/dev/null || true

echo ""
echo "============================================"
echo "  WordPress:       http://localhost"
echo "  WP Admin:        http://localhost/wp-admin"
echo "  WP Login:        admin / admin"
echo "  CashuPay Setup:  http://localhost/cashupay-setup/"
echo "  CashuPay Admin:  http://localhost/cashupay-admin/"
echo "============================================"
echo ""

# Start Apache (foreground)
exec "$@"
