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
    if ((string)get_option('btcpay_gf_url', '') === '') {
        $can = current_user_can('manage_woocommerce');
        return [
            'intro'    => false,
            'why'      => sprintf(
                __('BTCPay for WooCommerce is switched on but not connected to CashuPay yet, so your shop cannot take payments through it. In its settings, paste %s as the "BTCPay Server URL", click "Generate API key" and approve the connection.', 'cashupay'),
                Urls::server()
            ),
            'label'    => $can ? __('Open BTCPay settings', 'cashupay') : '',
            'url'      => $can ? admin_url('admin.php?page=wc-settings&tab=btcpay_settings') : null,
            'external' => false,
            'details'  => null,
        ];
    }
    return null;
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
