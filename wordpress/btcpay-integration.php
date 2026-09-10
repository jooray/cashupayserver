<?php
/**
 * BareBits plugin — BTCPay-for-WooCommerce auto-configuration.
 *
 * Safely points the BTCPay Greenfield WooCommerce gateway at the connected
 * BareBits server: installs/activates the gateway plugin from wordpress.org,
 * writes its options, registers the webhook over the server's Greenfield API,
 * and applies BareBits branding — with safety checks so a real BTCPay Server
 * configuration is never overwritten without explicit consent. All server
 * communication is HTTP; nothing here reads BareBits internals. License:
 * GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if a real BTCPay Server (not the connected BareBits server) is
 * configured in the gateway plugin.
 */
function cashupay_is_real_btcpay_configured(): bool {
    $url = get_option('btcpay_gf_url', '');
    if (empty($url)) {
        return false;
    }
    $ours = cashupay_server_url();
    if ($ours !== '' && strpos($url, $ours) === 0) {
        return false; // Already ours
    }
    return true; // Real BTCPay Server is configured
}

/**
 * Pure decision for the existing-BTCPay takeover flow. Given the BTCPay
 * plugin's configured server URL, our own server URL, and the URL the
 * merchant last consented to replace, decide what the wiring may do:
 *
 *   'none'          no real BTCPay Server is configured (URL empty or already
 *                   ours) — normal wiring may proceed.
 *   'needs_consent' a real server is configured and no matching consent
 *                   exists — hands off entirely until the merchant approves.
 *   'consented'     a real server is configured and the merchant approved
 *                   replacing exactly this one.
 *
 * Consent is compared against the exact configured URL: approval given for
 * one server must never silently authorize clobbering a different one the
 * merchant connected later.
 *
 * Pure (no WordPress calls) so tests/php can pin the matrix without a
 * WordPress install; cashupay_btcpay_takeover_state() is the live wrapper.
 */
function cashupay_btcpay_takeover_decision(string $configuredUrl, string $ourUrl, string $consentUrl): string {
    $configuredUrl = trim($configuredUrl);
    // Mirrors cashupay_is_real_btcpay_configured(): empty or prefixed by our
    // own URL means the config is ours (or absent), not a real server's.
    if ($configuredUrl === '' || ($ourUrl !== '' && strpos($configuredUrl, $ourUrl) === 0)) {
        return 'none';
    }
    return trim($consentUrl) === $configuredUrl ? 'consented' : 'needs_consent';
}

/**
 * The takeover decision for this site's live options. Consent is persisted in
 * an option (not the PHP session) because it can be granted on one onboarding
 * screen and consumed on a later request.
 */
function cashupay_btcpay_takeover_state(): string {
    return cashupay_btcpay_takeover_decision(
        (string) get_option('btcpay_gf_url', ''),
        cashupay_server_url(),
        (string) get_option('cashupay_btcpay_override_consent', '')
    );
}

/**
 * Record the merchant's approval to replace the currently configured BTCPay
 * Server connection. Stores the exact URL being replaced, so the consent is
 * scoped to that server and a later reconnection to a different one re-warns.
 */
function cashupay_record_btcpay_override_consent(): void {
    $url = trim((string) get_option('btcpay_gf_url', ''));
    if ($url !== '') {
        update_option('cashupay_btcpay_override_consent', $url);
    }
}

/**
 * Delete every option the BTCPay Greenfield plugin holds — the btcpay_gf_*
 * globals (server URL, API key, store id, webhook, order states, transaction
 * speed, separate-gateways mode, …) and all woocommerce_btcpaygf_* gateway
 * settings (the default gateway plus any separate per-payment-method ones).
 *
 * Only called on a consented takeover: the merchant approved replacing "all
 * existing settings", and the writers that run next then find every field
 * empty and fill in BareBits defaults. Deleting by prefix rather than by a
 * hardcoded list keeps this correct across gateway-plugin versions; each name
 * goes through delete_option() so the options cache stays coherent.
 */
function cashupay_reset_btcpay_plugin_settings(): void {
    global $wpdb;
    // Internal bookkeeping the gateway plugin keeps alongside its settings —
    // not configuration of the old server, so it survives the wipe.
    // btcpay_gf_version is load-bearing: it gates UpdateManager::processUpdates()
    // at every plugin boot, and with it deleted each boot re-runs the version
    // migrations — update-1.0.3's webhook migration performs a BLOCKING API
    // call against the configured server URL, so every request spawns a
    // blocking request that boots the plugin again until the PHP workers are
    // exhausted and the site deadlocks. The dismissal flags are merely the
    // merchant's notice/review UI state.
    $keep = [
        'btcpay_gf_version',
        'btcpay_gf_review_dismissed',
        'btcpay_gf_review_dismissed_forever',
        'btcpay_gf_order_states_warning',
    ];
    // Direct, uncached on purpose: enumerating ANOTHER plugin's options by
    // prefix has no WordPress API, and this one-shot cleanup must see the
    // live table, not a cache.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $names = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
          WHERE option_name LIKE 'btcpay\\_gf\\_%'
             OR option_name LIKE 'woocommerce\\_btcpaygf\\_%'"
    );
    foreach ((array) $names as $name) {
        if (in_array((string) $name, $keep, true)) {
            continue;
        }
        delete_option((string) $name);
    }
}

/**
 * Fully qualified plugin file for the BTCPay Greenfield WooCommerce gateway,
 * as WordPress identifies it (folder/entry-file).
 */
function cashupay_btcpay_plugin_file(): string {
    return 'btcpay-greenfield-for-woocommerce/btcpay-greenfield-for-woocommerce.php';
}

/**
 * Whether the BTCPay Greenfield WooCommerce gateway plugin is installed AND
 * active.
 */
function cashupay_is_btcpay_plugin_active(): bool {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active(cashupay_btcpay_plugin_file());
}

/**
 * Enable the BTCPay gateway in WooCommerce so it appears at checkout.
 *
 * WooCommerce stores each gateway's config under
 * woocommerce_{gateway_id}_settings; the Greenfield gateway's id is
 * btcpaygf_default (see the plugin's DefaultGateway). Merchants normally flip
 * this by hand under WooCommerce → Settings → Payments; doing it here removes
 * that last manual step so payments work the moment onboarding finishes.
 */
function cashupay_enable_btcpay_gateway(): void {
    // Raw read on purpose: read-modify-write of this option must bypass the
    // discount title suffix payment-discount.php applies at read time.
    $settings = cashupay_gateway_stored_settings();
    $settings['enabled'] = 'yes';
    update_option('woocommerce_btcpaygf_default_settings', $settings);
}

/**
 * Ensure the bundled BareBits gateway logo exists as a media-library
 * attachment and return its id (0 on failure).
 *
 * The attachment id is cached in an option so repeated onboarding renders
 * reuse the same attachment instead of re-uploading the file. The attachment
 * metadata is written WITHOUT intermediate sizes on purpose: the BTCPay
 * gateway resolves its icon via wp_get_attachment_image_src() at the default
 * 'thumbnail' size, and a generated 150x150 crop would truncate the ~154px-
 * wide wordmark. With no registered sizes WordPress falls back to the
 * original file.
 */
function cashupay_ensure_gateway_icon_attachment(): int {
    $existing = (int) get_option('cashupay_gateway_icon_attachment_id', 0);
    if ($existing > 0 && wp_get_attachment_url($existing) !== false) {
        return $existing;
    }

    $src = CASHUPAY_PLUGIN_DIR . '/assets/img/barebits-gateway-logo.png';
    if (!is_file($src)) {
        return 0;
    }
    $contents = file_get_contents($src);
    if ($contents === false) {
        return 0;
    }

    $bits = wp_upload_bits('barebits-gateway-logo.png', null, $contents);
    if (!empty($bits['error'])) {
        return 0;
    }

    $attachmentId = wp_insert_attachment([
        'post_mime_type' => 'image/png',
        'post_title'     => 'BareBits payment gateway logo',
        'post_status'    => 'inherit',
    ], $bits['file']);
    if (is_wp_error($attachmentId) || !$attachmentId) {
        return 0;
    }

    $size = @getimagesize($bits['file']);
    wp_update_attachment_metadata((int) $attachmentId, [
        'width'  => (int) ($size[0] ?? 0),
        'height' => (int) ($size[1] ?? 0),
        'file'   => _wp_relative_upload_path($bits['file']),
    ]);

    update_option('cashupay_gateway_icon_attachment_id', (int) $attachmentId);
    return (int) $attachmentId;
}

/**
 * Apply BareBits branding (checkout title, customer message, gateway logo) to
 * the BTCPay gateway's WooCommerce settings.
 *
 * Deliberately conservative: each field is written only when it is empty or
 * still carries the stock BTCPay default, so a merchant's manual edits under
 * WooCommerce -> Settings -> Payments -> BTCPay survive every re-run of the
 * onboarding flow.
 *
 * The merchant's discount is deliberately NOT part of the stored title: it
 * is appended at read time by payment-discount.php's option filter, so the
 * advertised percentage always tracks the current setting without ever
 * touching a title the merchant may have customized.
 */
function cashupay_apply_btcpay_gateway_branding(): void {
    $optionKey = 'woocommerce_btcpaygf_default_settings';
    // Raw read on purpose: this is a read-modify-write, and reading through
    // the discount title filter would bake the runtime suffix into the
    // stored title.
    $settings = cashupay_gateway_stored_settings();

    // Stock defaults from the BTCPay plugin's DefaultGateway::getTitle() /
    // getDescription(). Anything else means the merchant customized it.
    $stockTitle = 'BTCPay (Bitcoin, Lightning Network, ...)';
    $stockDescription = 'You will be redirected to BTCPay to complete your purchase.';

    $title = trim((string) ($settings['title'] ?? ''));
    if ($title === '' || $title === $stockTitle) {
        $settings['title'] = 'BareBits (Bitcoin + Lightning)';
    }

    $description = trim((string) ($settings['description'] ?? ''));
    if ($description === '' || $description === $stockDescription) {
        $settings['description'] = 'CashApp, PayPal, and Venmo are all Bitcoin wallets. '
            . 'You will be redirected to BareBits to complete this payment.';
    }

    if (empty($settings['icon_media_id'])) {
        $iconId = cashupay_ensure_gateway_icon_attachment();
        if ($iconId > 0) {
            $settings['icon_media_id'] = (string) $iconId;
        }
    }

    update_option($optionKey, $settings);
}

/**
 * Map the BTCPay plugin's "Expired" webhook to wc-failed instead of its stock
 * wc-cancelled. WooCommerce keeps failed orders payable (the order-pay
 * endpoint plus the "Pay" button under My Account → Orders), so the customer
 * can retry after an invoice lapses; a cancelled order loses that affordance
 * and restocks the items.
 *
 * Same conservatism as the branding writer above: the mapping is flipped only
 * while it is unset or still the stock default, so a merchant's deliberate
 * choice under WooCommerce → Settings → Payments → BTCPay survives re-runs.
 * The full mapping array is always written because the plugin's webhook
 * handler indexes every state without isset() checks once the option exists.
 */
function cashupay_apply_btcpay_order_states(): void {
    // Stock defaults from the plugin's OrderStates::getDefaultOrderStateMappings().
    $defaults = [
        'New'                => 'wc-pending',
        'Processing'         => 'wc-on-hold',
        'Settled'            => 'BTCPAY_IGNORE',
        'SettledPaidOver'    => 'wc-processing',
        'Invalid'            => 'wc-failed',
        'Expired'            => 'wc-cancelled',
        'ExpiredPaidPartial' => 'wc-failed',
        'ExpiredPaidLate'    => 'wc-processing',
    ];

    $states = get_option('btcpay_gf_order_states');
    if (!is_array($states)) {
        $states = [];
    }

    $current = (string) ($states['Expired'] ?? '');
    if ($current !== '' && $current !== 'wc-cancelled') {
        return; // The merchant picked something else on purpose.
    }

    update_option(
        'btcpay_gf_order_states',
        array_merge($defaults, $states, ['Expired' => 'wc-failed'])
    );
}

/**
 * Redirect a payer whose invoice expired back to a page where they can pay.
 *
 * The install's payment page links here (CASHUPAY_RETRY_URL_TEMPLATE, written
 * by the installer) as /?cashupay-retry={invoiceId}. Resolves the invoice
 * back to its WooCommerce order via the BTCPay_id order meta (the same lookup
 * the gateway's webhook handler uses), then sends the customer to
 * WooCommerce's order-pay page — where clicking "Pay" makes the gateway
 * notice the old invoice is Expired and mint a fresh one. Orders that no
 * longer need payment (paid meanwhile, cancelled, refunded) go to the
 * order-received page instead, which explains the order's actual state.
 */
function cashupay_maybe_handle_retry(): void {
    // A payer-facing link from the install's payment page. No nonce exists or
    // can exist: the payer has no WordPress session (nonces are bound to one),
    // and the link is minted by the BareBits payment page, which cannot create
    // WordPress nonces — the same class as any public permalink. Defense is
    // capability-free by nature of the action: read-only resolution of an
    // invoice id to a redirect the payer could reach anyway (WooCommerce's
    // own order-pay/order-received pages enforce their own access rules).
    // The id must match the server's invoice-id shape before it touches the
    // database; anything else is ignored outright.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $invoiceId = isset($_GET['cashupay-retry'])
        ? sanitize_text_field((string) wp_unslash($_GET['cashupay-retry']))
        : '';
    // phpcs:enable
    if ($invoiceId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $invoiceId)) {
        return;
    }

    // Without WooCommerce (or if the invoice can't be resolved to exactly one
    // order) the front page beats a dead end — the expired payment page is
    // what linked here, so bouncing back to it would loop.
    $fallback = home_url('/');

    if (!function_exists('wc_get_orders')) {
        wp_safe_redirect($fallback);
        exit;
    }

    // The gateway stores the invoice id in order meta; resolving an invoice
    // back to its order has no other lookup. Bounded by the exactly-one-match
    // requirement below.
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    $orders = wc_get_orders([
        'meta_key' => 'BTCPay_id',
        'meta_value' => $invoiceId,
    ]);
    // phpcs:enable

    if (!is_array($orders) || count($orders) !== 1) {
        wp_safe_redirect($fallback);
        exit;
    }

    $order = $orders[0];
    if ($order->needs_payment()) {
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    wp_safe_redirect($order->get_checkout_order_received_url());
    exit;
}
add_action('template_redirect', 'cashupay_maybe_handle_retry');

/**
 * Whether this WordPress install can install plugins programmatically without
 * prompting for FTP/SSH credentials.
 *
 * WordPress writes plugin files through the WP_Filesystem abstraction. Only the
 * "direct" transport works unattended; ftp/ssh transports need credentials we
 * cannot collect during a headless onboarding step. DISALLOW_FILE_MODS (common
 * on managed hosts) blocks all plugin installs outright. When either check
 * fails we fall back to asking the merchant to install the plugin by hand.
 */
function cashupay_can_install_plugins(): bool {
    if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
        return false;
    }
    if (!function_exists('get_filesystem_method')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    return get_filesystem_method() === 'direct';
}

/**
 * Install (from the WordPress.org plugin directory) and activate the BTCPay
 * Greenfield WooCommerce gateway.
 *
 * Idempotent: if the plugin is already present it is only (re)activated; if it
 * is already active this is a no-op. The 'installed' flag in the result is true
 * only when this call *freshly downloaded* the plugin — the onboarding screen
 * uses it to decide whether to show the "we installed a plugin for you" notice.
 *
 * @return array{success:bool, installed:bool, error?:string, message?:string}
 */
function cashupay_install_btcpay_plugin(): array {
    $pluginFile = cashupay_btcpay_plugin_file();

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $alreadyInstalled = array_key_exists($pluginFile, get_plugins());
    $freshlyInstalled = false;

    if (!$alreadyInstalled) {
        if (!cashupay_can_install_plugins()) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'filesystem',
                'message' => 'This host does not allow unattended plugin installs.',
            ];
        }

        // wordpress.org requires core admin includes to be loaded guarded and
        // consumed immediately: file.php backs the WP_Filesystem the upgrader
        // writes plugin files through, plugins_api() resolves the download
        // link, and Plugin_Upgrader performs the install below.
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $api = plugins_api('plugin_information', [
            'slug' => 'btcpay-greenfield-for-woocommerce',
            'fields' => ['sections' => false],
        ]);
        if (is_wp_error($api)) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'plugins_api',
                'message' => $api->get_error_message(),
            ];
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'install',
                'message' => $result->get_error_message(),
            ];
        }
        if (!$result) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'install',
                'message' => 'The plugin could not be installed.',
            ];
        }
        $freshlyInstalled = true;
    }

    if (!is_plugin_active($pluginFile)) {
        $activated = activate_plugin($pluginFile);
        if (is_wp_error($activated)) {
            return [
                'success' => false,
                'installed' => $freshlyInstalled,
                'error' => 'activate',
                'message' => $activated->get_error_message(),
            ];
        }
    }

    return ['success' => true, 'installed' => $freshlyInstalled];
}

/**
 * One call that makes WooCommerce ready to take payments through the
 * connected BareBits server: ensure the BTCPay gateway plugin is installed +
 * active, point it at the server's Greenfield API, register the webhook over
 * that API, and enable the gateway at checkout. Safe to call repeatedly.
 *
 * Returns a status the onboarding screen renders from:
 *   - 'ready'            everything wired; 'auto_installed' says whether we had
 *                        to fetch the gateway plugin ourselves, and a non-empty
 *                        'replaced_url' names the real BTCPay Server connection
 *                        this call (with consent) just replaced.
 *   - 'existing_btcpay'  a real BTCPay Server is configured and the merchant
 *                        has not consented to replacing it; nothing is touched.
 *   - 'needs_woocommerce' WooCommerce itself isn't active.
 *   - 'needs_plugin'     the gateway plugin is missing and we couldn't install
 *                        it unattended (merchant must add it by hand).
 *   - 'error'            configuration failed after the plugin was available.
 *
 * @return array{status:string, auto_installed:bool, message?:string, current_url?:string, replaced_url?:string, webhook?:mixed}
 */
function cashupay_ensure_woocommerce_integration(string $store_id, string $api_key): array {
    // A real BTCPay Server connection is only replaced after the merchant
    // explicitly consented on the onboarding screen. Without that consent:
    // hands off entirely.
    $takeover = cashupay_btcpay_takeover_state();
    if ($takeover === 'needs_consent') {
        return [
            'status' => 'existing_btcpay',
            'auto_installed' => false,
            'current_url' => get_option('btcpay_gf_url', ''),
        ];
    }

    // The gateway plugin is useless without WooCommerce; don't install it into
    // a site that can't run it.
    if (!class_exists('WooCommerce')) {
        return ['status' => 'needs_woocommerce', 'auto_installed' => false];
    }

    $autoInstalled = false;
    if (!cashupay_is_btcpay_plugin_active()) {
        $install = cashupay_install_btcpay_plugin();
        if (empty($install['success'])) {
            return [
                'status' => 'needs_plugin',
                'auto_installed' => false,
                'message' => $install['message'] ?? '',
            ];
        }
        $autoInstalled = !empty($install['installed']);
    }

    // Consented takeover. Deliberately sequenced after the WooCommerce and
    // gateway-plugin checks: if either is missing, the merchant's old config
    // survives untouched and the consent stays recorded, so a reload after
    // they fix the stack completes the replacement — never a half-torn-down
    // checkout. The wipe makes every writer below (configure, enable,
    // branding, order states) see a blank slate and install BareBits
    // defaults, which is exactly what the consent screen promised.
    $replacedUrl = '';
    if ($takeover === 'consented') {
        $replacedUrl = (string) get_option('btcpay_gf_url', '');
        cashupay_reset_btcpay_plugin_settings();
    }

    $config = cashupay_configure_btcpay_plugin($store_id, $api_key);
    if (empty($config['success'])) {
        return [
            'status' => 'error',
            'auto_installed' => $autoInstalled,
            'message' => $config['message'] ?? 'Could not configure the BTCPay plugin.',
        ];
    }

    cashupay_enable_btcpay_gateway();
    cashupay_apply_btcpay_gateway_branding();
    cashupay_apply_btcpay_order_states();
    cashupay_pin_order_storage_for_sqlite();

    // Consent is single-use: it covered the server that was just replaced.
    // Deleted on every successful wiring (not only a consented takeover) so
    // no stale approval lingers — if the merchant ever reconnects a real
    // BTCPay Server, even the same one, the onboarding must warn again rather
    // than silently re-clobber it.
    delete_option('cashupay_btcpay_override_consent');

    return [
        'status' => 'ready',
        'auto_installed' => $autoInstalled,
        'replaced_url' => $replacedUrl,
        'webhook' => $config['webhook'] ?? null,
    ];
}

/**
 * Decide whether the wiring should pin WooCommerce's order storage to the
 * legacy posts table. Pure (no WordPress calls) so tests/php can pin the
 * matrix.
 *
 * Why pin at all: on the SQLite database drop-in, HPOS's DECIMAL total
 * column is a REAL. The drop-in stringifies the value it reads back the way
 * PHP prints floats — a 0.00001500 BTC total becomes "1.5E-5" — and
 * WooCommerce's wc_format_decimal() strips every character outside
 * [0-9.-] during order hydration, mangling it to 1.50000000. Every consumer
 * of the order total (this gateway's invoice amount first among them, but
 * also order emails, refunds, reports) sees the wrong number, and no filter
 * fires early enough to repair it. The posts-table store keeps totals as
 * plain-decimal meta strings and is immune. WooCommerce auto-enables HPOS
 * on every new shop, so SQLite hosts hit this out of the box.
 *
 * The one state left alone is HPOS already on WITH orders already in its
 * table: flipping storage then would make those orders invisible (they
 * only migrate back via WooCommerce's own sync tooling), which is worse
 * than degraded totals. Everything else on a SQLite host is pinned —
 * including HPOS freshly auto-enabled before any order exists.
 *
 * Returns 'pin' or 'leave'.
 */
function cashupay_order_storage_pin_decision(bool $sqliteEngine, bool $hposEnabled, int $hposOrderCount): string {
    if (!$sqliteEngine) {
        return 'leave'; // real MySQL DECIMAL columns round-trip exactly
    }
    if ($hposEnabled && $hposOrderCount > 0) {
        return 'leave'; // never orphan existing HPOS orders
    }
    return 'pin';
}

/**
 * Apply cashupay_order_storage_pin_decision on this host: pin order storage
 * to the posts table, and drop the "newly installed" flag WooCommerce's
 * deferred HPOS-for-new-shops job keys off (without that, the job flips
 * HPOS back on minutes after the wiring ran).
 *
 * Runs on every successful wiring — first onboarding and every re-run —
 * so a shop whose host later gains the auto-enabled flag gets re-pinned
 * the next time the merchant re-wires.
 */
function cashupay_pin_order_storage_for_sqlite(): void {
    global $wpdb;
    $sqlite = (defined('DB_ENGINE') && DB_ENGINE === 'sqlite')
        || class_exists('WP_SQLite_Translator', false)
        || (isset($wpdb) && is_a($wpdb, 'WP_SQLite_DB'));
    if (!$sqlite) {
        return;
    }

    $hposEnabled = get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    $hposOrderCount = 0;
    if ($hposEnabled) {
        // The table exists whenever WooCommerce has HPOS enabled; suppress
        // the drop-in's error chatter anyway in case it does not.
        $suppress = $wpdb->suppress_errors();
        // Direct, uncached on purpose: WooCommerce's HPOS table has no
        // count API this early in boot, and the one-shot pin decision must
        // see the live row count.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $hposOrderCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"
        );
        $wpdb->suppress_errors($suppress);
    }

    if (cashupay_order_storage_pin_decision($sqlite, $hposEnabled, $hposOrderCount) === 'pin') {
        try {
            update_option('woocommerce_custom_orders_table_enabled', 'no');
        } catch (\Throwable $e) {
            // WooCommerce THROWS from pre_update_option when orders are
            // pending sync between the two stores
            // (CustomOrdersTableController::process_pre_update_option). The
            // pin-decision inputs make that unreachable in any state
            // WooCommerce itself can produce, but a shop in a state we did
            // not foresee must degrade to unpinned totals — never to a
            // fatal that breaks the wiring and the merchant's onboarding.
        }
        update_option('woocommerce_newly_installed', 'no');
    }
}

/**
 * Point the BTCPay WooCommerce plugin at the connected BareBits server.
 */
function cashupay_configure_btcpay_plugin(string $store_id, string $api_key): array {
    if (cashupay_is_real_btcpay_configured()) {
        return [
            'success' => false,
            'error' => 'existing_btcpay',
            'current_url' => get_option('btcpay_gf_url', ''),
            'message' => 'A real BTCPay Server is already configured. '
                       . 'Disconnect it first via WooCommerce > Settings > Payments > BTCPay.'
        ];
    }

    // The gateway base: api.php's query-transport form for a same-origin
    // alongside install (one loopback deep on every host — see
    // cashupay_gateway_base_url), the canonical URL for remote servers.
    update_option('btcpay_gf_url', cashupay_gateway_server_url());
    update_option('btcpay_gf_api_key', $api_key);
    update_option('btcpay_gf_store_id', $store_id);

    // Register the invoice-events webhook with the BareBits server.
    $webhookResult = cashupay_register_webhook($store_id, $api_key);
    if (empty($webhookResult['success'])) {
        return [
            'success' => false,
            'error' => 'webhook',
            'message' => 'Webhook registration failed: ' . ($webhookResult['error'] ?? 'unknown'),
        ];
    }

    return [
        'success' => true,
        'webhook' => $webhookResult
    ];
}

/**
 * Register (or adopt) the webhook the BTCPay WooCommerce plugin listens on,
 * over the BareBits server's Greenfield webhook API.
 *
 * The gateway expects deliveries at /?wc-api=btcpaygf_default and reads the
 * shared HMAC secret from the btcpay_gf_webhook option. The create response
 * is the only place the API reveals the secret, so: reuse the stored option
 * when the server still lists that webhook; otherwise delete any stale
 * webhook for our URL (its secret is unrecoverable) and create a fresh one.
 *
 * @return array{success:bool, webhook_id?:string, existing?:bool, error?:string}
 */
function cashupay_register_webhook(string $store_id, string $api_key): array {
    $webhookUrl = site_url('/?wc-api=btcpaygf_default');
    $base = '/api/v1/stores/' . rawurlencode($store_id) . '/webhooks';

    $list = cashupay_api_request('GET', $base, null, $api_key);
    if ($list['error'] !== null || $list['code'] !== 200 || !is_array($list['body'])) {
        return ['success' => false, 'error' => $list['error'] ?? ('HTTP ' . $list['code'] . ' listing webhooks')];
    }

    $stored = get_option('btcpay_gf_webhook');
    foreach ($list['body'] as $hook) {
        if (($hook['url'] ?? '') !== $webhookUrl) {
            continue;
        }
        if (is_array($stored) && ($stored['id'] ?? '') === ($hook['id'] ?? null)
                && !empty($stored['secret'])) {
            // Server and option agree; the stored secret is still good.
            return ['success' => true, 'webhook_id' => (string) $hook['id'], 'existing' => true];
        }
        // A webhook for our URL whose secret we no longer hold — replace it.
        cashupay_api_request('DELETE', $base . '/' . rawurlencode((string) $hook['id']), null, $api_key);
    }

    $create = cashupay_api_request('POST', $base, [
        'url' => $webhookUrl,
        'enabled' => true,
        'authorizedEvents' => [
            'everything' => false,
            'specificEvents' => [
                'InvoiceCreated',
                'InvoiceReceivedPayment',
                'InvoiceProcessing',
                'InvoiceSettled',
                'InvoiceExpired',
                'InvoiceInvalid',
            ],
        ],
    ], $api_key);
    if ($create['error'] !== null || $create['code'] !== 200 || empty($create['body']['secret'])) {
        return ['success' => false, 'error' => $create['error'] ?? ('HTTP ' . $create['code'] . ' creating webhook')];
    }

    update_option('btcpay_gf_webhook', [
        'id' => (string) $create['body']['id'],
        'url' => $webhookUrl,
        'secret' => (string) $create['body']['secret'],
    ]);

    return ['success' => true, 'webhook_id' => (string) $create['body']['id'], 'existing' => false];
}
