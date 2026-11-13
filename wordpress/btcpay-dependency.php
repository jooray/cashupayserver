<?php
/**
 * CashuPay: find the BTCPay for WooCommerce plugin and help the admin install it.
 *
 * Deliberately NOT a "Requires Plugins" header. BTCPay for WooCommerce itself declares
 * "Requires Plugins: woocommerce", so the header would chain WooCommerce in as a hard
 * dependency: WordPress would refuse to activate CashuPay without both, and an operator
 * whose WooCommerce broke or was removed could no longer reach the wallet to withdraw
 * ecash. The integration is optional (any Greenfield client can use /cashupay/api/v1);
 * this file only offers the next step, as a dismissible notice.
 *
 * WordPress-only. Copied into the plugin root by scripts/build-wordpress-plugin.sh and
 * docker/Dockerfile.wordpress; never part of the standalone build.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CASHUPAY_BTCPAY_SLUG = 'btcpay-greenfield-for-woocommerce';
const CASHUPAY_BTCPAY_NOTICE_META = 'cashupay_dismissed_btcpay_notice';

// Site admin only. On multisite, WooCommerce and the gateway are per-site concerns, and
// the network admin has no "this site's shop" to talk about.
add_action('admin_notices', 'cashupay_btcpay_notice');
add_action('admin_enqueue_scripts', 'cashupay_btcpay_notice_assets');
add_action('admin_post_cashupay_dismiss_btcpay', 'cashupay_dismiss_btcpay_notice');
add_action('admin_post_cashupay_connect_woocommerce', 'cashupay_connect_woocommerce_action');
add_action('admin_notices', 'cashupay_connect_woocommerce_result_notice');

const CASHUPAY_CONNECT_RESULT_TRANSIENT = 'cashupay_connect_result_';

/**
 * Where a plugin stands on this site.
 *
 * Found by directory name, the way core's WP_Plugin_Dependencies resolves slugs, with a
 * fallback on the main file name for copies unpacked from a GitHub zip
 * ("btcpay-greenfield-for-woocommerce-main/btcpay-greenfield-for-woocommerce.php").
 *
 * @return array{state: string, file: ?string} state is active|inactive|missing
 */
function cashupay_plugin_state(string $slug): array {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = array_keys(get_plugins());

    $found = null;
    foreach ($plugins as $file) {
        if (dirname($file) === $slug) {
            $found = $file;
            break;
        }
    }
    if ($found === null) {
        foreach ($plugins as $file) {
            if (basename($file) === $slug . '.php') {
                $found = $file;
                break;
            }
        }
    }
    if ($found === null) {
        return ['state' => 'missing', 'file' => null];
    }
    // is_plugin_active() also covers network activation on multisite.
    return ['state' => is_plugin_active($found) ? 'active' : 'inactive', 'file' => $found];
}

/**
 * The single next thing to do for the WooCommerce integration, or null when nothing is
 * left. WooCommerce comes first: BTCPay for WooCommerce declares "Requires Plugins:
 * woocommerce", so WordPress will not activate it before WooCommerce is active.
 *
 * @return array{
 *     intro?: bool,
 *     why: string,
 *     label: string,
 *     url: ?string,
 *     external: bool,
 *     details: ?string
 * }|null  `why` is the plain-words explanation shown to the admin; `url` is null when
 *         this user cannot take the step here (then `why` says whom to ask). `intro`
 *         false drops the generic "CashuPay needs BTCPay for WooCommerce" lead-in.
 */
function cashupay_btcpay_next_step(): ?array {
    $chain = [
        'woocommerce'        => __('WooCommerce', 'cashupay'),
        CASHUPAY_BTCPAY_SLUG => __('BTCPay for WooCommerce', 'cashupay'),
    ];

    foreach ($chain as $slug => $name) {
        $plugin = cashupay_plugin_state($slug);
        if ($plugin['state'] === 'active') {
            continue;
        }
        $isWoo = ($slug === 'woocommerce');

        if ($plugin['state'] === 'missing') {
            $why = $isWoo
                ? __('That plugin runs inside WooCommerce, which is not installed on this site yet. Install WooCommerce first; this message will then show the next step.', 'cashupay')
                : __('It is not installed yet. After it installs, click "Activate Plugin" on the next screen.', 'cashupay');

            // install_plugins is false for everyone when DISALLOW_FILE_MODS is set, and
            // super-admin only on multisite (map_meta_cap). Installs on multisite happen
            // in the network admin.
            if (current_user_can('install_plugins')) {
                $admin_url = is_multisite() ? 'network_admin_url' : 'self_admin_url';
                return [
                    'why'      => $why,
                    'label'    => sprintf(__('Install %s', 'cashupay'), $name),
                    'url'      => wp_nonce_url(
                        $admin_url('update.php?action=install-plugin&plugin=' . rawurlencode($slug)),
                        'install-plugin_' . $slug
                    ),
                    'external' => false,
                    'details'  => $admin_url('plugin-install.php?tab=plugin-information&plugin=' . rawurlencode($slug)
                        . '&TB_iframe=true&width=772&height=600'),
                ];
            }

            $page = 'https://wordpress.org/plugins/' . $slug . '/';
            if (!wp_is_file_mod_allowed('capability_update_core')) {
                // Installing from wp-admin is switched off for this whole site (usually
                // DISALLOW_FILE_MODS in wp-config.php, often set by managed hosting).
                return [
                    'why'      => __('Installing plugins from this dashboard is turned off on this site. Ask your web host or whoever looks after the site to add it for you, or upload it through your hosting control panel.', 'cashupay'),
                    'label'    => sprintf(__('Get %s from WordPress.org', 'cashupay'), $name),
                    'url'      => $page,
                    'external' => true,
                    'details'  => null,
                ];
            }
            return [
                'why'      => is_multisite()
                    ? __('On this network, only the network administrator can install plugins. Please ask them to install it.', 'cashupay')
                    : __('Your account is not allowed to install plugins. Please ask the site administrator to install it.', 'cashupay'),
                'label'    => sprintf(__('About %s on WordPress.org', 'cashupay'), $name),
                'url'      => $page,
                'external' => true,
                'details'  => null,
            ];
        }

        // Installed but switched off.
        $why = $isWoo
            ? __('That plugin runs inside WooCommerce, which is installed but switched off.', 'cashupay')
            : __('It is installed but switched off.', 'cashupay');

        if (current_user_can('activate_plugin', $plugin['file'])) {
            return [
                'why'      => $why,
                'label'    => sprintf(__('Activate %s', 'cashupay'), $name),
                // Site admin: activates for this site only (plugins.php passes
                // is_network_admin() as $network_wide).
                'url'      => wp_nonce_url(
                    self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode($plugin['file'])),
                    'activate-plugin_' . $plugin['file']
                ),
                'external' => false,
                'details'  => null,
            ];
        }
        return [
            'why'      => $why . ' ' . (is_multisite()
                ? __('On this network, only the network administrator can switch plugins on. Please ask them to activate it for this site.', 'cashupay')
                : __('Your account is not allowed to switch plugins on. Please ask the site administrator to activate it.', 'cashupay')),
            'label'    => '',
            'url'      => null,
            'external' => false,
            'details'  => null,
        ];
    }

    // Both active. Installing them after the setup wizard skips the wizard's automatic
    // connection (it only runs inside setup), so say so until the gateway points somewhere.
    // A URL that is not ours means a real BTCPay Server; that is the admin's choice.
    // Pointing at us is not enough on its own: without the webhook that tells the shop
    // about payments (or after an uninstall switched it off), orders stay "Pending
    // payment" and nothing else warns about it (UX-A2).
    $gatewayUrl = (string)get_option('btcpay_gf_url', '');
    $webhookMissing = $gatewayUrl !== '' && cashupay_gateway_url_is_ours($gatewayUrl)
        && !cashupay_gateway_webhook_ready();
    if ($gatewayUrl === '' || $webhookMissing) {
        // The same one-click connection as the setup wizard's last step (ARGON UX-05):
        // someone who installed the plugins after setup, or disconnected another BTCPay
        // Server as the wizard told her, could otherwise only copy addresses by hand.
        $can = current_user_can('manage_woocommerce') && cashupay_current_user_can_manage();
        $problem = $webhookMissing
            ? __('Your shop is set to use CashuPay, but CashuPay is not set up to tell your shop when an order is paid, so paid orders would stay "Pending payment".', 'cashupay')
            : __('BTCPay for WooCommerce is switched on but not connected to CashuPay yet, so your shop cannot take payments through it.', 'cashupay');
        return [
            'intro'    => false,
            'why'      => $problem . ' ' . ($can
                ? __('Click "Connect my shop to CashuPay" and CashuPay fills in its settings for you.', 'cashupay')
                : __('Please ask the site administrator to open this page and click "Connect my shop to CashuPay".', 'cashupay')),
            'label'    => $can ? __('Connect my shop to CashuPay', 'cashupay') : '',
            'url'      => $can
                ? wp_nonce_url(admin_url('admin-post.php?action=cashupay_connect_woocommerce'), 'cashupay_connect_woocommerce')
                : null,
            'external' => false,
            'details'  => null,
        ];
    }
    return null;
}

/**
 * Whether CashuPay will tell the shop about payments: an enabled webhook of the store
 * the gateway uses, pointing at the gateway's callback, whose id the gateway knows (it
 * checks signatures with that webhook's secret).
 */
function cashupay_gateway_webhook_ready(): bool {
    $storeId = (string)get_option('btcpay_gf_store_id', '');
    $saved = get_option('btcpay_gf_webhook', null);
    $savedId = is_array($saved) ? (string)($saved['id'] ?? '') : '';
    if ($storeId === '' || $savedId === '') {
        return false;
    }
    try {
        require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
        $row = Database::fetchOne(
            'SELECT id FROM webhooks WHERE id = ? AND store_id = ? AND url = ? AND enabled = 1 AND deleted_at IS NULL',
            [$savedId, $storeId, site_url('/?wc-api=btcpaygf_default')]
        );
        return (bool)$row;
    } catch (Throwable $e) {
        // A database that cannot be read has its own notice; do not add a wrong one.
        return true;
    }
}

/**
 * What to tell the admin when the shop points at CashuPay but registering the webhook
 * failed: orders would stay "Pending payment". Shared with the setup wizard.
 */
function cashupay_webhook_failure_message(string $error): string {
    return sprintf(
        __('The shop is connected to CashuPay, but CashuPay cannot tell your shop when an order is paid, so orders would stay "Pending payment". Send this message to your hosting provider: %s', 'cashupay'),
        $error
    );
}

/**
 * Whether the notice belongs on this request, and if so, the step it shows.
 *
 * Contextual and dismissible (wordpress.org guideline 11): only for admins, only once
 * CashuPay itself is set up (the "configure CashuPay" notice comes first, and the setup
 * wizard has its own WooCommerce section), and never again for a user who dismissed it.
 * Screens are checked by the caller.
 */
function cashupay_btcpay_notice_step(): ?array {
    if (is_network_admin() || !current_user_can('manage_options')) {
        return null;
    }
    if (get_user_meta(get_current_user_id(), CASHUPAY_BTCPAY_NOTICE_META, true)) {
        return null;
    }
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        return null;
    }
    return cashupay_btcpay_next_step();
}

/**
 * Plugins screen, Dashboard, and our own Tools → CashuPay page. Nowhere else.
 */
function cashupay_btcpay_notice_screen(): bool {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return $screen !== null
        && in_array($screen->id, ['plugins', 'dashboard', 'tools_page_cashupay'], true);
}

/**
 * The "More details" link opens core's plugin-information modal, which needs Thickbox.
 */
function cashupay_btcpay_notice_assets(): void {
    if (!cashupay_btcpay_notice_screen()) {
        return;
    }
    $step = cashupay_btcpay_notice_step();
    if ($step !== null && $step['details'] !== null) {
        add_thickbox();
    }
}

function cashupay_btcpay_notice(): void {
    if (!cashupay_btcpay_notice_screen()) {
        return;
    }
    $step = cashupay_btcpay_notice_step();
    if ($step === null) {
        return;
    }
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=cashupay_dismiss_btcpay'), 'cashupay_dismiss_btcpay');

    echo '<div class="notice notice-info cashupay-btcpay-notice"><p><strong>CashuPay:</strong> ';
    if ($step['intro'] ?? true) {
        echo esc_html__('To take payments in your WooCommerce shop, CashuPay needs the free "BTCPay for WooCommerce" plugin.', 'cashupay') . ' ';
    }
    echo esc_html($step['why']);
    echo '</p><p>';
    if ($step['url'] !== null) {
        if ($step['external']) {
            echo '<a class="button" href="' . esc_url($step['url']) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html($step['label']) . '</a> ';
        } else {
            echo '<a class="button button-primary" href="' . esc_url($step['url']) . '">' . esc_html($step['label']) . '</a> ';
        }
    }
    if ($step['details'] !== null) {
        echo '<a class="thickbox open-plugin-details-modal" href="' . esc_url($step['details']) . '">'
            . esc_html__('More details', 'cashupay') . '</a> &nbsp; ';
    }
    echo '<a href="' . esc_url($dismiss) . '">' . esc_html__('I don\'t use WooCommerce, hide this', 'cashupay') . '</a>';
    echo '</p></div>';
}

function cashupay_dismiss_btcpay_notice(): void {
    check_admin_referer('cashupay_dismiss_btcpay');
    if (current_user_can('manage_options')) {
        update_user_meta(get_current_user_id(), CASHUPAY_BTCPAY_NOTICE_META, 1);
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}

/**
 * The store the WooCommerce gateway should use: the one it was connected to before, if
 * it still exists and is set up, else the first store that finished setup.
 *
 * @return array{id: string, name: string}|null
 */
function cashupay_woocommerce_store(): ?array {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    $ready = "mint_url IS NOT NULL AND mint_url != '' AND seed_phrase IS NOT NULL AND seed_phrase != ''";
    $previous = (string)get_option('btcpay_gf_store_id', '');
    if ($previous !== '') {
        $row = Database::fetchOne("SELECT id, name FROM stores WHERE id = ? AND $ready", [$previous]);
        if ($row) {
            return ['id' => (string)$row['id'], 'name' => (string)$row['name']];
        }
    }
    $row = Database::fetchOne("SELECT id, name FROM stores WHERE $ready ORDER BY created_at, id LIMIT 1");
    return $row ? ['id' => (string)$row['id'], 'name' => (string)$row['name']] : null;
}

/** "Connect my shop to CashuPay" in the notice above. */
function cashupay_connect_woocommerce_action(): void {
    check_admin_referer('cashupay_connect_woocommerce');
    if (!cashupay_current_user_can_manage() || !current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Your account is not allowed to change the shop\'s payment settings.', 'cashupay'), '', ['response' => 403]);
    }

    $result = ['ok' => false, 'text' => ''];
    try {
        require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
        require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
        require_once __DIR__ . '/btcpay-integration.php';
        $store = Database::isInitialized() && Config::isSetupComplete() ? cashupay_woocommerce_store() : null;
        if ($store === null) {
            $result['text'] = __('CashuPay is not set up yet. Finish the CashuPay setup first (Tools → CashuPay), then click the button again.', 'cashupay');
        } else {
            $connect = cashupay_connect_woocommerce_store($store['id']);
            if (!($connect['success'] ?? false)) {
                $result['text'] = (string)($connect['message'] ?? $connect['error'] ?? __('The connection did not work.', 'cashupay'));
            } elseif (!($connect['webhook']['success'] ?? false)) {
                // Without the webhook, paid orders are never marked paid.
                $result['text'] = cashupay_webhook_failure_message((string)($connect['webhook']['error'] ?? ''));
            } else {
                $result = ['ok' => true, 'text' => sprintf(
                    __('Your shop is connected to CashuPay (store "%s"). Customers can now pay with Bitcoin at checkout. Check that "BTCPay" is switched on under WooCommerce → Settings → Payments.', 'cashupay'),
                    $store['name']
                )];
            }
        }
    } catch (Throwable $e) {
        error_log('CashuPayServer: connecting WooCommerce failed: ' . $e->getMessage());
        $result['text'] = sprintf(__('The connection did not work: %s', 'cashupay'), $e->getMessage());
    }
    set_transient(CASHUPAY_CONNECT_RESULT_TRANSIENT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
    // Back where the button was, except Tools → CashuPay: once connected, that page
    // forwards to the CashuPay dashboard, which shows no WordPress notices.
    // The result is a wp-admin notice, so a click from the CashuPay dashboard (outside
    // wp-admin) lands on the WordPress dashboard where it is shown.
    $back = (string)wp_get_referer();
    if ($back === '' || strpos($back, 'page=cashupay') !== false || strpos($back, admin_url()) !== 0) {
        $back = admin_url();
    }
    wp_safe_redirect($back);
    exit;
}

/** What the "Connect my shop to CashuPay" button did, shown once on the next page. */
function cashupay_connect_woocommerce_result_notice(): void {
    if (!cashupay_current_user_can_manage()) {
        return;
    }
    $key = CASHUPAY_CONNECT_RESULT_TRANSIENT . get_current_user_id();
    $result = get_transient($key);
    if (!is_array($result) || ($result['text'] ?? '') === '') {
        return;
    }
    delete_transient($key);
    echo '<div class="notice ' . (!empty($result['ok']) ? 'notice-success' : 'notice-error') . '"><p><strong>CashuPay:</strong> '
        . esc_html((string)$result['text']) . '</p></div>';
}
