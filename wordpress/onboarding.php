<?php
/**
 * BareBits plugin — onboarding flow.
 *
 * Walks the merchant from "no server" to "WooCommerce takes Bitcoin":
 *
 *   1. Choose: connect an existing BareBits server by URL, or install
 *      BareBits alongside WordPress (see installer.php). The install-
 *      alongside server checks render right on this chooser (the option is
 *      disabled, and its POST refused, while one fails).
 *   2. Get credentials: URL mode pairs via the server's BTCPay-compatible
 *      /api-keys/authorize redirect flow; install mode collects them through
 *      the one-time provisioning handshake after the operator finishes the
 *      BareBits setup wizard.
 *   3. Ask the Bitcoin checkout discount, then wire WooCommerce (gateway
 *      plugin + webhook + branding; payment-discount.php applies the
 *      discount at checkout from the saved option).
 *
 * Rendering happens inside the wp-admin "BareBits" page (admin-menu.php
 * calls cashupay_render_onboarding()); actions POST to admin-post.php.
 * License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_cashupay_choose_mode', 'cashupay_handle_choose_mode');
add_action('admin_post_cashupay_run_install', 'cashupay_handle_run_install');
add_action('admin_post_cashupay_collect_provision', 'cashupay_handle_collect_provision');
add_action('admin_post_cashupay_provision_return', 'cashupay_handle_provision_return');
// Logged-out variant: a wp-admin session that expired mid-wizard would
// otherwise land the merchant on a blank admin-post.php page. Send them
// through the login form and back to the onboarding page instead.
add_action('admin_post_nopriv_cashupay_provision_return', 'cashupay_handle_provision_return_nopriv');
add_action('admin_post_cashupay_start_pairing', 'cashupay_handle_start_pairing');
add_action('admin_post_cashupay_finish', 'cashupay_handle_finish');
add_action('admin_post_cashupay_reset_onboarding', 'cashupay_handle_reset_onboarding');
// The pairing callback is reached by a POST the BareBits server's approval
// page auto-submits from the merchant's browser. It cannot rely on the
// wp-admin auth cookie (a cross-site POST may not carry it), so it is
// registered for both auth states and authenticated by the single-use state
// token we minted when the pairing started.
add_action('admin_post_cashupay_pairing_callback', 'cashupay_handle_pairing_callback');
add_action('admin_post_nopriv_cashupay_pairing_callback', 'cashupay_handle_pairing_callback');

/** How long a started pairing redirect stays collectable. */
const CASHUPAY_PAIRING_WINDOW_SECONDS = 900;

/** Where every handler sends the merchant back to. */
function cashupay_onboarding_url(): string {
    return admin_url('admin.php?page=cashupay');
}

/**
 * Stash a one-shot notice for the next onboarding render. Site-wide rather
 * than per-user on purpose: the pairing callback runs WITHOUT a wp-admin
 * session (a cross-site POST from the BareBits approval page may not carry
 * the auth cookie), and its outcome still has to reach the admin who started
 * the pairing. Onboarding is inherently a single-admin flow.
 */
function cashupay_flash(string $kind, string $message): void {
    set_transient('cashupay_flash', ['kind' => $kind, 'message' => $message], 300);
}

function cashupay_take_flash(): ?array {
    $flash = get_transient('cashupay_flash');
    if (is_array($flash)) {
        delete_transient('cashupay_flash');
        return $flash;
    }
    return null;
}

/**
 * The onboarding step to render, derived from stored state:
 *   'choose'    no mode picked yet
 *   'install'   install mode, installer not run yet
 *   'provision' install mode, waiting for the BareBits wizard + handshake
 *   'pair'      url mode, no credentials yet
 *   'wire'      credentials in hand; discount question + WooCommerce wiring
 *   'done'      fully wired
 */
function cashupay_onboarding_step(): string {
    if (cashupay_is_configured()) {
        return 'done';
    }
    $mode = cashupay_mode();
    if ($mode === '') {
        return 'choose';
    }
    $haveCreds = get_option('cashupay_store_id', '') !== '' && get_option('cashupay_api_key', '') !== '';
    if ($haveCreds) {
        return 'wire';
    }
    if ($mode === 'install') {
        return get_option('cashupay_install_dir', '') === '' ? 'install' : 'provision';
    }
    return 'pair';
}

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------

/**
 * Every admin-post handler below opens with the same two lines, deliberately
 * inline rather than behind a helper: the capability check authorizes, the
 * nonce check (check_admin_referer wp_die()s on failure) proves intent — and
 * keeping both literally inside each handler lets any reviewer or static
 * scanner verify at a glance that no handler skips them
 * (test_wp_plugin_check.py enforces exactly that).
 */
function cashupay_admin_post_denied(): void {
    wp_die(esc_html__('Sorry, you are not allowed to do that.', 'barebits-lightning-payments-via-bitcoin'), 403);
}

/** Step 1: the merchant picked a mode (and, for 'url', gave the server URL). */
function cashupay_handle_choose_mode(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    check_admin_referer('cashupay_choose_mode');

    $mode = sanitize_key(wp_unslash($_POST['cashupay_mode'] ?? ''));
    if ($mode === 'url') {
        $url = rtrim(trim(sanitize_text_field(wp_unslash((string) ($_POST['cashupay_server_url'] ?? '')))), '/');
        $probe = cashupay_probe_server($url);
        if (empty($probe['ok'])) {
            cashupay_flash('error', $probe['message']);
            wp_safe_redirect(cashupay_onboarding_url());
            exit;
        }
        if (strpos($url, 'http://') === 0 && !cashupay_is_same_host_url($url)) {
            cashupay_flash('warning', 'That server is reachable, but over plain HTTP. Payments and API keys will travel unencrypted — use HTTPS if at all possible.');
        }
        update_option('cashupay_mode', 'url');
        update_option('cashupay_server_url', $url);
    } elseif ($mode === 'install') {
        if (!cashupay_installer_available()) {
            cashupay_flash('error', 'This build of the plugin cannot install BareBits alongside '
                . 'WordPress — connect a BareBits server you run yourself by URL instead.');
            wp_safe_redirect(cashupay_onboarding_url());
            exit;
        }
        // Save the folder name BEFORE the preflight so the writable-location
        // check resolves the merchant's Advanced choice, not the default —
        // the chooser's table only ever showed the saved/default target.
        $dirname = sanitize_file_name(wp_unslash((string) ($_POST['cashupay_install_dirname'] ?? '')));
        update_option('cashupay_install_dirname', $dirname, false);
        $failed = cashupay_install_preflight_failure();
        if ($failed !== null) {
            cashupay_flash('error', 'This host does not pass the server checks for installing '
                . 'alongside (' . $failed . '). Fix that and try again, or connect a BareBits '
                . 'server running elsewhere by URL.');
        } else {
            update_option('cashupay_mode', 'install');
        }
    } else {
        cashupay_flash('error', 'Pick one of the two options.');
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/** Install mode: download + unpack + configure the BareBits release. */
function cashupay_handle_run_install(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    check_admin_referer('cashupay_run_install');

    if (!cashupay_installer_available()) {
        cashupay_flash('error', 'This build of the plugin cannot install BareBits alongside '
            . 'WordPress — connect a BareBits server you run yourself by URL instead.');
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    // Conditions can regress between the chooser (which gated on the same
    // checks) and this click — a permissions change, a removed extension.
    // Installing anyway would leave a server this host cannot run.
    $failed = cashupay_install_preflight_failure();
    if ($failed !== null) {
        cashupay_flash('error', 'This host no longer passes the server checks (' . $failed . ').');
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    $result = cashupay_run_install((string) get_option('cashupay_install_dirname', ''));
    if (empty($result['ok'])) {
        cashupay_flash('error', $result['message']);
    } else {
        // The next render embeds the setup wizard right below this notice, so
        // no "come back here" choreography — just say where the install went.
        $message = 'BareBits is installed at ' . $result['url'] . '.'
            . (empty($result['verified']) ? ' (Note: this release published no checksums; the download was TLS-protected but not checksum-verified.)' : '');
        // One question gates payments and is worth answering while the
        // merchant is still here: can this site reach its own URLs over HTTP
        // at all? (The WP-cron heartbeat, the API bridge, and checkout's
        // Greenfield calls all ride loopback requests.) The probe goes to the
        // install's api.php directly — see cashupay_install_loopback_verdict
        // on why the canonical /api/v1 form must NOT be probed from here: on
        // rewrite-hostile hosts with tight worker pools (Local WP) that
        // chain starves, and it used to cry "loopback blocked" on sites
        // whose loopback works fine.
        $verdict = cashupay_install_loopback_verdict($result['url']);
        if ($verdict === 'ok') {
            cashupay_flash('success', $message);
        } elseif ($verdict === 'unreachable') {
            cashupay_flash('warning', $message . ' Heads up: this WordPress site cannot make HTTP '
                . 'requests to its own URL (a firewall or hosting "loopback" restriction). Setup '
                . 'can still complete, but taking payments needs those requests — ask your host '
                . 'about allowing loopback requests.');
        } else { // 'unexpected': something answered, but not the install's API
            cashupay_flash('warning', $message . ' Heads up: the install\'s API did not answer as '
                . 'expected — something on this site (a security plugin, or the web server\'s '
                . 'configuration) may be intercepting requests to it. Setup can still complete, '
                . 'but taking payments needs the install\'s API to answer.');
        }
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Collect credentials through the handshake, store them, and flash the
 * outcome. Shared by the manual "I finished the wizard" button and the
 * wizard's own return link (cashupay_handle_provision_return).
 */
function cashupay_collect_provision_and_store(): void {
    if (!cashupay_installer_available()) {
        cashupay_flash('error', 'This build of the plugin cannot finish an install-alongside '
            . 'setup. Install the full BareBits plugin from the GitHub releases to continue, '
            . 'or use "Start over" and connect the server by URL.');
        return;
    }
    $result = cashupay_collect_provision();
    if ($result['status'] === 'pending') {
        cashupay_flash('warning', 'BareBits setup is not finished yet — complete the wizard, then try again.');
    } elseif ($result['status'] === 'ready') {
        update_option('cashupay_store_id', $result['storeId']);
        update_option('cashupay_api_key', $result['apiKey'], false);
        update_option('cashupay_cron_key', $result['cronKey'], false);
        cashupay_cron_reschedule();
        // Prove the heartbeat loop RIGHT NOW, with the merchant watching:
        // one synchronous ping with the freshly collected key. Success seeds
        // cashupay_cron_last_ok so the stale-heartbeat warning starts from a
        // known-good point; failure is worth a warning while the merchant is
        // still here to act on it, instead of a silent 10-minute backoff.
        // Ping mode: proves routing + key without triggering the install's
        // first-ever FULL cron pass inside this blocked interactive request
        // (see cashupay_fire_cron_endpoint) — the scheduled tick, due a
        // minute out, does the first real run.
        if (cashupay_fire_cron_endpoint(15, true)) {
            cashupay_flash('success', 'Connected! One more step below.');
        } else {
            cashupay_flash('warning', 'Connected! One more step below. (Heads up: a test request to the '
                . 'install\'s background-task endpoint failed — payments will still work, but '
                . 'confirmations may lag until your host allows this site to request its own URLs.)');
        }
    } else {
        cashupay_flash('error', $result['message']);
    }
}

/** Install mode: try to collect credentials through the handshake. */
function cashupay_handle_collect_provision(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    check_admin_referer('cashupay_collect_provision');

    cashupay_collect_provision_and_store();
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Install mode: the wizard's completion screen sent the operator back here
 * (CASHUPAY_MANAGED_RETURN_URL, written by the installer). Collect the
 * credentials and land on the next onboarding step — the merchant clicks
 * nothing on the WordPress side.
 *
 * Capability-gated but deliberately nonce-free: the link is minted at
 * install time and rendered by the BareBits wizard, which cannot create
 * WordPress nonces (and must not — it knows nothing of WordPress). The
 * action is idempotent and merely advances the plugin's own onboarding
 * state, the same thing the nonce-protected manual button does.
 */
function cashupay_handle_provision_return(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    // Nothing left to collect (already collected, or not in install mode):
    // just land on the onboarding page at whatever step it is on. Covers a
    // re-click of the wizard's finish button after the handshake completed.
    if (cashupay_mode() === 'install'
            && (string) get_option('cashupay_provision_token', '') !== '') {
        cashupay_collect_provision_and_store();
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/** See the admin_post_nopriv registration above. */
function cashupay_handle_provision_return_nopriv(): void {
    wp_safe_redirect(wp_login_url(cashupay_onboarding_url()));
    exit;
}

/** URL mode: mint the state token and send the merchant to the approval page. */
function cashupay_handle_start_pairing(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    check_admin_referer('cashupay_start_pairing');

    $server = cashupay_server_url();
    if ($server === '') {
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    $state = bin2hex(random_bytes(16));
    update_option('cashupay_pairing_expected', ['state' => $state, 'at' => time()], false);

    $redirect = admin_url('admin-post.php') . '?action=cashupay_pairing_callback&state=' . $state;
    $query = [
        'applicationName=' . rawurlencode(get_bloginfo('name') ?: 'WordPress'),
        'applicationIdentifier=' . rawurlencode('cashupay-wordpress'),
        'redirect=' . rawurlencode($redirect),
        'strict=true',
    ];
    // Repeated bare permissions= parameters, the BTCPay convention the
    // server's authorize endpoint parses natively.
    foreach ([
        'btcpay.store.canviewinvoices',
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canmodifyinvoices',
        'btcpay.store.webhooks.canmodifywebhooks',
    ] as $permission) {
        $query[] = 'permissions=' . rawurlencode($permission);
    }

    // Always the real file, never the pretty /api-keys/authorize rewrite.
    // Any host that ignores .htaccess and has no PATH_INFO routing (Local
    // WP's nginx, hardened shared hosts) 404s the extension-less path —
    // and that is just as true for a REMOTE server connected by URL as for
    // the alongside install (a merchant hit exactly that pairing with an
    // existing server). authorize.php executes anywhere BareBits itself
    // runs — the whole app is served as .php files — and it builds its
    // self-post URL from its own request path, so the entire approval flow
    // stays on the .php form.
    // Deliberately wp_redirect, not wp_safe_redirect: the approval page lives
    // on the merchant's own BareBits server — an external host by design,
    // which the safe-redirect allowlist would refuse. The URL is built from
    // the stored server URL the merchant configured and probed.
    // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
    wp_redirect($server . '/api-keys/authorize.php?' . implode('&', $query));
    exit;
}

/**
 * URL mode: the approval page POSTed the minted key back (apiKey, storeId,
 * permissions). Authenticated by the single-use state token, then the key is
 * verified against the server before anything is stored.
 */
function cashupay_handle_pairing_callback(): void {
    // No WordPress nonce on purpose (see the action registration above), and
    // none is technically possible: the POST arrives cross-site from the
    // BareBits approval page, which cannot mint a WordPress nonce — and even
    // one minted here at pairing start could never verify, because a
    // cross-site POST does not carry the wp-admin auth cookie (SameSite) and
    // WordPress nonces are bound to the logged-in session. The state token
    // below is the nonce-equivalent, strictly stronger than a stock nonce:
    // 128 bits from random_bytes (a nonce is a truncated hash), single-use
    // (deleted before checking, success or not — a stock nonce is replayable
    // for 12-24h), time-boxed to 15 minutes, and compared with hash_equals.
    // On top of that, nothing is stored until the returned credentials are
    // verified against the server the ADMIN configured (an attacker-supplied
    // key for a foreign server cannot pass), and the handler only ever writes
    // the plugin's own pairing options and redirects — it echoes nothing back.
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
    $expected = get_option('cashupay_pairing_expected');
    delete_option('cashupay_pairing_expected'); // single use, success or not

    $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
    if (!is_array($expected)
            || $state === ''
            || !hash_equals((string) ($expected['state'] ?? ''), $state)
            || (time() - (int) ($expected['at'] ?? 0)) > CASHUPAY_PAIRING_WINDOW_SECONDS) {
        wp_die(esc_html__('This pairing link is no longer valid. Start the pairing again from the BareBits page in wp-admin.', 'barebits-lightning-payments-via-bitcoin'), 403);
    }

    if (isset($_GET['error']) || empty($_POST['apiKey']) || empty($_POST['storeId'])) {
        cashupay_flash('error', 'Pairing was denied or came back incomplete. You can start it again below.');
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    $apiKey = sanitize_text_field(wp_unslash((string) $_POST['apiKey']));
    $storeId = sanitize_text_field(wp_unslash((string) $_POST['storeId']));
    // phpcs:enable

    // Prove the pair is real against the server before trusting it: listing
    // the store's webhooks needs both a valid key and access to that store.
    $check = cashupay_api_request('GET', '/api/v1/stores/' . rawurlencode($storeId) . '/webhooks', null, $apiKey);
    if ($check['code'] !== 200) {
        cashupay_flash('error', 'The server rejected the pairing result (HTTP ' . $check['code'] . '). Start the pairing again.');
        wp_safe_redirect(cashupay_onboarding_url());
        exit;
    }

    update_option('cashupay_store_id', $storeId);
    update_option('cashupay_api_key', $apiKey, false);
    cashupay_flash('success', 'Paired with your BareBits server! One more step below.');
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Final step: save the discount answer (when asked), record takeover consent
 * (when granted), and run the WooCommerce wiring.
 */
function cashupay_handle_finish(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    check_admin_referer('cashupay_finish');

    if (isset($_POST['cashupay_discount_percent'])) {
        $percent = cashupay_parse_discount_percent(sanitize_text_field(wp_unslash((string) $_POST['cashupay_discount_percent'])));
        if ($percent === null) {
            cashupay_flash('error', 'The discount must be a number between 0 and 100 (up to two decimal places).');
            wp_safe_redirect(cashupay_onboarding_url());
            exit;
        }
        cashupay_save_discount_percent($percent);
    }

    if (!empty($_POST['cashupay_btcpay_override_consent'])) {
        cashupay_record_btcpay_override_consent();
    }

    $storeId = (string) get_option('cashupay_store_id', '');
    $apiKey = (string) get_option('cashupay_api_key', '');
    $status = cashupay_ensure_woocommerce_integration($storeId, $apiKey);

    if (($status['status'] ?? '') === 'ready') {
        update_option('cashupay_wired_at', time());
        cashupay_flash('success', 'Done — WooCommerce now takes Bitcoin through BareBits.');
    } elseif (($status['status'] ?? '') === 'existing_btcpay') {
        cashupay_flash('warning', 'A BTCPay Server is already connected at ' . ($status['current_url'] ?? '') . '. Tick the consent box below to replace that connection.');
    } elseif (($status['status'] ?? '') === 'needs_woocommerce') {
        cashupay_flash('warning', 'WooCommerce is not active. Install and activate WooCommerce, then click "Finish" again.');
    } else {
        cashupay_flash('error', 'Wiring failed: ' . ($status['message'] ?? $status['status'] ?? 'unknown error'));
    }
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

/**
 * Start over: forget the chosen mode and connection details. Never touches an
 * installed BareBits server or its data — it only resets the plugin's own
 * state.
 *
 * When an alongside install exists, the install RECORD survives the reset:
 * its location, its admin password, its SSO key, and its cron key. That
 * server keeps running with real money behind that password — the merchant
 * never chose it and can't recover it, so deleting our only copy would lock
 * them out of their own wallet UI. The chooser renders a reconnect hint
 * (with a password reveal) from these surviving options, and the WP-cron
 * pinger keeps ticking the install (it has no crontab of its own — the
 * plugin promised it the heartbeat at provision time).
 */
function cashupay_handle_reset_onboarding(): void {
    if (!current_user_can('manage_options')) {
        cashupay_admin_post_denied();
    }
    check_admin_referer('cashupay_reset_onboarding');

    $options = [
        'cashupay_mode', 'cashupay_server_url', 'cashupay_store_id',
        'cashupay_api_key', 'cashupay_cron_key', 'cashupay_wired_at',
        'cashupay_discount_percent', 'cashupay_pairing_expected',
        'cashupay_provision_token', 'cashupay_admin_password',
        'cashupay_sso_key', 'cashupay_install_dir',
        'cashupay_install_url', 'cashupay_install_data_dir',
        'cashupay_install_dirname', 'cashupay_btcpay_override_consent',
    ];
    $hasInstall = (string) get_option('cashupay_install_dir', '') !== '';
    if ($hasInstall) {
        // Make sure the install's own URL is recorded (backfills installs
        // that predate the option) BEFORE the mode is forgotten — afterwards
        // it can no longer be derived from the connected-server URL.
        cashupay_install_url();
        $options = array_values(array_diff($options, [
            'cashupay_server_url', 'cashupay_install_dir',
            'cashupay_install_url', 'cashupay_install_data_dir',
            'cashupay_install_dirname', 'cashupay_admin_password',
            'cashupay_sso_key',
            // The cron key too: the install has no crontab of its own (this
            // plugin promised it the heartbeat at provision time) and the
            // provisioning handshake that minted the key is one-time. The
            // pinger keeps ticking the install through the reset.
            'cashupay_cron_key',
        ]));
    }
    foreach ($options as $option) {
        delete_option($option);
    }
    if (!cashupay_cron_needed()) {
        cashupay_cron_unschedule();
    }
    cashupay_flash('success', $hasInstall
        ? 'Onboarding reset. Your BareBits install keeps running and nothing on its side was '
            . 'removed; its address and admin password stay saved here so you can reconnect it below.'
        : 'Onboarding reset. Nothing on the BareBits side was removed.');
    wp_safe_redirect(cashupay_onboarding_url());
    exit;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/** Render the onboarding UI for the current step (called by admin-menu.php). */
function cashupay_render_onboarding(): void {
    $step = cashupay_onboarding_step();
    $flash = cashupay_take_flash();
    ?>
    <div class="wrap cashupay-wrap">
        <h1>BareBits</h1>
        <?php if ($flash): ?>
            <div class="notice notice-<?php echo esc_attr($flash['kind'] === 'error' ? 'error' : ($flash['kind'] === 'warning' ? 'warning' : 'success')) ?>"><p><?php echo esc_html($flash['message']); ?></p></div>
        <?php endif; ?>
        <?php
        switch ($step) {
            case 'choose':    cashupay_render_step_choose(); break;
            // Install-mode steps degrade when this build ships no installer
            // (the wordpress.org distribution): a site can land here mid-flow
            // after swapping plugin builds, and must get an exit, not a fatal.
            case 'install':
                cashupay_installer_available()
                    ? cashupay_render_step_install()
                    : cashupay_render_step_install_unavailable();
                break;
            case 'provision':
                cashupay_installer_available()
                    ? cashupay_render_step_provision()
                    : cashupay_render_step_install_unavailable();
                break;
            case 'pair':      cashupay_render_step_pair(); break;
            case 'wire':      cashupay_render_step_wire(); break;
        }
        if ($step !== 'choose') {
            cashupay_render_reset_form();
        }
        cashupay_render_maintenance_guard();
        ?>
    </div>
    <?php
}

/**
 * Gate every onboarding form on WordPress not being in maintenance mode.
 *
 * WordPress auto-updates (wp-cron: core/plugin/translation updates) put the
 * WHOLE site behind the "Briefly unavailable for scheduled maintenance"
 * screen — every wp URL answers 503 until the update finishes, usually
 * under a minute. A merchant who loaded this page just before that window
 * and clicks a button during it lands on the maintenance screen, their
 * choice unsaved (WordPress checks its .maintenance flag before any plugin
 * loads, so nothing server-side here can intercept it). Same failure, same
 * cure as the setup wizard's return-to-WordPress handoff (setup.php): probe
 * first, and only submit once WordPress answers — the probe-and-submit
 * logic lives in assets/js/maintenance-guard.js, keyed off the waiting note
 * rendered here (which also carries the probe URL).
 */
function cashupay_render_maintenance_guard(): void {
    ?>
    <div class="notice notice-warning inline" id="cashupay-maintenance-waiting" hidden
         data-probe-url="<?php echo esc_attr(admin_url('admin-post.php')); ?>">
        <p>WordPress is briefly updating itself (maintenance mode) &mdash; continuing automatically
           as soon as it's back, usually under a minute. Clicking again goes ahead right away.</p>
    </div>
    <?php
}

/** The ✅/❌ server-checks table, shared by the chooser and the install confirmation. */
function cashupay_render_preflight_table(array $checks): void {
    ?>
    <table class="widefat striped cashupay-table">
        <tbody>
        <?php foreach ($checks as $label => $check): ?>
            <tr>
                <td><?php echo esc_html($check['ok'] ? '✅' : '❌'); ?></td>
                <td><?php echo esc_html($label); ?></td>
                <td class="description"><?php echo esc_html($check['detail']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function cashupay_render_step_choose(): void {
    // A surviving install record (a "Start over" or an earlier plugin
    // removal left an alongside install running) gets a reconnect hint: the
    // install's own address prefilled for URL mode, and the saved admin
    // password revealable — pairing needs it, and the merchant never chose
    // one.
    $existingInstall = cashupay_install_url();
    // The install-alongside option only exists in builds that ship the
    // installer (the GitHub distribution) — the wordpress.org build renders
    // the URL-connect form alone.
    $installerAvailable = cashupay_installer_available();
    // The server checks that used to live on their own page after picking
    // "install alongside" — surfaced below the choices instead, so the
    // merchant sees whether this host qualifies while still choosing and
    // the separate checks page is gone from the flow. All checks are local
    // and cheap (extensions, writability): no HTTP, safe on every render.
    $checks = $installerAvailable ? cashupay_install_preflight() : [];
    $installOk = $installerAvailable;
    foreach ($checks as $check) {
        $installOk = $installOk && $check['ok'];
    }
    ?>
    <?php if ($installerAvailable): ?>
        <p>Accept Bitcoin (on-chain and Lightning) in WooCommerce. Where should your BareBits server live?</p>
    <?php else: ?>
        <p>Accept Bitcoin (on-chain and Lightning) in WooCommerce by connecting this shop to your
           self-hosted BareBits server.</p>
    <?php endif; ?>
    <?php if ($existingInstall !== ''): ?>
        <div class="notice notice-info inline cashupay-notice-lead">
            <p>
                A BareBits server installed earlier by this plugin is still running at
                <code><?php echo esc_html($existingInstall); ?></code> (its data and funds are untouched).
                To reconnect it, pick "I already run a BareBits server" below — the address is
                prefilled — and sign in with its saved admin password when asked:
                <code id="cashupay-admin-password">••••••••••••</code>
                <button type="button" class="button button-small" id="cashupay-reveal-password"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('cashupay_reveal_password')); ?>">Reveal</button>
            </p>
            <?php // Button behavior: assets/js/admin-reveal-password.js. ?>
        </div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('cashupay_choose_mode'); ?>
        <input type="hidden" name="action" value="cashupay_choose_mode">
        <table class="form-table" role="presentation">
            <tr>
                <td class="cashupay-radio-cell"><input type="radio" name="cashupay_mode" value="url" id="cashupay-mode-url" checked></td>
                <td>
                    <label for="cashupay-mode-url"><strong>I already run a BareBits server</strong></label>
                    <p class="description">Connect this shop to an existing server by URL.</p>
                    <input type="url" name="cashupay_server_url" id="cashupay-server-url" class="regular-text"
                           placeholder="https://pay.example.com" value="<?php echo esc_attr($existingInstall); ?>">
                </td>
            </tr>
            <?php if ($installerAvailable): ?>
            <tr>
                <td class="cashupay-radio-cell"><input type="radio" name="cashupay_mode" value="install" id="cashupay-mode-install" <?php disabled(!$installOk); ?>></td>
                <td>
                    <label for="cashupay-mode-install"><strong>Install BareBits alongside WordPress</strong></label>
                    <p class="description">Downloads the latest stable BareBits release from GitHub and installs it next to this WordPress site (its own folder, its own license, updated independently of this plugin).</p>
                    <?php if (!$installOk): ?>
                        <p class="description"><strong>This host does not pass the server checks below yet, so this option is unavailable.</strong></p>
                    <?php endif; ?>
                    <details>
                        <summary>Advanced: folder name</summary>
                        <input type="text" name="cashupay_install_dirname" class="regular-text" placeholder="barebits"
                               value="<?php echo esc_attr((string) get_option('cashupay_install_dirname', '')); ?>">
                        <p class="description">Folder under your site the server is installed into (default <code>barebits</code>, served at <?php echo esc_html(site_url('/barebits')); ?>).</p>
                    </details>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php if ($installerAvailable): ?>
            <h2 class="cashupay-subheading">Server checks for installing alongside</h2>
            <?php cashupay_render_preflight_table($checks); ?>
            <?php if (!$installOk): ?>
                <p><strong>Fix the failed checks above, then reload this page.</strong> If your host cannot pass them, you can still run BareBits on another host and connect it by URL with the first option.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php submit_button('Continue'); ?>
    </form>
    <?php // URL-field validation sync: assets/js/onboarding.js. ?>
    <?php
}

/**
 * Rendered in place of the install/provision steps when the site is in
 * install mode but THIS build ships no installer — a mid-onboarding swap to
 * the wordpress.org build. The merchant gets the two real exits instead of a
 * fatal: the full plugin from GitHub, or a reset into URL mode.
 */
function cashupay_render_step_install_unavailable(): void {
    ?>
    <h2>Install BareBits alongside WordPress</h2>
    <div class="notice notice-warning inline cashupay-notice-lead">
        <p>Onboarding was started in install-alongside mode, but this build of the plugin cannot
           manage that installation (the wordpress.org edition connects to a server you run
           yourself). To continue the install-alongside setup, replace this plugin with the full
           BareBits plugin from the
           <a href="https://github.com/BareBits/cashupayserver/releases" target="_blank" rel="noopener noreferrer">GitHub releases</a>.
           Or click "Start over" below and connect a BareBits server by URL.</p>
    </div>
    <?php
}

function cashupay_render_step_install(): void {
    // The chooser already showed — and its POST handler gated on — the full
    // server checks, so this page is just the "download now" confirmation.
    // Conditions can still regress between the two screens (a permissions
    // change, a removed extension), so re-verify quietly and only resurface
    // the checks table when something actually broke.
    $checks = cashupay_install_preflight();
    $allOk = true;
    foreach ($checks as $check) {
        $allOk = $allOk && $check['ok'];
    }
    ?>
    <h2>Install BareBits alongside WordPress</h2>
    <?php if ($allOk): $target = cashupay_resolve_install_target((string) get_option('cashupay_install_dirname', '')); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cashupay-section">
            <?php wp_nonce_field('cashupay_run_install'); ?>
            <input type="hidden" name="action" value="cashupay_run_install">
            <p>This downloads the latest stable release (a few MB) and installs it
               <?php if (!empty($target['url'])): ?> to <code><?php echo esc_html($target['url']) ?></code><?php endif; ?>.
               It can take a minute on slow hosts.</p>
            <?php submit_button('Download and install BareBits'); ?>
        </form>
    <?php else: ?>
        <?php cashupay_render_preflight_table($checks); ?>
        <p><strong>Fix the failed checks above, then reload this page.</strong> If your host cannot pass them, you can still run BareBits on another host and connect it by URL (use "Start over" below).</p>
    <?php endif; ?>
    <?php
}

function cashupay_render_step_provision(): void {
    $setupUrl = cashupay_server_url() . '/setup.php';
    ?>
    <h2>Finish the BareBits setup</h2>
    <p>BareBits is installed. Walk through its setup wizard below — it configures your store, wallets and payment rails, and shows you the recovery phrase to write down.
       When the wizard says you're done, its finish button brings you straight back here.</p>
    <p>
        <button type="button" class="button button-primary button-hero" id="cashupay-wizard-expand">Continue — open the wizard full screen</button>
    </p>
    <!-- Same-origin embed: the alongside install lives under this site's own
         origin, so the wizard runs inside wp-admin just like the old bundled
         plugin's did. Expand/collapse behavior: assets/js/wizard-expand.js;
         layout (incl. the expanded state): admin.css. -->
    <div id="cashupay-wizard-shell">
        <button type="button" class="button" id="cashupay-wizard-exit">Exit full screen</button>
        <iframe id="cashupay-wizard-frame" src="<?php echo esc_url($setupUrl); ?>" title="BareBits setup"></iframe>
    </div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cashupay-section">
        <?php wp_nonce_field('cashupay_collect_provision'); ?>
        <input type="hidden" name="action" value="cashupay_collect_provision">
        <p class="description">Finished the wizard but still seeing this page? Continue manually:</p>
        <?php submit_button('I finished the wizard — continue', 'secondary'); ?>
    </form>
    <?php
}

function cashupay_render_step_pair(): void {
    ?>
    <h2>Pair with your BareBits server</h2>
    <p>Connected to <code><?php echo esc_html(cashupay_server_url()); ?></code>. Next, authorize this shop: you'll be sent to your server to sign in and approve an API key, then brought straight back.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('cashupay_start_pairing'); ?>
        <input type="hidden" name="action" value="cashupay_start_pairing">
        <?php submit_button('Pair with BareBits'); ?>
    </form>
    <?php
}

function cashupay_render_step_wire(): void {
    $takeover = cashupay_btcpay_takeover_state();
    $hasWoo = class_exists('WooCommerce');
    $saved = get_option('cashupay_discount_percent', null);
    ?>
    <h2>Last step: connect WooCommerce</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('cashupay_finish'); ?>
        <input type="hidden" name="action" value="cashupay_finish">
        <?php if ($hasWoo): ?>
            <p><label for="cashupay-discount">Offer customers a discount for paying with Bitcoin (Bitcoin payments have no card fees or chargebacks):</label></p>
            <p>
                <input type="number" min="0" max="100" step="0.01" id="cashupay-discount"
                       name="cashupay_discount_percent" value="<?php echo esc_attr($saved === null ? '0' : (string) $saved); ?>" class="cashupay-percent-field"> %
                <span class="description">0 = no discount. Applied automatically at checkout when the customer pays with BareBits, and advertised in the payment method's title.</span>
            </p>
        <?php else: ?>
            <p><strong>WooCommerce is not active.</strong> Install and activate WooCommerce first, then click Finish.</p>
        <?php endif; ?>
        <?php if ($takeover === 'needs_consent'): ?>
            <p class="cashupay-btcpay-consent">
                <label>
                    <input type="checkbox" name="cashupay_btcpay_override_consent" value="1">
                    A BTCPay Server is already connected (<code><?php echo esc_html((string) get_option('btcpay_gf_url', '')); ?></code>).
                    Replace that connection and all its gateway settings with BareBits.
                </label>
            </p>
        <?php endif; ?>
        <p class="description">This installs and configures the "BTCPay for WooCommerce" gateway plugin, registers the payment webhook with your BareBits server, and enables Bitcoin at checkout.</p>
        <?php submit_button('Finish'); ?>
    </form>
    <?php
}

function cashupay_render_reset_form(): void {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cashupay-reset-form">
        <?php wp_nonce_field('cashupay_reset_onboarding'); ?>
        <input type="hidden" name="action" value="cashupay_reset_onboarding">
        <?php // Confirmation prompt: assets/js/onboarding.js. ?>
        <button type="submit" class="button-link" id="cashupay-reset-button">
            Start over
        </button>
    </form>
    <?php
}
