<?php
/**
 * WordPress installer (wordpress/installer.php) — release-channel selection
 * and the post-install API-routing probe.
 *
 * The install-alongside flow originally read GitHub's /releases/latest, which
 * only ever answers with the newest STABLE release — so a testing-channel
 * plugin deployed a stable server missing the whole managed-install feature
 * set (the provisioning handshake above all) and walked the merchant into a
 * wizard that asked for a password and an onboarding that could never finish.
 * This file pins the channel logic that prevents that: the stable channel
 * keeps /releases/latest, the testing channel reads the head of /releases
 * (newest release of any kind, drafts skipped), and the channel value itself
 * is sanitized. Plus the post-install loopback probe (barebits_api_probe_verdict
 * / barebits_install_loopback_verdict), which tells a BareBits JSON answer
 * apart from an intercepted one or no answer at all — and must go to the
 * install's api.php directly, never the canonical /api/v1 route (whose
 * bridge chain starves tight worker pools into a false "blocked" alarm).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/barebits_channel_' . bin2hex(random_bytes(6)) . '/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

// --- minimal WordPress stubs -------------------------------------------------
$GLOBALS['filters'] = [];                // hook => forced return value
$GLOBALS['http_routes'] = [];            // url-substring => ['code'=>…, 'body'=>…] | 'error'
$GLOBALS['http_log'] = [];               // every wp_remote_get url

function apply_filters($hook, $value) {
    return array_key_exists($hook, $GLOBALS['filters']) ? $GLOBALS['filters'][$hook] : $value;
}
function get_option($name, $default = false) { return $default; }
function site_url($path = '') { return 'http://wp.test' . $path; }

class WP_Error {
    public function __construct(private string $msg = 'stub error') {}
    public function get_error_message(): string { return $this->msg; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

function wp_remote_get($url, $args = []) {
    $GLOBALS['http_log'][] = $url;
    foreach ($GLOBALS['http_routes'] as $needle => $response) {
        if (str_contains($url, $needle)) {
            return $response === 'error' ? new WP_Error('unreachable') : $response;
        }
    }
    return new WP_Error('no stub route for ' . $url);
}
function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

// installer.php calls these (defined in state.php in the real plugin).
function barebits_is_same_host_url(string $url): bool { return true; }
// Mirrors state.php's same-origin form: the loopback probe always targets the
// install's own URL, so this is the branch the real function would take.
function barebits_api_transport_url(string $server, string $path, string $installUrl): string {
    return $server . '/api.php?cashupay_path=' . rawurlencode($path);
}

require dirname(__DIR__, 2) . '/wordpress/installer.php';

// =============================================================================
// barebits_release_channel — default, override, sanitization
// =============================================================================

assert_eq('stable', barebits_release_channel(), 'the in-tree default channel is stable');

$GLOBALS['filters']['barebits_release_channel'] = 'testing';
assert_eq('testing', barebits_release_channel(), 'the filter can put a site on the testing channel');

$GLOBALS['filters']['barebits_release_channel'] = 'nightly-of-doom';
assert_eq('stable', barebits_release_channel(), 'anything but "testing" collapses to stable');
unset($GLOBALS['filters']['barebits_release_channel']);

// =============================================================================
// barebits_fetch_latest_release — stable reads /releases/latest
// =============================================================================

$assets = [
    ['name' => 'SHA256SUMS', 'browser_download_url' => 'http://releases.test/dl/SHA256SUMS'],
    ['name' => 'barebits-v9.9.zip', 'browser_download_url' => 'http://releases.test/dl/barebits.zip'],
];

$GLOBALS['http_routes'] = ['/releases/latest' => [
    'code' => 200,
    'body' => json_encode(['tag_name' => 'v9.9', 'assets' => $assets]),
]];
$GLOBALS['http_log'] = [];
$r = barebits_fetch_latest_release();
assert_eq(true, $r['ok'], 'stable channel resolves the latest stable release');
assert_eq('v9.9', $r['tag'], 'with its tag');
assert_eq('barebits-v9.9.zip', $r['zip_name'], 'and the standalone zip');
assert_eq(1, count($GLOBALS['http_log']), 'one API call');
assert_true(str_contains($GLOBALS['http_log'][0], '/releases/latest'), 'to /releases/latest');
assert_false(str_contains($GLOBALS['http_log'][0], '/releases?'), 'never the prerelease listing');

// =============================================================================
// barebits_fetch_latest_release — testing reads the head of /releases
// =============================================================================

$GLOBALS['filters']['barebits_release_channel'] = 'testing';

// Newest-first listing: a draft (never installable) ahead of the prerelease
// the testing channel must pick, with an older stable behind it.
$GLOBALS['http_routes'] = ['/releases?' => [
    'code' => 200,
    'body' => json_encode([
        ['tag_name' => 'v9.10-draft', 'draft' => true, 'prerelease' => true, 'assets' => $assets],
        ['tag_name' => 'v9.9-testing.7', 'draft' => false, 'prerelease' => true, 'assets' => [
            ['name' => 'SHA256SUMS', 'browser_download_url' => 'http://releases.test/dl/SHA256SUMS'],
            ['name' => 'barebits-v9.9-testing.7.zip', 'browser_download_url' => 'http://releases.test/dl/t.zip'],
        ]],
        ['tag_name' => 'v9.8', 'draft' => false, 'prerelease' => false, 'assets' => $assets],
    ]),
]];
$GLOBALS['http_log'] = [];
$r = barebits_fetch_latest_release();
assert_eq(true, $r['ok'], 'testing channel resolves a release');
assert_eq('v9.9-testing.7', $r['tag'], 'the newest NON-DRAFT release, prereleases included');
assert_eq('barebits-v9.9-testing.7.zip', $r['zip_name'], 'with its standalone zip');
assert_true(str_contains($GLOBALS['http_log'][0], '/releases?'), 'read from the /releases listing');

// An empty listing (or one of drafts only) is a clean channel-specific error.
$GLOBALS['http_routes'] = ['/releases?' => ['code' => 200, 'body' => json_encode([])]];
$r = barebits_fetch_latest_release();
assert_eq(false, $r['ok'], 'an empty listing fails cleanly');
assert_true(str_contains($r['message'], 'testing-channel release'), 'naming the channel');

unset($GLOBALS['filters']['barebits_release_channel']);

// =============================================================================
// barebits_api_probe_verdict — the pure classification matrix
// =============================================================================

assert_eq('ok', barebits_api_probe_verdict(false, 503, json_encode(['code' => 'service-unavailable'])),
    'a pre-setup 503 JSON answer proves the request reached BareBits');
assert_eq('ok', barebits_api_probe_verdict(false, 200, json_encode(['version' => '9.9'])),
    'a 200 JSON answer counts too');
assert_eq('unreachable', barebits_api_probe_verdict(true, 0, ''),
    'a failed HTTP request (timeout, refused) means the site cannot reach the URL');
assert_eq('unexpected', barebits_api_probe_verdict(false, 404, '<html><body>Page not found</body></html>'),
    'a WordPress 404 page answered, so loopback works — but it is not the API');
assert_eq('unexpected', barebits_api_probe_verdict(false, 200, '<html><body>Welcome to the shop</body></html>'),
    'a 200 HTML page (404-to-home redirect plugins) is not the API either');
assert_eq('unexpected', barebits_api_probe_verdict(false, 500, json_encode(['error' => 'boom'])),
    'a 500 — even a JSON one — is a broken install, not a working API');
assert_eq('unexpected', barebits_api_probe_verdict(false, 200, json_encode('just a string')),
    'a JSON scalar is not an API object answer');

// =============================================================================
// barebits_install_loopback_verdict — the live probe goes to api.php directly
// =============================================================================

// Fresh install, wizard not run: api.php answers 503 with a JSON body. The
// probe must target the query-path transport (api.php?cashupay_path=…), NOT
// the canonical /api/v1 URL — on rewrite-hostile hosts the canonical form is
// only answered by the API bridge, whose three-request chain starves tight
// worker pools (Local WP) into a false "blocked" alarm.
$GLOBALS['http_routes'] = ['/api.php?cashupay_path=' => [
    'code' => 503,
    'body' => json_encode(['code' => 'service-unavailable', 'message' => 'BareBits setup not complete']),
]];
$GLOBALS['http_log'] = [];
assert_eq('ok', barebits_install_loopback_verdict('http://wp.test/barebits'),
    'a pre-setup 503 JSON answer from api.php probes ok');
assert_eq(1, count($GLOBALS['http_log']), 'one probe request');
assert_true(str_contains($GLOBALS['http_log'][0], '/api.php?cashupay_path='),
    'probed through the direct api.php transport');
assert_false(str_contains($GLOBALS['http_log'][0], '/barebits/api/v1/'),
    'never the canonical route (its bridge chain starves tight worker pools)');

// Loopback genuinely blocked: the request itself dies.
$GLOBALS['http_routes'] = ['/api.php?cashupay_path=' => 'error'];
assert_eq('unreachable', barebits_install_loopback_verdict('http://wp.test/barebits'),
    'an unreachable api.php means the site cannot request its own URL');

// Loopback works but something else answers (security plugin, WAF, fatal).
$GLOBALS['http_routes'] = ['/api.php?cashupay_path=' => ['code' => 403, 'body' => '<html>Forbidden</html>']];
assert_eq('unexpected', barebits_install_loopback_verdict('http://wp.test/barebits'),
    'an interception answers HTTP but is not the API — must not read as a loopback block');

echo "test_wp_installer_channel: ok\n";
