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

# Install CashuPay plugin (copy from staging area)
if [ ! -d /var/www/html/wp-content/plugins/cashupay ]; then
    cp -a /usr/src/cashupay-plugin /var/www/html/wp-content/plugins/cashupay
    chown -R www-data:www-data /var/www/html/wp-content/plugins/cashupay
fi

# Activate CashuPay plugin. Activation creates the data folder and database and registers
# /cashupay-setup/, so a failure here must be visible in `docker logs`.
CASHUPAY_ACTIVE=yes
if ! su -s /bin/bash www-data -c "wp --path=/var/www/html plugin activate cashupay"; then
    CASHUPAY_ACTIVE=no
fi
# The plugin turns its own startup errors into an admin notice instead of a fatal error;
# surface that too.
ACTIVATION_ERROR="$(su -s /bin/bash www-data -c "wp --path=/var/www/html option get cashupay_activation_error" 2>/dev/null || true)"
if [ "$CASHUPAY_ACTIVE" = no ] || [ -n "$ACTIVATION_ERROR" ]; then
    echo "ERROR: CashuPay plugin activation failed; /cashupay-setup/ will not work until it succeeds." >&2
    [ -n "$ACTIVATION_ERROR" ] && echo "       Reason: $ACTIVATION_ERROR" >&2
    echo "       See the message in WP Admin, fix it, then restart the container." >&2
fi

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
