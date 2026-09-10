<?php
/**
 * BareBits plugin — wp-admin menu, status page, and notices.
 *
 * Adds the top-level "BareBits" page: it renders the onboarding flow until
 * the shop is wired, then a status panel linking out to the BareBits server's
 * own admin. License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'cashupay_admin_menu');
add_action('admin_enqueue_scripts', 'cashupay_admin_enqueue_assets');
add_action('admin_notices', 'cashupay_admin_notice');
add_action('wp_ajax_cashupay_dismiss_review', 'cashupay_dismiss_review_notice');
add_action('wp_ajax_cashupay_reveal_password', 'cashupay_reveal_password_ajax');

// Review-banner dismissal state, stored site-wide (one admin dismissing hides
// it for everyone): ['dismissed_at' => unix ts of last dismissal, 'count' =>
// total dismissals]. Each dismissal hides the banner for 30 days; after
// CASHUPAY_REVIEW_MAX_DISMISSALS it is hidden permanently.
const CASHUPAY_REVIEW_OPTION = 'cashupay_review_banner';
const CASHUPAY_REVIEW_HIDE_SECONDS = 30 * DAY_IN_SECONDS;
const CASHUPAY_REVIEW_MAX_DISMISSALS = 3;

// How stale the WP-cron pinger's last successful tick (cashupay_cron_last_ok,
// stamped by cron-integration.php) may grow before wp-admin warns. The pinger
// runs every minute and backs off ten on failure, so anything past this is a
// real outage — WP-cron not firing (quiet site, DISABLE_WP_CRON without a
// system cron) or the install unreachable — during which payment
// confirmations and webhooks stall.
const CASHUPAY_CRON_STALE_WARN_SECONDS = 600;

function cashupay_admin_menu(): void {
    $hooks = [];
    $hooks['main'] = add_menu_page(
        'BareBits',
        'BareBits',
        'manage_options',
        'cashupay',
        'cashupay_admin_page',
        'dashicons-money-alt',
        58
    );
    if (cashupay_is_configured()) {
        // Rename the auto-created first submenu entry and add the details page.
        add_submenu_page('cashupay', 'BareBits', cashupay_mode() === 'install' ? 'Dashboard' : 'Status', 'manage_options', 'cashupay', 'cashupay_admin_page');
        $hooks['connection'] = add_submenu_page('cashupay', 'BareBits connection', 'Connection', 'manage_options', 'cashupay-connection', 'cashupay_connection_page');
    }
    // The hook suffixes cashupay_admin_enqueue_assets() keys asset loading on
    // (admin_enqueue_scripts fires after admin_menu). false = no capability.
    $GLOBALS['cashupay_admin_page_hooks'] = array_filter($hooks);
}

/** Enqueue one of the plugin's admin scripts (assets/js/…), file-mtime versioned like the checkout scripts. */
function cashupay_enqueue_admin_script(string $handle, string $rel): void {
    wp_enqueue_script(
        $handle,
        plugin_dir_url(CASHUPAY_PLUGIN_FILE) . 'assets/' . $rel,
        [],
        (string) filemtime(CASHUPAY_PLUGIN_DIR . '/assets/' . $rel),
        true
    );
}

/** Enqueue one of the plugin's admin stylesheets (assets/css/…). */
function cashupay_enqueue_admin_style(string $handle, string $rel): void {
    wp_enqueue_style(
        $handle,
        plugin_dir_url(CASHUPAY_PLUGIN_FILE) . 'assets/' . $rel,
        [],
        (string) filemtime(CASHUPAY_PLUGIN_DIR . '/assets/' . $rel)
    );
}

/**
 * All the plugin's admin JS/CSS lives in files under assets/ and is enqueued
 * here (the wordpress.org review disallows inline script/style blocks
 * printed from PHP). Each page enqueues only what its render path uses, mirroring
 * the render functions' own conditions; every script also no-ops when its
 * elements are absent, so a condition drifting out of sync degrades to a
 * dead file load, never a broken page. Styles must enqueue here (not during
 * render) to reach the document head.
 */
function cashupay_admin_enqueue_assets(string $hook): void {
    // The review banner renders (admin_notices) on every admin page once the
    // plugin is configured; its dismiss handler rides along under the same
    // conditions cashupay_review_notice() renders under.
    if (cashupay_is_configured() && current_user_can('manage_options') && cashupay_review_notice_visible()) {
        cashupay_enqueue_admin_script('cashupay-review-notice', 'js/review-notice.js');
    }

    $hooks = $GLOBALS['cashupay_admin_page_hooks'] ?? [];
    if (!in_array($hook, $hooks, true)) {
        return;
    }

    if (!cashupay_is_configured()) {
        // The main page renders the onboarding flow.
        cashupay_enqueue_admin_style('cashupay-admin', 'css/admin.css');
        cashupay_enqueue_admin_script('cashupay-maintenance-guard', 'js/maintenance-guard.js');
        cashupay_enqueue_admin_script('cashupay-onboarding', 'js/onboarding.js');
        $step = cashupay_onboarding_step();
        if ($step === 'choose' && cashupay_install_url() !== '') {
            cashupay_enqueue_admin_script('cashupay-reveal-password', 'js/admin-reveal-password.js');
        }
        if ($step === 'provision' && cashupay_installer_available()) {
            cashupay_enqueue_admin_script('cashupay-wizard-expand', 'js/wizard-expand.js');
        }
        return;
    }

    // Configured + install mode on the main page: the full-height admin
    // embed hands the whole content area to the iframe.
    if ($hook === ($hooks['main'] ?? null) && cashupay_mode() === 'install') {
        cashupay_enqueue_admin_style('cashupay-admin-embed', 'css/admin-embed.css');
        return;
    }

    // The Connection page — reached directly, or as what the main page
    // renders for a URL-mode server.
    cashupay_enqueue_admin_style('cashupay-admin', 'css/admin.css');
    cashupay_enqueue_admin_script('cashupay-maintenance-guard', 'js/maintenance-guard.js');
    if (cashupay_mode() === 'install' && (string) get_option('cashupay_admin_password', '') !== '') {
        cashupay_enqueue_admin_script('cashupay-reveal-password', 'js/admin-reveal-password.js');
    }
}

/**
 * The BareBits page: onboarding until wired; then, for an alongside install,
 * the BareBits admin embedded full-height in wp-admin — signed in
 * automatically through a one-time SSO token, so clicking "BareBits" in the
 * sidebar drops the operator straight into the dashboard, exactly like the
 * old bundled plugin. Remote (URL-mode) servers get the connection panel
 * instead: cross-site embedding is unreliable (third-party cookies) so they
 * link out.
 */
function cashupay_admin_page(): void {
    if (!cashupay_is_configured()) {
        cashupay_render_onboarding();
        return;
    }
    if (cashupay_mode() !== 'install') {
        cashupay_connection_page();
        return;
    }

    // Mint the sign-in handoff; on failure (install briefly unreachable, SSO
    // not provisioned on an old install) fall back to the plain admin URL,
    // where BareBits shows its own login.
    $src = cashupay_sso_login_url() ?: (cashupay_server_url() . '/admin.php');
    // Consume any pending one-shot notice here too — the wiring's "Finish"
    // redirects straight to this page, and its success message must not lie
    // in wait to pop up on some later Connection-page visit instead.
    $flash = cashupay_take_flash();
    if ($flash) {
        echo '<div class="notice notice-' . esc_attr($flash['kind'] === 'error' ? 'error' : ($flash['kind'] === 'warning' ? 'warning' : 'success')) . '"><p>' . esc_html($flash['message']) . '</p></div>';
    }
    // Layout (content area handed entirely to the iframe) comes from
    // admin-embed.css, enqueued for exactly this view.
    ?>
    <iframe id="cashupay-admin-frame" src="<?php echo esc_url($src); ?>" title="BareBits"></iframe>
    <?php
}

/**
 * Connection details: status table, wiring re-run, and (install mode) the
 * BareBits admin password reveal — day-to-day sign-in is automatic via SSO,
 * but BareBits still asks for the password before showing a wallet recovery
 * phrase, and it is the fallback if this plugin is ever removed.
 */
function cashupay_connection_page(): void {
    $mode = cashupay_mode();
    $server = cashupay_server_url();
    $flash = cashupay_take_flash();
    ?>
    <div class="wrap cashupay-wrap">
        <h1>BareBits</h1>
        <?php if ($flash): ?>
            <div class="notice notice-<?php echo esc_attr($flash['kind'] === 'error' ? 'error' : ($flash['kind'] === 'warning' ? 'warning' : 'success')) ?>"><p><?php echo esc_html($flash['message']); ?></p></div>
        <?php endif; ?>
        <p>✅ WooCommerce is connected to your BareBits server.</p>
        <table class="widefat striped cashupay-table">
            <tbody>
                <tr><td>Server</td><td><a href="<?php echo esc_url($server) ?>" target="_blank" rel="noopener"><?php echo esc_html($server); ?></a></td></tr>
                <tr><td>Store ID</td><td><code><?php echo esc_html((string) get_option('cashupay_store_id', '')); ?></code></td></tr>
                <tr><td>Mode</td><td><?php echo esc_html($mode === 'install' ? 'Installed alongside WordPress' : 'Existing server (connected by URL)'); ?></td></tr>
                <?php if ($mode === 'install'): ?>
                    <tr><td>Install directory</td><td><code><?php echo esc_html((string) get_option('cashupay_install_dir', '')); ?></code></td></tr>
                    <tr>
                        <td>Data directory</td>
                        <td>
                            <code><?php echo esc_html((string) get_option('cashupay_install_data_dir', '')); ?></code>
                            <p class="description">Holds the wallet database — real money. It is never deleted by this plugin; back up your recovery phrase.</p>
                        </td>
                    </tr>
                    <tr><td>Background jobs</td><td>Driven by WP-cron (this plugin pings the server every minute).</td></tr>
                    <?php if ((string) get_option('cashupay_admin_password', '') !== ''): ?>
                    <tr>
                        <td>Admin password</td>
                        <td>
                            <code id="cashupay-admin-password">••••••••••••</code>
                            <button type="button" class="button button-small" id="cashupay-reveal-password"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('cashupay_reveal_password')); ?>">Reveal</button>
                            <p class="description">Sign-in from here is automatic; BareBits asks for this password only for
                            sensitive actions (revealing a wallet recovery phrase), and it lets you sign in directly if this
                            plugin is ever removed.</p>
                            <?php // Button behavior: assets/js/admin-reveal-password.js. ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="cashupay-section">
            <a href="<?php echo esc_url($server . '/admin.php') ?>" target="_blank" rel="noopener" class="button button-primary">Open the BareBits admin<?php echo esc_html($mode === 'install' ? ' in a new tab' : ''); ?></a>
        </p>
        <?php cashupay_render_discount_settings(); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cashupay-section">
            <?php wp_nonce_field('cashupay_finish'); ?>
            <input type="hidden" name="action" value="cashupay_finish">
            <p class="description">If the WooCommerce gateway or webhook got misconfigured, re-run the wiring:</p>
            <?php submit_button('Re-run WooCommerce wiring', 'secondary'); ?>
        </form>
        <?php
        // The same wait-out-WP-maintenance gate the onboarding forms get:
        // it hooks every admin-post.php form on this page (the discount
        // save above and the re-run wiring).
        cashupay_render_maintenance_guard();
        ?>
    </div>
    <?php
}

function cashupay_admin_notice(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Before the configured check: an alongside install is owed its
    // heartbeat even while onboarding is unfinished (mid-"Start over"), and
    // a stall there must not be invisible.
    cashupay_cron_stale_notice();

    if (!cashupay_is_configured()) {
        // Not on the plugin's own page — it already renders the flow. A
        // read-only render decision on an admin pageview; no nonce applies.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'cashupay') {
            return;
        }
        echo '<div class="notice notice-info"><p>';
        echo '<strong>BareBits</strong> is almost ready — finish setup to start accepting Lightning payments via Bitcoin. ';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=cashupay')) . '">Configure BareBits</a>';
        echo '</p></div>';
        return;
    }

    cashupay_review_notice();
}

/**
 * Warn when the alongside install's background heartbeat has gone quiet.
 *
 * An alongside install delegates the server's cron to the WP-cron pinger,
 * and WP-cron only fires on site traffic — a quiet shop, a DISABLE_WP_CRON
 * without a system cron, or a host that blocks self-requests silently
 * stalls payment confirmations. cashupay_cron_last_ok is stamped on every
 * successful ping (seeded synchronously when onboarding collects the
 * credentials); staleness is measured from the LATER of that stamp and the
 * wiring time, so installs wired before the stamp existed don't warn until
 * they have actually been quiet. Gated on the same condition as the pinger
 * itself (an install record plus its cron key — not the mode, which a reset
 * or a URL-mode reconnect changes while the heartbeat is still owed).
 * State-only on purpose: this reads options and never fires HTTP from an
 * admin pageview.
 */
function cashupay_cron_stale_notice(): void {
    if (cashupay_install_url() === '' || (string) get_option('cashupay_cron_key', '') === '') {
        return;
    }
    $baseline = max((int) get_option('cashupay_cron_last_ok', 0), (int) get_option('cashupay_wired_at', 0));
    if ($baseline <= 0 || (time() - $baseline) <= CASHUPAY_CRON_STALE_WARN_SECONDS) {
        return;
    }
    $minutes = (int) floor((time() - $baseline) / 60);
    echo '<div class="notice notice-warning"><p>';
    echo '<strong>BareBits</strong>: the background heartbeat to your BareBits server has not succeeded for ';
    echo esc_html((string) $minutes) . ' minutes. Payments still arrive, but confirmations and order updates ';
    echo 'will lag until it recovers. Common causes: WP-cron is disabled (<code>DISABLE_WP_CRON</code>) without ';
    echo 'a system cron calling <code>wp-cron.php</code>, the site gets too little traffic to fire WP-cron, or ';
    echo 'the host blocks this site from requesting its own URLs. ';
    echo '<a href="' . esc_url(admin_url('admin.php?page=cashupay-connection')) . '">Connection details</a>';
    echo '</p></div>';
}

/**
 * "Leave us a review" banner, shown once setup is complete. Dismissing it
 * (the standard notice X) hides it site-wide for 30 days; after three
 * dismissals it never comes back. State lives in a WP option — see
 * CASHUPAY_REVIEW_OPTION above.
 */
function cashupay_review_notice(): void {
    if (!cashupay_review_notice_visible()) {
        return;
    }

    $nonce = wp_create_nonce('cashupay_dismiss_review');
    echo '<div class="notice notice-info is-dismissible" id="cashupay-review-notice" data-nonce="' . esc_attr($nonce) . '"><p>';
    echo 'Enjoying having control of your money with <strong>BareBits</strong>? ';
    echo '<a href="https://wordpress.org/plugins/search/barebits/" target="_blank" rel="noopener noreferrer">Leave us a review!</a>';
    echo '</p></div>';
    // Dismissal persistence: assets/js/review-notice.js, enqueued under the
    // same visibility conditions by cashupay_admin_enqueue_assets().
}

/**
 * Whether the review banner should render, given the stored dismissal state.
 */
function cashupay_review_notice_visible(): bool {
    $state = get_option(CASHUPAY_REVIEW_OPTION, []);
    if (!is_array($state)) {
        $state = [];
    }
    $count = (int)($state['count'] ?? 0);
    if ($count >= CASHUPAY_REVIEW_MAX_DISMISSALS) {
        return false;
    }
    $dismissedAt = (int)($state['dismissed_at'] ?? 0);
    if ($dismissedAt > 0 && (time() - $dismissedAt) < CASHUPAY_REVIEW_HIDE_SECONDS) {
        return false;
    }
    return true;
}

/**
 * Reveal the provisioned BareBits admin password to a site admin (nonce +
 * capability gated; the Connection page's Reveal button calls this).
 */
function cashupay_reveal_password_ajax(): void {
    check_ajax_referer('cashupay_reveal_password', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }
    $password = (string) get_option('cashupay_admin_password', '');
    if ($password === '') {
        wp_send_json_error(null, 404);
    }
    wp_send_json_success($password);
}

function cashupay_dismiss_review_notice(): void {
    check_ajax_referer('cashupay_dismiss_review', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }

    $state = get_option(CASHUPAY_REVIEW_OPTION, []);
    if (!is_array($state)) {
        $state = [];
    }
    $state['count'] = (int)($state['count'] ?? 0) + 1;
    $state['dismissed_at'] = time();
    // autoload=false: only admin_notices reads this, no need on every request.
    update_option(CASHUPAY_REVIEW_OPTION, $state, false);

    wp_send_json_success($state);
}
