<?php
/**
 * CashuPay WordPress Admin Menu
 *
 * Adds CashuPay to the WordPress admin sidebar.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'cashupay_admin_menu');
add_action('admin_notices', 'cashupay_admin_notice');
add_action('admin_notices', 'cashupay_startup_error_notice');
add_action('admin_notices', 'cashupay_data_exposure_notice');
add_action('admin_notices', 'cashupay_foreign_data_dir_notice');
add_action('admin_notices', 'cashupay_network_data_dir_notice');
add_action('admin_notices', 'cashupay_needs_attention_notice');
add_action('admin_init', 'cashupay_handle_exposure_recheck');
add_action('admin_notices', 'cashupay_plain_permalinks_notice');
add_action('admin_post_cashupay_enable_pretty_links', 'cashupay_enable_pretty_links');

const CASHUPAY_LINKS_RESULT_TRANSIENT = 'cashupay_links_result_';

/**
 * WordPress's "Plain" permalink setting (?p=123) stores no rewrite rules, so every
 * CashuPay address (/cashupay-setup/, /cashupay-admin/, the /cashupay/api/v1 address the
 * BTCPay for WooCommerce plugin calls, the checkout page) answers 404 (ARGON UX-01).
 * The Greenfield clients build API addresses by appending /api/v1/... to the server
 * address, so a query-string fallback cannot serve them; readable addresses are needed.
 */
function cashupay_plain_permalinks(): bool {
    return get_option('permalink_structure') === '';
}

/**
 * Explains the Plain-permalinks dead end and offers a button. The setting is the shop
 * owner's, so it is never changed without her pressing it.
 */
function cashupay_plain_permalinks_notice(): void {
    if (!cashupay_current_user_can_manage() || !empty($GLOBALS['cashupay_startup_error'])) {
        return;
    }
    $key = CASHUPAY_LINKS_RESULT_TRANSIENT . get_current_user_id();
    $result = get_transient($key);
    if (is_array($result) && ($result['text'] ?? '') !== '') {
        delete_transient($key);
        echo '<div class="notice ' . (!empty($result['ok']) ? 'notice-success' : 'notice-error') . '"><p><strong>CashuPay:</strong> '
            . esc_html((string)$result['text']) . '</p></div>';
    }
    if (!cashupay_plain_permalinks()) {
        return;
    }
    $button = wp_nonce_url(admin_url('admin-post.php?action=cashupay_enable_pretty_links'), 'cashupay_enable_pretty_links');
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('CashuPay cannot work until your site uses readable web addresses.', 'cashupay') . '</strong></p><p>'
        . esc_html__('Your site uses WordPress\'s "Plain" web addresses (like yoursite.com/?p=123). CashuPay\'s pages, its checkout page and the connection your shop uses to create payments need readable addresses (like yoursite.com/sample-post/). Until then they show "Page not found".', 'cashupay')
        . '</p><p>'
        . esc_html__('Click the button to switch. Your existing links keep working: WordPress forwards old addresses to the new ones. You can change it back later under Settings → Permalinks, but CashuPay then stops working.', 'cashupay')
        . '</p><p><a class="button button-primary" href="' . esc_url($button) . '">'
        . esc_html__('Switch to readable web addresses', 'cashupay') . '</a></p></div>';
}

/** The address cashupay_api_reachable() checks; the admin can open it herself. */
function cashupay_api_check_url(): string {
    return rtrim(Urls::server(), '/') . '/api/v1/server/info';
}

/**
 * Whether CashuPay's API address reaches CashuPay (its server-info answer) rather than a
 * WordPress or web-server "Page not found", or any other JSON. Null when the site cannot
 * call itself (loopback blocked, timeout): then nothing is known either way (UX-A7).
 */
function cashupay_api_reachable(): ?bool {
    $response = wp_remote_get(cashupay_api_check_url(), [
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => false,
    ]);
    if (is_wp_error($response)) {
        return null;
    }
    $info = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($info) && ($info['isCashuPayServer'] ?? null) === true;
}

/** The notice's button: readable addresses ("Post name"), then a check that they work. */
function cashupay_enable_pretty_links(): void {
    check_admin_referer('cashupay_enable_pretty_links');
    if (!cashupay_current_user_can_manage()) {
        wp_die(esc_html__('Your account is not allowed to change this site\'s settings.', 'cashupay'), '', ['response' => 403]);
    }
    global $wp_rewrite;
    if (cashupay_plain_permalinks()) {
        // What Settings → Permalinks → "Post name" saves.
        $wp_rewrite->set_permalink_structure('/%postname%/');
    }
    // Hard flush: also writes the .htaccess rules where WordPress can (Apache).
    flush_rewrite_rules(true);
    delete_option('cashupay_rewrite_rules_version');

    $reachable = cashupay_api_reachable();
    $fixPageNotFound = __('Open Settings → Permalinks and click "Save Changes". If that page then shows text for a file called .htaccess, send that text to your hosting provider and ask them to add it to your site.', 'cashupay');
    if ($reachable === false) {
        $result = ['ok' => false, 'text' => __('WordPress now uses readable web addresses, but your web server does not pass them on to WordPress yet, so CashuPay still shows "Page not found".', 'cashupay')
            . ' ' . $fixPageNotFound];
    } elseif ($reachable === null) {
        // The site could not call itself, which says nothing about whether the links work.
        $result = ['ok' => false, 'text' => sprintf(
            __('WordPress now uses readable web addresses, but CashuPay could not check automatically whether they work. Open this address in a new browser tab: %s — if you see a short text that contains "isCashuPayServer", everything is fine. If you see "Page not found" instead:', 'cashupay'),
            cashupay_api_check_url()
        ) . ' ' . $fixPageNotFound];
    } else {
        $result = ['ok' => true, 'text' => __('Your site now uses readable web addresses, and CashuPay can be reached. You can continue with CashuPay under Tools → CashuPay.', 'cashupay')];
    }
    set_transient(CASHUPAY_LINKS_RESULT_TRANSIENT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url());
    exit;
}

function cashupay_admin_menu(): void {
    if (!cashupay_current_user_can_manage()) {
        return; // On a network, subsite administrators get no CashuPay menu.
    }
    $hook = add_submenu_page(
        'tools.php',
        'CashuPay',
        'CashuPay',
        'manage_options',
        'cashupay',
        'cashupay_admin_page'
    );
    if ($hook) {
        // load-{hook} runs before any admin output, so a real HTTP redirect is possible.
        add_action('load-' . $hook, 'cashupay_admin_redirect');
    }
}

/**
 * Tools → CashuPay normally forwards straight to the setup wizard or the CashuPay
 * dashboard. The one exception: while the BTCPay for WooCommerce notice has something
 * to say, stay on this wp-admin page so the admin actually sees it (the CashuPay
 * dashboard is outside wp-admin and shows no WordPress notices).
 */
function cashupay_admin_redirect(): void {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/safe_mode.php';

    // Everything CashuPay serves answers 503 while shut down, so this notice is the one
    // place a WordPress operator is sure to see why and what to do.
    $shutdown = SafeMode::shutdown();
    if ($shutdown !== null) {
        echo '<div class="notice notice-error"><p><strong>CashuPay has shut itself down for safety.</strong> ';
        echo esc_html__('The CashuPayServer developers found a serious security problem in this version and sent a signed warning. Your money is still there.', 'cashupay') . ' ';
        if ($shutdown['fixed'] !== null) {
            echo esc_html(sprintf(__('Update the CashuPay plugin to version %s or later; payments start again by themselves.', 'cashupay'), $shutdown['fixed']));
        } else {
            echo esc_html__('There is no fixed version yet; it is safe while it is off. When the fix is released, update the plugin and payments start again by themselves.', 'cashupay');
        }
        if ($shutdown['reason'] !== '') {
            echo ' ' . esc_html($shutdown['reason']);
        }
        echo ' <a href="' . esc_url($shutdown['url'] ?? 'https://github.com/jooray/cashupayserver/releases') . '" target="_blank" rel="noopener">'
            . esc_html__('How to upgrade', 'cashupay') . '</a></p></div>';
        return;
    }

    if (cashupay_plain_permalinks()) {
        return; // Every CashuPay page would 404; cashupay_plain_permalinks_notice() explains.
    }
    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        wp_redirect(Urls::setup()); // Our own URL, and site_url() may be on a different host than home_url().
        exit;
    }
    if (function_exists('cashupay_btcpay_notice_step') && cashupay_btcpay_notice_step() !== null) {
        return; // cashupay_admin_page() renders; the notice prints above it.
    }
    wp_redirect(Urls::admin());
    exit;
}

function cashupay_admin_page(): void {
    echo '<div class="wrap"><h1>CashuPay</h1>';
    if (cashupay_plain_permalinks()) {
        echo '<p>' . esc_html__('First switch your site to readable web addresses with the button in the message above. CashuPay opens here after that.', 'cashupay') . '</p></div>';
        return;
    }
    echo '<p>' . esc_html__('Your CashuPay wallet and payment settings open on their own page.', 'cashupay') . '</p>';
    echo '<p><a class="button button-primary button-hero" href="' . esc_url(Urls::admin()) . '">'
        . esc_html__('Open CashuPay', 'cashupay') . '</a></p>';
    echo '</div>';
}

function cashupay_admin_notice(): void {
    if (!cashupay_current_user_can_manage()) {
        return;
    }

    if (!empty($GLOBALS['cashupay_startup_error'])) {
        return; // cashupay_startup_error_notice() explains what is wrong instead.
    }

    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';

    if (cashupay_plain_permalinks()) {
        return; // The setup link would 404; cashupay_plain_permalinks_notice() comes first.
    }
    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        echo '<div class="notice notice-info"><p>';
        echo '<strong>CashuPay:</strong> Plugin not configured yet, please ';
        echo '<a href="' . esc_url(Urls::setup()) . '">configure the plugin here</a>.';
        echo '</p></div>';
    }
}

/**
 * CashuPay could not start (see cashupay_record_startup_error()). The rest of the site
 * keeps working; only admins are told, in words a shop owner can act on.
 */
function cashupay_startup_error_notice(): void {
    if (!cashupay_current_user_can_manage()) {
        return;
    }

    $activationError = get_option('cashupay_activation_error');
    if (is_string($activationError) && $activationError !== '' && empty($GLOBALS['cashupay_startup_error'])) {
        try {
            require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
            if (Database::isInitialized()) {
                delete_option('cashupay_activation_error'); // Fixed since; nothing to report.
                $activationError = '';
            }
        } catch (Throwable $e) {
            // Keep showing the activation error.
        }
    }

    $runtimeError = $GLOBALS['cashupay_startup_error'] ?? '';
    foreach ([$runtimeError, $activationError] as $message) {
        if (!is_string($message) || $message === '') {
            continue;
        }
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('CashuPay is switched off because it could not start.', 'cashupay') . '</strong> '
            . esc_html__('Your shop still works, but CashuPay payments are not accepted until this is fixed. Your money and wallet files have not been touched.', 'cashupay')
            . '</p><p>' . esc_html__('What went wrong:', 'cashupay') . ' <code>' . esc_html($message) . '</code></p><p>'
            . esc_html__('Send this message to your hosting provider or to whoever set up your site. Once it is fixed, deactivate and activate the CashuPay plugin again under Plugins.', 'cashupay')
            . '</p></div>';
        return; // One notice is enough; the runtime error is the current one.
    }
}

/**
 * Public URL of the data directory, or null when the web server cannot reach it at all
 * (not under WordPress, wp-content or the document root).
 */
function cashupay_data_dir_public_url(): ?string {
    $dir = realpath(cashupay_data_dir()) ?: rtrim(cashupay_data_dir(), '/');

    $roots = [
        [WP_CONTENT_DIR, content_url()],
        [ABSPATH, site_url()],
    ];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $home = wp_parse_url(home_url());
        if (!empty($home['host'])) {
            $origin = ($home['scheme'] ?? 'https') . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
            $roots[] = [$_SERVER['DOCUMENT_ROOT'], $origin];
        }
    }

    foreach ($roots as [$fsRoot, $urlRoot]) {
        $root = rtrim(realpath($fsRoot) ?: $fsRoot, '/');
        if ($root === '' || ($dir !== $root && strpos($dir . '/', $root . '/') !== 0)) {
            continue;
        }
        $relative = trim(substr($dir, strlen($root)), '/');
        $encoded = implode('/', array_map('rawurlencode', $relative === '' ? [] : explode('/', $relative)));
        return rtrim($urlRoot, '/') . '/' . ($encoded === '' ? '' : $encoded . '/');
    }
    return null;
}

/**
 * Which wallet files the web server hands out to anyone, or null when that could not
 * be determined. Cached for an hour: every probe is a few loopback HTTP requests.
 *
 * @return array{url: string, exposed: string[]}|null
 */
function cashupay_data_exposure(): ?array {
    $url = cashupay_data_dir_public_url();
    if ($url === null) {
        return null;
    }

    $cached = get_transient('cashupay_data_exposure');
    if (is_array($cached) && ($cached['url'] ?? null) === $url) {
        return $cached;
    }

    $exposed = [];
    $probed = 0;
    // The sidecars hold the most recently written proofs, and a rule that only denies
    // the main file leaves them downloadable. Only existing files are probed, so a
    // "soft 404" page answering 200 for everything is not mistaken for an exposure.
    $names = ['cashupay.sqlite', 'cashupay.sqlite-wal', 'cashupay.sqlite-shm'];
    foreach (glob(cashupay_data_dir() . '/setup-token*.txt') ?: [] as $tokenFile) {
        $names[] = basename($tokenFile); // The setup recovery code, a takeover credential.
    }
    foreach (glob(cashupay_data_dir() . '/backup-*.sqlite') ?: [] as $backupFile) {
        $names[] = basename($backupFile); // A complete copy of the wallet if one was left behind.
    }
    $inconclusive = [];
    foreach ($names as $name) {
        $local = cashupay_data_dir() . '/' . $name;
        if (!is_file($local)) {
            continue;
        }
        $response = wp_remote_get($url . $name, [
            // A ranged GET, not HEAD: some servers answer HEAD from a rule that a real
            // GET would not hit. The first bytes are compared with the file's own.
            'headers' => ['Range' => 'bytes=0-15'],
            'timeout' => 3,
            'redirection' => 0,
            'sslverify' => false,
            'limit_response_size' => 1024,
        ]);
        if (is_wp_error($response)) {
            continue;
        }
        $probed++;
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 206) {
            continue;
        }
        $verdict = cashupay_exposure_body_matches($local, (string) wp_remote_retrieve_body($response),
            (string) wp_remote_retrieve_header($response, 'content-type'));
        if ($verdict === true) {
            $exposed[] = $name;
        } elseif ($verdict === null) {
            $inconclusive[] = $name;
        }
    }

    if ($probed === 0 && $exposed === []) {
        return null; // Nothing to test yet, or the site cannot reach itself; try again later.
    }

    $result = ['url' => $url, 'exposed' => $exposed, 'inconclusive' => $inconclusive];
    set_transient('cashupay_data_exposure', $result, HOUR_IN_SECONDS);
    return $result;
}

/**
 * Whether a 200/206 answer for a wallet file really is that file (UX-A8): a "soft 404"
 * or a login page answering 200 for every address must not read as an exposure.
 * True when the bytes are the file's own (an SQLite database must start with its
 * "SQLite format 3" header), false for an HTML page, null when it cannot be told.
 */
function cashupay_exposure_body_matches(string $localFile, string $body, string $contentType): ?bool {
    $head = substr($body, 0, 16);
    if (stripos($contentType, 'text/html') !== false
        || preg_match('/^\s*<(!doctype|html|head|body|\?xml)/i', $head)) {
        return false;
    }
    $isDatabase = (bool) preg_match('/\.sqlite$/', $localFile);
    if ($isDatabase && strncmp($head, 'SQLite format 3', 15) !== 0) {
        return null;
    }
    $own = (string) @file_get_contents($localFile, false, null, 0, 16);
    if ($own === '') {
        return $body === '' ? null : false;
    }
    // The sidecars rewrite most of their header as the wallet works; their first four
    // bytes (format magic and version) stay fixed.
    $compare = preg_match('/-(wal|shm)$/', $localFile) ? 4 : min(16, strlen($own));
    if (strlen($head) < $compare) {
        return null;
    }
    return strncmp($head, $own, $compare) === 0 ? true : null;
}

function cashupay_handle_exposure_recheck(): void {
    if (empty($_GET['cashupay_recheck_data']) || !cashupay_current_user_can_manage()) {
        return;
    }
    check_admin_referer('cashupay_recheck_data');
    delete_transient('cashupay_data_exposure');
    wp_safe_redirect(remove_query_arg(['cashupay_recheck_data', '_wpnonce']));
    exit;
}

/**
 * The wallet database sits inside the website and the web server serves it. nginx and
 * most non-Apache servers ignore the .htaccess written into the folder, so this is
 * the normal outcome there unless a rule was added by hand.
 */
function cashupay_data_exposure_notice(): void {
    if (!cashupay_current_user_can_manage()) {
        return;
    }
    $exposure = cashupay_data_exposure();
    if ($exposure === null || $exposure['exposed'] === []) {
        return;
    }

    $path = (string) (wp_parse_url($exposure['url'], PHP_URL_PATH) ?: '/');
    $regexPath = preg_replace('/[.\\\\+*?\\[\\]^$(){}|]/', '\\\\$0', $path);
    $nginxRule = 'location ~* ^' . $regexPath . ' { deny all; }';
    // This installation's own name (bootstrap.php), so the moved folder cannot collide
    // with a neighbouring site's.
    $suggested = rtrim(dirname(rtrim(ABSPATH, '/')), '/') . '/cashupay-data-' . substr(hash('sha256', ABSPATH), 0, 12);
    $wpConfigLine = "define('CASHUPAY_DATA_DIR', '" . $suggested . "');";
    // wp-config.php is shared by every site of a network and its folder holds the other
    // sites' wallets too, so moving one site's folder is not something to do by hand.
    $canMove = !(function_exists('is_multisite') && is_multisite());
    $recheck = wp_nonce_url(add_query_arg('cashupay_recheck_data', '1'), 'cashupay_recheck_data');

    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('CashuPay: anyone on the internet can download your wallet right now.', 'cashupay') . '</strong></p>';
    echo '<p>' . esc_html(sprintf(
        __('CashuPay keeps the money your shop has received, and the secret words that control it, in the folder %s. Your web server hands out the files in that folder to anyone who asks for them: we just downloaded the start of %s. A stranger who downloads them can take the money.', 'cashupay'),
        cashupay_data_dir(),
        implode(', ', $exposure['exposed'])
    )) . '</p>';
    // The plugin chose this folder itself and may create its own folder next to
    // WordPress: the dashboard moves it with one button (UX-A10, includes/data_folder.php).
    $ownDir = function_exists('cashupay_own_data_dir') ? cashupay_own_data_dir() : null;
    if ($canMove && defined('CASHUPAY_DATA_DIR_AUTO') && $ownDir !== null && !file_exists($ownDir)
        && is_writable(dirname($ownDir))) {
        echo '<p><a class="button button-primary" href="' . esc_url(Urls::admin()) . '">'
            . esc_html__('Open the CashuPay dashboard and move it for me', 'cashupay') . '</a> '
            . esc_html__('(press "Move the data out of the website for me" at the top; your WordPress password confirms it). Or do it by hand:', 'cashupay')
            . '</p>';
    }
    echo '<p>' . ($canMove
        ? esc_html__('Fix it today, in one of these two ways:', 'cashupay')
        : esc_html__('Fix it today:', 'cashupay')) . '</p><ol>';
    echo '<li><p>' . esc_html__('Block the folder. Send this to your hosting provider (or whoever runs your server). If the server runs nginx, the rule below goes inside the site\'s server { } block, above the other "location" lines, followed by an nginx reload:', 'cashupay') . '</p>'
        . '<pre style="white-space:pre-wrap">' . esc_html($nginxRule) . '</pre></li>';
    if ($canMove) {
        echo '<li><p>' . esc_html(sprintf(
            __('Or move the folder out of the website. Move the whole folder %1$s, with every file in it, to a place that is not part of the website, for example %2$s. Then add the line below to wp-config.php, above the line "That\'s all, stop editing!". Do both together and in that order. If CashuPay then asks to be set up again, it cannot find the moved files: do not set up a new wallet, correct the folder in that line instead. The money is still in the files you moved.', 'cashupay'),
            cashupay_data_dir(),
            $suggested
        )) . '</p><pre style="white-space:pre-wrap">' . esc_html($wpConfigLine) . '</pre></li>';
    }
    echo '</ol><p><a class="button" href="' . esc_url($recheck) . '">'
        . esc_html__('I fixed it, check again', 'cashupay') . '</a></p></div>';
}

/**
 * bootstrap.php found the shared cashupay-data folder an older version used, but it
 * belongs to another WordPress site next to this one, so this site uses its own folder.
 * Shown until this site's CashuPay is set up, because the one mistake to prevent is an
 * owner whose wallet really is in that folder (the site's address changed) setting up
 * a new wallet instead of pointing CashuPay at the old one.
 */
function cashupay_foreign_data_dir_notice(): void {
    $foreign = $GLOBALS['cashupay_foreign_data_dir'] ?? null;
    if (!is_string($foreign) || $foreign === '' || !cashupay_current_user_can_manage()
        || !empty($GLOBALS['cashupay_startup_error'])) {
        return;
    }
    try {
        require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
        require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
        if (Database::isInitialized() && Config::isSetupComplete()) {
            return;
        }
    } catch (Throwable $e) {
        // Say it anyway.
    }

    echo '<div class="notice notice-warning"><p><strong>'
        . esc_html__('CashuPay: this site has its own wallet folder.', 'cashupay') . '</strong></p><p>'
        . esc_html(sprintf(
            __('The folder %1$s holds the CashuPay wallet of another WordPress site on this server, so this site does not use it. This site keeps its CashuPay wallet in %2$s instead. Nothing in the other folder was changed.', 'cashupay'),
            $foreign,
            cashupay_data_dir()
        )) . '</p><p>'
        . esc_html__('If that folder in fact holds this shop\'s wallet (for example because the address of this site changed), do not set up a new wallet. Add the line below to wp-config.php, above the line "That\'s all, stop editing!", and reload this page. Your money is still in that folder.', 'cashupay')
        . '</p><pre style="white-space:pre-wrap">' . esc_html("define('CASHUPAY_DATA_DIR', '" . $foreign . "');") . '</pre></div>';
}

/**
 * On a network, this site keeps its wallet in site-<id> inside the network's folder,
 * while older versions gave every site the wallet in that folder itself. That wallet
 * stays with the site whose address it remembers (bootstrap.php), so a shop that took
 * payments on this site before may find CashuPay asking to be set up. Shown, to super
 * admins, until this site's CashuPay is set up, and only when that folder really holds
 * a set-up wallet: setting up a new one by mistake is what this prevents.
 */
function cashupay_network_data_dir_notice(): void {
    $base = $GLOBALS['cashupay_network_data_dir'] ?? null;
    if (!is_string($base) || $base === '' || !cashupay_current_user_can_manage()
        || !empty($GLOBALS['cashupay_startup_error'])) {
        return;
    }
    if (cashupay_data_dir_setting($base, 'setup_complete') !== true) {
        return;
    }
    try {
        require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
        require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
        if (Database::isInitialized() && Config::isSetupComplete()) {
            return;
        }
    } catch (Throwable $e) {
        // Say it anyway.
    }
    $sharedUrl = cashupay_data_dir_setting($base, 'base_url');

    echo '<div class="notice notice-warning"><p><strong>'
        . esc_html__('CashuPay: this site now has its own wallet.', 'cashupay') . '</strong></p><p>'
        . esc_html(sprintf(
            __('Each site of this WordPress network now keeps its CashuPay wallet in a folder of its own. This site\'s wallet is in %1$s. Before this update, the sites of the network shared one wallet, in the folder %2$s.', 'cashupay'),
            cashupay_data_dir(),
            $base
        ));
    if (is_string($sharedUrl) && $sharedUrl !== '') {
        echo ' ' . esc_html(sprintf(
            __('That wallet was set up for %s and stays with that site. Nothing in it was changed.', 'cashupay'),
            $sharedUrl
        ));
    }
    echo '</p><p>'
        . esc_html__('If this shop took payments with CashuPay before this update, do not set up a new wallet here: its money is in that shared folder. Ask whoever looks after this WordPress network for help before you go on.', 'cashupay')
        . '</p></div>';
}

/**
 * What the CashuPay dashboard would flag (customers cannot pay, withdrawals failing, a
 * security update, ...), in wp-admin where a WordPress operator spends their time. The
 * CashuPay dashboard is a separate page they rarely open (A12, 2026-10-28). Cached for
 * five minutes, so ordinary wp-admin pages stay fast.
 */
function cashupay_needs_attention_notice(): void {
    if (!cashupay_current_user_can_manage()) {
        return;
    }
    $problems = get_transient('cashupay_attention');
    if ($problems === false) {
        $problems = [];
        try {
            require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
            require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
            if (Database::isInitialized() && Config::isSetupComplete()) {
                require_once CASHUPAY_PLUGIN_DIR . '/includes/alerts.php';
                $problems = array_column(Alerts::problems(), 'text');
            }
        } catch (Throwable $e) {
            $problems = [];
        }
        set_transient('cashupay_attention', $problems, 5 * MINUTE_IN_SECONDS);
    }
    if (!$problems) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>' . esc_html__('CashuPay needs your attention.', 'cashupay') . '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
    foreach ($problems as $text) {
        echo '<li>' . esc_html($text) . '</li>';
    }
    echo '</ul><p><a href="' . esc_url(admin_url('tools.php?page=cashupay')) . '">'
        . esc_html__('Open CashuPay', 'cashupay') . '</a></p></div>';
}
