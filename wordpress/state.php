<?php
/**
 * BareBits plugin — configuration state and the HTTP client for talking to
 * the BareBits server.
 *
 * Everything the plugin knows lives in WordPress options with the barebits_
 * prefix; the BareBits server is only ever reached over HTTP with the
 * BTCPay-compatible `Authorization: token …` scheme. License: GPLv2 or later.
 *
 * Options:
 *   barebits_mode              'url' (existing server) | 'install' (alongside)
 *   barebits_server_url        BareBits base URL (the BTCPay "server URL")
 *   barebits_store_id          store id on that server
 *   barebits_install_dir       install mode: absolute path of the install
 *   barebits_install_url       install mode: the alongside install's own base
 *                              URL — survives mode changes so the cron
 *                              heartbeat can keep finding the install
 *   barebits_install_data_dir  install mode: absolute path of the data dir
 *   barebits_provision_token   install mode: one-time token (deleted on use)
 *   barebits_admin_password    install mode: the BareBits admin password the
 *                              installer generated (account pre-seeded from
 *                              its hash; revealable on the Connection page)
 *   barebits_sso_key           install mode: key that mints one-time BareBits
 *                              sign-in tokens (see barebits_sso_login_url)
 *   barebits_cron_key          install mode: key for the WP-cron pinger
 *   barebits_cron_last_ok      install mode: unix ts of the last successful
 *                              cron ping (drives the stale-heartbeat notice)
 *   barebits_wired_at          unix ts when WooCommerce wiring completed
 *   barebits_discount_percent  merchant's Bitcoin-checkout discount, percent
 *                              0-100 with up to two decimals, stored as the
 *                              normalized string payment-discount.php writes
 *   barebits_pairing_expected  unix ts while a pairing redirect is in flight
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether the install-alongside flow ships in this build. The wordpress.org
 * distribution excludes installer.php (the directory's guidelines forbid
 * plugins fetching executable code), so every install-related UI element and
 * POST handler gates on this; the GitHub distribution ships the full flow.
 */
function barebits_installer_available(): bool {
    return function_exists('barebits_run_install');
}

/** Chosen onboarding mode: 'url', 'install', or '' while undecided. */
function barebits_mode(): string {
    $mode = (string) get_option('barebits_mode', '');
    return in_array($mode, ['url', 'install'], true) ? $mode : '';
}

/** The BareBits server base URL (no trailing slash), or '' if not set yet. */
function barebits_server_url(): string {
    return rtrim((string) get_option('barebits_server_url', ''), '/');
}

/**
 * The base URL of the alongside install this plugin provisioned (no trailing
 * slash), or '' when none exists. Deliberately distinct from
 * barebits_server_url: the CONNECTED server can change — "Start over", then
 * reconnecting the install by URL, or connecting some other server entirely —
 * while the install this plugin promised a cron heartbeat to (it was
 * provisioned with its own cron screen skipped) keeps running at its own
 * address. Installs recorded before this option existed are backfilled from
 * the connected URL while the plugin is still in install mode, when the two
 * are the same thing by construction.
 */
function barebits_install_url(): string {
    if ((string) get_option('barebits_install_dir', '') === '') {
        return '';
    }
    $url = rtrim((string) get_option('barebits_install_url', ''), '/');
    if ($url !== '') {
        return $url;
    }
    // Backfill for installs recorded before this option existed. In install
    // mode the connected server IS the install, so the connected URL is
    // proven and worth persisting. After an old-code reset (mode '') the
    // surviving connected URL is still the best available answer, but only
    // install mode proves it — return it without persisting the guess. In
    // URL mode the connected server may be a different host entirely.
    if (barebits_mode() === 'install') {
        $url = barebits_server_url();
        if ($url !== '') {
            update_option('barebits_install_url', $url, false);
        }
        return $url;
    }
    return barebits_mode() === '' ? barebits_server_url() : '';
}

/** Whether onboarding finished: a server is connected and WooCommerce wired. */
function barebits_is_configured(): bool {
    return barebits_server_url() !== '' && (int) get_option('barebits_wired_at', 0) > 0;
}

/**
 * Whether $url points at this WordPress site's own origin — scheme, host,
 * AND port. Same-origin requests (the alongside install, a loopback cron
 * ping) skip TLS peer verification the same way WordPress core's own
 * loopbacks do — staging boxes with self-signed certificates would
 * otherwise break themselves. The comparison is deliberately the full
 * origin, not the hostname alone: a different service on another port of
 * the same host is NOT this site and gets verified like any remote server.
 */
function barebits_is_same_host_url(string $url): bool {
    $target = wp_parse_url($url);
    $self = wp_parse_url(site_url('/'));
    if (!is_array($target) || !is_array($self) || empty($target['host']) || empty($self['host'])) {
        return false;
    }
    $port = static function (array $parts): int {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    };
    return strtolower($target['host']) === strtolower($self['host'])
        && strtolower((string) ($target['scheme'] ?? '')) === strtolower((string) ($self['scheme'] ?? ''))
        && $port($target) === $port($self);
}

/**
 * The absolute URL a Greenfield API call against $server should target.
 *
 * For the same-origin alongside install ($server is the install this plugin
 * provisioned), that is api.php with the API path carried in the
 * cashupay_path query parameter — api.php's query-path transport — rather
 * than the canonical /api/v1 path. On rewrite-hostile hosts (Local WP,
 * managed nginx) the canonical URL falls through the web server into
 * WordPress, where the API bridge replays it against api.php: one plugin
 * call becomes a chain of THREE simultaneous PHP requests on the same site
 * (the wp-admin request making the call, the bridged WordPress request, and
 * api.php). Hosts with small per-site PHP worker pools starve on that chain
 * and the call dies as a bare timeout — cURL error 28 at the WooCommerce
 * wiring step on Local WP. api.php is a real file every host executes
 * directly, so the query form works on friendly and hostile hosts alike and
 * keeps the plugin's own API calls one loopback deep. Any other server
 * (URL mode, remote) keeps the canonical URL its operator's setup proved.
 *
 * Pure (no WordPress calls) so tests/php can pin the selection without a
 * WordPress install; barebits_api_url() is the live wrapper. $server and
 * $installUrl arrive normalized (no trailing slash) from
 * barebits_server_url() / barebits_install_url().
 */
function barebits_api_transport_url(string $server, string $path, string $installUrl): string {
    if ($server === '' || $installUrl === '' || $server !== $installUrl) {
        return $server . $path;
    }
    // No current caller passes a query string in $path, but carry one through
    // the way the bridge does (as real parameters, outside cashupay_path)
    // rather than corrupting it into the encoded path.
    $query = '';
    $mark = strpos($path, '?');
    if ($mark !== false) {
        $query = '&' . substr($path, $mark + 1);
        $path = substr($path, 0, $mark);
    }
    return $server . '/api.php?cashupay_path=' . rawurlencode($path) . $query;
}

/** The live wrapper: transport decision against the recorded install. */
function barebits_api_url(string $server, string $path): string {
    return barebits_api_transport_url($server, $path, barebits_install_url());
}

/**
 * The server URL the BTCPay WooCommerce gateway should be configured with
 * (btcpay_gf_url).
 *
 * For the same-origin alongside install this is api.php's query-transport
 * BASE — {install}/api.php?cashupay_path= — not the bare install URL. The
 * gateway's Greenfield library builds every request URL by plain string
 * concatenation onto this value ({base}/api/v1/..., {base}/i/{invoiceId}),
 * so each call it makes lands directly on api.php with the path carried in
 * cashupay_path: one loopback deep, executable on every host. The canonical
 * bare-URL form would instead ride /install/api/v1/... — which, on
 * rewrite-hostile hosts, falls into WordPress and is replayed by the API
 * bridge as a THIRD simultaneous same-site PHP request. Hosts with small
 * per-site worker pools (Local WP) starve on that chain at checkout: the
 * invoice-creation call dies as a bare timeout and every single order fails
 * with the generic "payment could not be started" error. Same worker math,
 * same fix as barebits_api_transport_url() gave the plugin's own calls.
 *
 * Any other server (URL mode, remote) keeps the canonical URL its
 * operator's setup proved.
 *
 * Pure (no WordPress calls) so tests/php can pin the selection;
 * barebits_gateway_server_url() is the live wrapper. $server and
 * $installUrl arrive normalized (no trailing slash).
 */
function barebits_gateway_base_url(string $server, string $installUrl): string {
    if ($server === '' || $installUrl === '' || $server !== $installUrl) {
        return $server;
    }
    return $server . '/api.php?cashupay_path=';
}

/** The live wrapper: gateway base decision against the recorded install. */
function barebits_gateway_server_url(): string {
    return barebits_gateway_base_url(barebits_server_url(), barebits_install_url());
}

/**
 * Probe a candidate BareBits server URL: fetch {url}/api/v1/server/info and
 * require the isCashuPayServer marker. Returns ['ok' => true, 'version' => …]
 * or ['ok' => false, 'message' => operator-facing reason].
 */
function barebits_probe_server(string $url): array {
    $url = rtrim(trim($url), '/');
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'message' => 'Enter the full URL, starting with https:// (or http:// for a local server).'];
    }
    // Reconnecting the alongside install goes through api.php directly, like
    // every other plugin call to it — the canonical /api/v1 form would nest
    // through the bridge on rewrite-hostile hosts (see
    // barebits_api_transport_url).
    $response = wp_remote_get(barebits_api_url($url, '/api/v1/server/info'), [
        'timeout' => 10,
        'redirection' => 3,
        'sslverify' => !barebits_is_same_host_url($url),
    ]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'message' => 'Could not reach the server: ' . $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($body) || empty($body['isCashuPayServer'])) {
        return ['ok' => false, 'message' => 'That URL answered, but it does not look like a BareBits server (HTTP ' . $code . ').'];
    }
    return ['ok' => true, 'version' => (string) ($body['version'] ?? '')];
}

/**
 * Mint a one-time BareBits admin sign-in URL through the install's SSO
 * handoff (install mode only — the plugin holds the SSO key it provisioned).
 * Returns the URL to send the browser/iframe to, or null when SSO isn't
 * available (URL mode, setup not finished, install unreachable) — callers
 * fall back to the plain admin URL, where BareBits shows its own login.
 */
function barebits_sso_login_url(): ?string {
    $server = barebits_server_url();
    $ssoKey = (string) get_option('barebits_sso_key', '');
    if ($server === '' || $ssoKey === '') {
        return null;
    }
    $response = wp_remote_post($server . '/sso.php', [
        'timeout' => 10,
        'sslverify' => !barebits_is_same_host_url($server),
        'headers' => ['X-SSO-KEY' => $ssoKey],
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || ($body['status'] ?? '') !== 'ready' || empty($body['token'])) {
        return null;
    }
    return $server . '/sso.php?token=' . rawurlencode((string) $body['token']);
}

/**
 * Authenticated request against the configured BareBits server's Greenfield
 * API. $path is relative to the API base (e.g. '/api/v1/stores/x/webhooks').
 * Returns ['code' => int, 'body' => decoded array|null, 'error' => string|null].
 */
function barebits_api_request(string $method, string $path, ?array $body = null, ?string $apiKey = null): array {
    $server = barebits_server_url();
    if ($server === '') {
        return ['code' => 0, 'body' => null, 'error' => 'No BareBits server configured.'];
    }
    if ($apiKey === null) {
        $apiKey = (string) get_option('btcpay_gf_api_key', '');
    }
    $args = [
        'method' => $method,
        'timeout' => 15,
        'redirection' => 2,
        'sslverify' => !barebits_is_same_host_url($server),
        'headers' => [
            'Authorization' => 'token ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
    ];
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    $response = wp_remote_request(barebits_api_url($server, $path), $args);
    if (is_wp_error($response)) {
        $message = $response->get_error_message();
        // A timeout against this site's own origin is almost never the
        // server being slow — it is the host refusing or starving loopback
        // requests. Say so where the raw cURL text would send the merchant
        // hunting in the wrong direction.
        if (barebits_is_same_host_url($server) && stripos($message, 'timed out') !== false) {
            $message .= ' — the request went to this site\'s own URL, so this usually means '
                . 'the host blocks or limits requests from this site to itself (a "loopback" '
                . 'restriction, or too few PHP workers). Ask your host about allowing loopback requests.';
        }
        return ['code' => 0, 'body' => null, 'error' => $message];
    }
    return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => json_decode((string) wp_remote_retrieve_body($response), true),
        'error' => null,
    ];
}
