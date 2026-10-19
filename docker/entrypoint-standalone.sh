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

# Install and activate WooCommerce and BTCPay Greenfield for WooCommerce. Both come from
# wordpress.org at first start; a network hiccup must not stop the container (set -e would
# otherwise exit before Apache starts, every start, until the network is back). The
# is-installed guard retries the download on the next start.
install_plugin_from_wordpress_org() {
    local slug="$1"
    if su -s /bin/bash www-data -c "wp --path=/var/www/html plugin is-installed $slug" 2>/dev/null; then
        return 0
    fi
    if ! su -s /bin/bash www-data -c "wp --path=/var/www/html plugin install $slug --activate"; then
        echo "WARNING: could not download the $slug plugin from wordpress.org (network problem?)." >&2
        echo "         The container keeps running without it; restart it later to try again." >&2
    fi
}
install_plugin_from_wordpress_org woocommerce
install_plugin_from_wordpress_org btcpay-greenfield-for-woocommerce

# Enable pretty permalinks (needed for WooCommerce)
su -s /bin/bash www-data -c "wp --path=/var/www/html rewrite structure '/%postname%/' --hard" 2>/dev/null || true

# --- Setup CashuPayServer on separate Apache virtual host (port 8080) ---
echo "Setting up CashuPayServer Apache virtual host on port 8080..."

# Add Listen 8080
if ! grep -q "Listen 8080" /etc/apache2/ports.conf; then
    echo "Listen 8080" >> /etc/apache2/ports.conf
fi

# Create virtual host for CashuPayServer
cat > /etc/apache2/sites-available/cashupayserver.conf << 'VHOST'
<VirtualHost *:8080>
    DocumentRoot /opt/cashupayserver

    <Directory /opt/cashupayserver>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/cashupay-error.log
    CustomLog ${APACHE_LOG_DIR}/cashupay-access.log combined
</VirtualHost>
VHOST

# Enable the site
a2ensite cashupayserver

echo ""
echo "============================================"
echo "  WordPress:       http://localhost"
echo "  WP Admin:        http://localhost/wp-admin"
echo "  WP Login:        admin / admin"
echo "  CashuPayServer:  http://localhost:8080"
echo "============================================"
echo ""

# Start Apache (foreground)
exec "$@"
