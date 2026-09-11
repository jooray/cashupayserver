<?php
/**
 * BareBits plugin — "install BareBits alongside WordPress".
 *
 * Downloads the latest stable BareBits release zip from GitHub, verifies it
 * against the release's SHA256SUMS when published, unpacks it into a
 * web-served directory next to the WordPress install, and writes the
 * deployment config (data directory outside the web root when possible, an
 * external-cron marker, and a one-time provisioning token hash) that lets the
 * onboarding flow collect credentials once the operator finishes the BareBits
 * setup wizard. Uses only WordPress filesystem/HTTP APIs. License: GPLv2 or
 * later.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Default directory name (under the web root) and URL path for the install. */
const BAREBITS_INSTALL_DEFAULT_DIRNAME = 'barebits';

/**
 * GitHub releases API base for the BareBits repository. Overridable for
 * testing/mirrors via the BAREBITS_RELEASE_API_BASE constant (wp-config.php)
 * or the barebits_release_api_base filter.
 */
function barebits_release_api_base(): string {
    $base = defined('BAREBITS_RELEASE_API_BASE')
        ? (string) BAREBITS_RELEASE_API_BASE
        : 'https://api.github.com/repos/BareBits/cashupayserver';
    return rtrim((string) apply_filters('barebits_release_api_base', $base), '/');
}

/**
 * Release channel THIS plugin build was cut from. 'stable' in the source
 * tree; the testing release workflow stamps 'testing' into the plugin zips
 * it publishes (scripts/build-wordpress-plugin.sh does the stamping).
 */
const BAREBITS_PLUGIN_RELEASE_CHANNEL = 'stable';

/**
 * Which release channel the install-alongside flow downloads from. A plugin
 * must install a server from its own channel: GitHub's /releases/latest only
 * ever returns the newest STABLE release, so a testing-channel plugin
 * pointing there would deploy a server missing the features this very flow
 * depends on (the provisioning handshake, the pre-seeded admin, the pinned
 * base URL) — the merchant would be walked into a wizard that asks for a
 * password and an onboarding that can never collect credentials. Overridable
 * per site via a BAREBITS_RELEASE_CHANNEL constant (wp-config.php) or the
 * barebits_release_channel filter; anything but 'testing' means 'stable'.
 */
function barebits_release_channel(): string {
    $channel = defined('BAREBITS_RELEASE_CHANNEL')
        ? (string) BAREBITS_RELEASE_CHANNEL
        : BAREBITS_PLUGIN_RELEASE_CHANNEL;
    $channel = (string) apply_filters('barebits_release_channel', $channel);
    return $channel === 'testing' ? 'testing' : 'stable';
}

/**
 * Where the alongside install goes. $dirname is the merchant's optional
 * override from the onboarding form; it is a single path segment, not a full
 * path, so the result is always web-served under the site. Preference order:
 * a sibling of wp-admin/ in the web root (served at /{dirname}/ exactly like
 * a hand-made standalone install), falling back to wp-content when the web
 * root is not writable.
 *
 * @return array{dir?:string, url?:string, error?:string}
 */
function barebits_resolve_install_target(string $dirname = ''): array {
    $dirname = trim($dirname);
    if ($dirname === '') {
        $dirname = BAREBITS_INSTALL_DEFAULT_DIRNAME;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $dirname)) {
        return ['error' => 'The folder name may only contain letters, numbers, hyphens and underscores.'];
    }
    if (wp_is_writable(ABSPATH)) {
        return [
            'dir' => rtrim(ABSPATH, '/\\') . '/' . $dirname,
            'url' => site_url('/' . $dirname),
        ];
    }
    if (wp_is_writable(WP_CONTENT_DIR)) {
        return [
            'dir' => rtrim(WP_CONTENT_DIR, '/\\') . '/' . $dirname,
            'url' => content_url('/' . $dirname),
        ];
    }
    return ['error' => 'Neither the web root nor wp-content is writable, so BareBits cannot be installed automatically on this host.'];
}

/**
 * Where the BareBits data directory goes: outside the web root when the
 * parent of the web root is writable (so the database can never be fetched
 * over HTTP), else inside the install's own data/ directory — which the
 * release ships pre-protected with a deny-all .htaccess, and the BareBits
 * setup wizard's security screen verifies protection on such layouts.
 *
 * The outside directory is namespaced per site (a hash of ABSPATH): two
 * WordPress sites whose docroots share a parent — the standard shared-
 * hosting layout — must never resolve to the same data directory, or the
 * second install would silently reuse the first one's wallet database. A
 * previously recorded directory (this site's own earlier install, including
 * pre-namespacing 'barebits-data' layouts) is reused; an UNRECORDED existing
 * directory is never adopted — whatever lives there is not ours.
 */
function barebits_resolve_data_dir(string $installDir): string {
    $recorded = (string) get_option('barebits_install_data_dir', '');
    if ($recorded !== '' && is_dir($recorded) && wp_is_writable($recorded)) {
        return $recorded;
    }
    $outside = dirname(rtrim(ABSPATH, '/\\'))
        . '/barebits-data-' . substr(hash('sha256', ABSPATH), 0, 12);
    if (is_dir($outside) && wp_is_writable($outside)) {
        return $outside;
    }
    // Deliberately mkdir, not wp_mkdir_p: the data directory holds wallet
    // keys and must be created 0750 (no world access), a mode wp_mkdir_p
    // cannot be told to use — it copies the parent's permissions.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
    if (!is_dir($outside) && wp_is_writable(dirname($outside)) && @mkdir($outside, 0750, true)) {
        return $outside;
    }
    return rtrim($installDir, '/') . '/data';
}

/**
 * Preflight checks shown on (and gating) the install screen. The BareBits
 * server will run under the same PHP as WordPress, so checking this process
 * checks the server too. Returns [label => ['ok' => bool, 'detail' => string]].
 */
function barebits_install_preflight(): array {
    $target = barebits_resolve_install_target((string) get_option('barebits_install_dirname', ''));
    $checks = [
        'PHP ' . PHP_VERSION . ' (8.0+ required)' => [
            'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'detail' => '',
        ],
        'cURL extension' => ['ok' => extension_loaded('curl'), 'detail' => ''],
        'PDO SQLite extension' => ['ok' => extension_loaded('pdo_sqlite'), 'detail' => ''],
        'GMP or BCMath extension' => [
            'ok' => extension_loaded('gmp') || extension_loaded('bcmath'),
            'detail' => extension_loaded('gmp') ? '' : 'BCMath works; GMP is much faster and required for on-chain xpub wallets and swaps.',
        ],
        'Writable install location' => [
            'ok' => !isset($target['error']),
            'detail' => $target['error'] ?? ('Will install to ' . ($target['dir'] ?? '')),
        ],
        'Direct filesystem access' => [
            'ok' => barebits_can_install_plugins(),
            'detail' => 'WordPress must be able to write files without FTP credentials.',
        ],
    ];
    return $checks;
}

/**
 * The first failing preflight check as "label — detail" (label alone when
 * the check carries no detail), or null when the host passes them all. The
 * chooser disables the install option on a failure, but that gate is only
 * markup: the choose and install POST handlers refuse on this same answer,
 * so a hand-crafted POST or a chooser rendered before conditions changed
 * can never start an install the host cannot run.
 */
function barebits_install_preflight_failure(): ?string {
    foreach (barebits_install_preflight() as $label => $check) {
        if (empty($check['ok'])) {
            return $label . ($check['detail'] !== '' ? ' — ' . $check['detail'] : '');
        }
    }
    return null;
}

/**
 * Resolve the newest release's app zip (and SHA256SUMS when the release
 * publishes one) for this plugin's channel: /releases/latest (newest stable)
 * on the stable channel, the head of /releases (newest release of ANY kind,
 * prereleases included — the same semantics as the server's own 'testing'
 * update channel) on the testing channel.
 *
 * @return array{ok:bool, message?:string, tag?:string, zip_url?:string, zip_name?:string, sums_url?:?string}
 */
function barebits_fetch_latest_release(): array {
    $testing = barebits_release_channel() === 'testing';
    $endpoint = $testing ? '/releases?per_page=15' : '/releases/latest';
    $response = wp_remote_get(barebits_release_api_base() . $endpoint, [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/vnd.github+json'],
    ]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'message' => 'Could not reach GitHub: ' . $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $release = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($testing && is_array($release)) {
        // The listing is newest-first; drafts never appear for anonymous
        // callers but are skipped anyway in case a token-authed proxy is in
        // front of the API.
        $release = array_values(array_filter($release, function ($entry) {
            return is_array($entry) && empty($entry['draft']);
        }))[0] ?? null;
    }
    if ($code !== 200 || !is_array($release) || empty($release['assets'])) {
        return ['ok' => false, 'message' => 'GitHub did not return a usable '
            . ($testing ? 'testing-channel release' : 'latest release') . ' (HTTP ' . $code . ').'];
    }

    $zipUrl = $zipName = null;
    $sumsUrl = null;
    foreach ((array) $release['assets'] as $asset) {
        $name = (string) ($asset['name'] ?? '');
        $url = (string) ($asset['browser_download_url'] ?? '');
        if ($name === 'SHA256SUMS') {
            $sumsUrl = $url;
            continue;
        }
        // The standalone app zip only — never the WordPress-plugin or
        // Windows-desktop artifacts that sit on the same release.
        if ($zipUrl === null
                && preg_match('/^barebits(-.+)?\.zip$/', $name)
                && strpos($name, 'windows') === false
                && strpos($name, 'wordpress') === false) {
            $zipUrl = $url;
            $zipName = $name;
        }
    }
    if ($zipUrl === null) {
        return ['ok' => false, 'message' => 'The latest release has no standalone BareBits zip attached.'];
    }
    return [
        'ok' => true,
        'tag' => (string) ($release['tag_name'] ?? ''),
        'zip_url' => $zipUrl,
        'zip_name' => $zipName,
        'sums_url' => $sumsUrl,
    ];
}

/**
 * Download the release zip to a temp file and, when the release publishes
 * SHA256SUMS, verify the zip's hash against it. Returns the local path or an
 * error. The caller must delete the returned file.
 *
 * @return array{ok:bool, file?:string, message?:string, verified?:bool}
 */
function barebits_download_release(array $release): array {
    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $file = download_url($release['zip_url'], 300);
    if (is_wp_error($file)) {
        return ['ok' => false, 'message' => 'Download failed: ' . $file->get_error_message()];
    }

    $verified = false;
    if (!empty($release['sums_url'])) {
        $sums = wp_remote_get($release['sums_url'], ['timeout' => 30]);
        if (is_wp_error($sums) || (int) wp_remote_retrieve_response_code($sums) !== 200) {
            wp_delete_file($file);
            return ['ok' => false, 'message' => 'The release publishes SHA256SUMS but it could not be downloaded; aborting rather than installing unverified code.'];
        }
        $expected = null;
        foreach (preg_split('/\r?\n/', (string) wp_remote_retrieve_body($sums)) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m)
                    && trim($m[2]) === $release['zip_name']) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            wp_delete_file($file);
            return ['ok' => false, 'message' => 'SHA256SUMS has no entry for ' . $release['zip_name'] . '; aborting.'];
        }
        if (!hash_equals($expected, hash_file('sha256', $file))) {
            wp_delete_file($file);
            return ['ok' => false, 'message' => 'Checksum mismatch on the downloaded release — refusing to install it.'];
        }
        $verified = true;
    }

    return ['ok' => true, 'file' => $file, 'verified' => $verified];
}

/**
 * Unpack the release zip (top-level directory `barebits/`) into
 * $installDir. Unzips to a staging directory first so a half-extracted
 * archive can never masquerade as an install.
 *
 * @return array{ok:bool, message?:string}
 */
function barebits_unpack_release(string $zipPath, string $installDir): array {
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!WP_Filesystem()) {
        return ['ok' => false, 'message' => 'Could not initialize the WordPress filesystem API.'];
    }

    // Stage under wp-content/upgrade — WordPress's own staging area for
    // plugin/core unpacks — never next to the install target: the target's
    // parent is usually the web root, and even a dot-prefixed half-extracted
    // tree should not sit in a served directory while it exists. The
    // cross-directory rename below may cross filesystems in exotic setups;
    // the copy_dir fallback covers that.
    $staging = WP_CONTENT_DIR . '/upgrade/barebits-staging-' . wp_generate_password(8, false, false);
    if (!wp_mkdir_p($staging)) {
        return ['ok' => false, 'message' => 'Could not create the staging directory.'];
    }

    // Staging only, never persistence: wp-content/upgrade is WordPress core's
    // own unpack staging area, and the tree is moved to its final home (and
    // the staging directory deleted) a few lines down. Nothing is stored
    // under wp-content across requests.
    // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
    $result = unzip_file($zipPath, $staging);
    if (is_wp_error($result)) {
        $wp_filesystem->delete($staging, true);
        return ['ok' => false, 'message' => 'Unzip failed: ' . $result->get_error_message()];
    }

    $unpacked = $staging . '/barebits';
    if (!is_dir($unpacked) || !is_file($unpacked . '/BUILD_INFO')) {
        $wp_filesystem->delete($staging, true);
        return ['ok' => false, 'message' => 'The downloaded zip does not look like a BareBits release (no barebits/BUILD_INFO inside).'];
    }

    // A release that predates the managed-install support cannot be finished
    // by this flow: its wizard would demand a password nobody provisioned and
    // there is no provisioning endpoint to collect credentials from — the
    // merchant would only discover that after walking the whole wizard.
    // provision.php shipping in the zip is the canonical marker for the whole
    // feature set (it landed together with managed.php / sso.php / the
    // config constants this installer writes).
    if (!is_file($unpacked . '/provision.php')) {
        $wp_filesystem->delete($staging, true);
        return ['ok' => false, 'message' => 'The newest release on this update channel does not support '
            . 'being installed by this plugin yet (it predates the automatic-setup handshake). '
            . 'Use "Start over" and connect a BareBits server you set up yourself by URL, '
            . 'or try again once a newer release is published.'];
    }

    if (!wp_mkdir_p(dirname($installDir)) || !$wp_filesystem->move($unpacked, $installDir)) {
        // The move (a rename underneath) can fail across filesystems; fall
        // back to a copy.
        if (!wp_mkdir_p($installDir) || is_wp_error(copy_dir($unpacked, $installDir))) {
            $wp_filesystem->delete($staging, true);
            return ['ok' => false, 'message' => 'Could not move the unpacked release into place at ' . $installDir . '.'];
        }
    }
    $wp_filesystem->delete($staging, true);
    return ['ok' => true];
}

/**
 * Write the install's user_config.php. Everything BareBits needs to run as a
 * managed single-shop install is declared here as data — never code:
 *
 *   - the data directory and pinned base URL,
 *   - CASHUPAY_MANAGED_INSTALL (shapes the product for the single-shop
 *     case and implies the wizard's cron-screen skip — WP-cron pings
 *     cron.php),
 *   - the shop's front page + retry endpoint for payer-facing links,
 *   - a pre-seeded admin password (hash only; the wizard skips its
 *     password screen and this plugin can reveal the plaintext to the
 *     site admin when BareBits ever asks for it),
 *   - an SSO key (hash only) so opening BareBits from wp-admin signs the
 *     operator in via single-use login tokens,
 *   - a one-time provisioning token (hash only) for the credentials
 *     handshake after setup,
 *   - the return URL the wizard's completion screen sends the operator to,
 *     where the plugin collects credentials and resumes onboarding.
 *
 * The plaintexts are returned and kept in WordPress options; only hashes
 * ever touch the BareBits side.
 *
 * @return array{ok:bool, token?:string, admin_password?:string, sso_key?:string, message?:string}
 */
function barebits_write_install_config(string $installDir, string $dataDir, string $baseUrl): array {
    $token = bin2hex(random_bytes(32));
    $adminPassword = wp_generate_password(24, true, false);
    $ssoKey = bin2hex(random_bytes(32));
    // Not debug output: var_export() is generating the PHP config file the
    // BareBits install boots from — safely quoted PHP string literals.
    // phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
    $config = "<?php\n"
        . "// Written by the BareBits WordPress plugin's installer.\n"
        . "define('CASHUPAY_DATA_DIR', " . var_export(rtrim($dataDir, '/'), true) . ");\n"
        . "// The URL this install is served at; pinned so the app never has\n"
        . "// to trust the Host header or guess its own path.\n"
        . "define('CASHUPAY_BASE_URL', " . var_export(rtrim($baseUrl, '/'), true) . ");\n"
        . "// Managed single-shop install: single-store admin UI, shop-owned\n"
        . "// sections hidden, payer email capture defaulted off, cron screen\n"
        . "// skipped (WP-cron pings cron.php every minute).\n"
        . "define('CASHUPAY_MANAGED_INSTALL', true);\n"
        . "// Payer-facing links prefer the shop.\n"
        . "define('CASHUPAY_SHOP_URL', " . var_export(rtrim(home_url('/'), '/'), true) . ");\n"
        . "define('CASHUPAY_RETRY_URL_TEMPLATE', " . var_export(home_url('/?barebits-retry={invoiceId}'), true) . ");\n"
        . "// Pre-seeded admin account (wizard skips its password screen); the\n"
        . "// plaintext is held by the WordPress plugin (BareBits page -> reveal).\n"
        . "define('CASHUPAY_ADMIN_PASSWORD_HASH', " . var_export(password_hash($adminPassword, PASSWORD_DEFAULT), true) . ");\n"
        . "// SSO login-token handoff (see sso.php in the install).\n"
        . "define('CASHUPAY_SSO_KEY_HASH', '" . hash('sha256', $ssoKey) . "');\n"
        . "// One-time provisioning handshake (see provision.php in the install).\n"
        . "define('CASHUPAY_PROVISION_TOKEN_HASH', '" . hash('sha256', $token) . "');\n"
        . "// Where the wizard's completion screen sends the operator: the\n"
        . "// plugin's return endpoint, which collects the credentials through\n"
        . "// the handshake above and finishes the WooCommerce wiring flow.\n"
        . "define('CASHUPAY_MANAGED_RETURN_URL', " . var_export(admin_url('admin-post.php') . '?action=barebits_provision_return', true) . ");\n";
    // phpcs:enable
    if (file_put_contents(rtrim($installDir, '/') . '/user_config.php', $config) === false) {
        return ['ok' => false, 'message' => 'Could not write user_config.php into the install.'];
    }
    if (!is_dir($dataDir) && !wp_mkdir_p($dataDir)) {
        return ['ok' => false, 'message' => 'Could not create the data directory at ' . $dataDir . '.'];
    }
    return ['ok' => true, 'token' => $token, 'admin_password' => $adminPassword, 'sso_key' => $ssoKey];
}

/**
 * The full install: resolve target → fetch release → download+verify →
 * unpack → write config → persist state. Idempotent: an already-completed
 * install (our own user_config.php present) is reused rather than clobbered,
 * and a directory we did not create is never touched.
 *
 * @return array{ok:bool, message?:string, url?:string, verified?:bool}
 */
function barebits_run_install(string $dirname = ''): array {
    $target = barebits_resolve_install_target($dirname);
    if (isset($target['error'])) {
        return ['ok' => false, 'message' => $target['error']];
    }
    $installDir = $target['dir'];

    if (is_dir($installDir)) {
        $ours = (string) get_option('barebits_install_dir', '');
        if ($ours === $installDir && is_file($installDir . '/user_config.php') && is_file($installDir . '/BUILD_INFO')) {
            // Our own earlier install — but only resumable if that release can
            // actually finish this flow. An install made while the plugin still
            // downloaded /releases/latest may be a pre-managed-install release
            // (no provision.php): resuming it would walk the merchant into the
            // same dead end the channel fix exists to prevent. Never deleted
            // automatically — the folder could hold a database.
            if (!is_file($installDir . '/provision.php')) {
                return ['ok' => false, 'message' => 'A BareBits install from an older release is already at '
                    . $installDir . ', and it is too old for this plugin to finish setting up. '
                    . 'Rename that folder aside (renaming keeps anything stored inside it), '
                    . 'then run the install again.'];
            }
            // Resuming after a partial onboarding run (or after "Start over"
            // kept the install record): the install is in place. Restore the
            // connection options a reset may have cleared — but never the
            // credentials, whose hashes are baked into the install's own
            // user_config.php and must not be regenerated out from under it.
            update_option('barebits_mode', 'install');
            update_option('barebits_server_url', $target['url']);
            update_option('barebits_install_url', $target['url'], false);
            return ['ok' => true, 'url' => $target['url'], 'verified' => true];
        }
        if (count(array_diff((array) scandir($installDir), ['.', '..'])) > 0) {
            return ['ok' => false, 'message' => 'The directory ' . $installDir . ' already exists and is not empty. Move it aside, or choose a different folder name.'];
        }
        // Empty leftover directory: remove it so the unpack's move can take
        // its place. Just-verified empty, and the WP_Filesystem API is not
        // initialized this early in the flow (barebits_unpack_release does
        // that right before the move).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        @rmdir($installDir);
    }

    $release = barebits_fetch_latest_release();
    if (empty($release['ok'])) {
        return ['ok' => false, 'message' => $release['message']];
    }

    $download = barebits_download_release($release);
    if (empty($download['ok'])) {
        return ['ok' => false, 'message' => $download['message']];
    }

    $unpack = barebits_unpack_release($download['file'], $installDir);
    wp_delete_file($download['file']);
    if (empty($unpack['ok'])) {
        return ['ok' => false, 'message' => $unpack['message']];
    }

    $dataDir = barebits_resolve_data_dir($installDir);
    $config = barebits_write_install_config($installDir, $dataDir, $target['url']);
    if (empty($config['ok'])) {
        return ['ok' => false, 'message' => $config['message']];
    }

    update_option('barebits_mode', 'install');
    update_option('barebits_install_dir', $installDir, false);
    update_option('barebits_install_data_dir', $dataDir, false);
    update_option('barebits_server_url', $target['url']);
    // The install's own address, kept separate from the connected-server URL
    // so the cron heartbeat can outlive resets and mode changes.
    update_option('barebits_install_url', $target['url'], false);
    update_option('barebits_provision_token', $config['token'], false);
    // The BareBits admin password (its account is pre-seeded from the hash;
    // day-to-day login is automatic via SSO — this is the copy the site
    // admin can reveal when BareBits asks for a password, e.g. revealing a
    // wallet recovery phrase) and the SSO key that mints login tokens.
    update_option('barebits_admin_password', $config['admin_password'], false);
    update_option('barebits_sso_key', $config['sso_key'], false);

    return ['ok' => true, 'url' => $target['url'], 'verified' => !empty($download['verified'])];
}

/**
 * Classify one probe answer from the install's API:
 *
 *   'ok'          it answered like BareBits — pre-setup the endpoint answers
 *                 503 with a JSON body, after setup 200; both prove the
 *                 request reached the install.
 *   'unreachable' the HTTP request itself failed (timeout, connection
 *                 refused) — the site cannot reach the URL at all.
 *   'unexpected'  some OTHER HTTP answer: a WordPress 404 page, a
 *                 redirect-a-404-home plugin, a security plugin or WAF
 *                 intercepting, a fatal error in the install. The JSON check
 *                 matters: an HTML page must never count as the API.
 *
 * Pure (no WordPress calls) so tests/php can pin the matrix without a
 * WordPress install; barebits_install_loopback_verdict() is the live prober.
 */
function barebits_api_probe_verdict(bool $requestFailed, int $code, string $body): string {
    if ($requestFailed) {
        return 'unreachable';
    }
    return in_array($code, [200, 503], true) && is_array(json_decode($body, true))
        ? 'ok'
        : 'unexpected';
}

/**
 * Can this WordPress site reach its own fresh install over HTTP at all?
 * Payments depend on it — the WP-cron heartbeat, the API bridge, and
 * checkout's Greenfield calls all ride loopback requests — so a host that
 * blocks them is surfaced right after the install, while the merchant is
 * still looking at the screen, instead of as a cryptic failure later.
 *
 * Deliberately probes api.php directly (the query-path transport, one
 * loopback deep — the same URL shape every call the plugin itself makes
 * uses), NOT the canonical /api/v1 route: on rewrite-hostile hosts the
 * canonical form is only answered by the API bridge (api-bridge.php), and
 * probing through that chain from inside the installer request needs a
 * THIRD simultaneous PHP worker — tight per-site pools (Local WP) starve it
 * into a false "loopback blocked" alarm, and the abandoned bridge request
 * then wedges a worker for its full inner timeout. api.php is a real file
 * every host executes directly, so this probe answers wherever loopback
 * works at all.
 */
function barebits_install_loopback_verdict(string $url): string {
    $response = wp_remote_get(barebits_api_transport_url($url, '/api/v1/server/info', $url), [
        'timeout' => 10,
        'sslverify' => !barebits_is_same_host_url($url),
    ]);
    if (is_wp_error($response)) {
        return 'unreachable';
    }
    return barebits_api_probe_verdict(
        false,
        (int) wp_remote_retrieve_response_code($response),
        (string) wp_remote_retrieve_body($response)
    );
}

/**
 * Collect the install's credentials through the one-time provisioning
 * handshake, once the operator has finished the BareBits setup wizard.
 *
 * @return array{status:'ready'|'pending'|'error', message?:string,
 *               storeId?:string, apiKey?:string, cronKey?:string}
 */
function barebits_collect_provision(): array {
    $server = barebits_server_url();
    $token = (string) get_option('barebits_provision_token', '');
    if ($server === '' || $token === '') {
        return ['status' => 'error', 'message' => 'No provisioning token — run the installer first.'];
    }
    $response = wp_remote_post($server . '/provision.php', [
        'timeout' => 15,
        'sslverify' => !barebits_is_same_host_url($server),
        'headers' => ['X-PROVISION-TOKEN' => $token],
    ]);
    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => 'Could not reach the install: ' . $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return ['status' => 'error', 'message' => 'Unexpected answer from the install (HTTP ' . $code . ').'];
    }
    if (($body['status'] ?? '') === 'pending') {
        return ['status' => 'pending'];
    }
    if (($body['status'] ?? '') === 'ready'
            && !empty($body['storeId']) && !empty($body['apiKey']) && !empty($body['cronKey'])) {
        // Single use on both sides: the server just invalidated the exchange,
        // so the plaintext token has no further value here either.
        delete_option('barebits_provision_token');
        return [
            'status' => 'ready',
            'storeId' => (string) $body['storeId'],
            'apiKey' => (string) $body['apiKey'],
            'cronKey' => (string) $body['cronKey'],
        ];
    }
    return ['status' => 'error', 'message' => (string) ($body['error'] ?? ('HTTP ' . $code))];
}
