<?php
/**
 * Plugin → server API transport selection (wordpress/state.php).
 *
 * The plugin's own Greenfield calls to the same-origin alongside install must
 * go to api.php directly (the query-path transport), never the canonical
 * /api/v1 URL: on rewrite-hostile hosts the canonical form falls through into
 * WordPress and the API bridge replays it — one call becomes three nested
 * loopback PHP requests, which starves small per-site worker pools (Local WP)
 * into bare cURL timeouts at the WooCommerce wiring step. Remote servers must
 * keep the canonical URL. barebits_api_transport_url is the pure selector;
 * barebits_api_request / barebits_probe_server are exercised through stubs to
 * pin that they actually route through it — plus the same-host timeout hint
 * that replaces the raw cURL text with something a merchant can act on.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', '/tmp/');

$GLOBALS['wp_options'] = [];
$GLOBALS['http_requests'] = [];
$GLOBALS['http_error'] = null;

class WP_Error {
    private string $message;
    public function __construct(string $message = '') { $this->message = $message; }
    public function get_error_message(): string { return $this->message; }
}

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function site_url($path = '') { return 'http://wp.test' . $path; }
function wp_json_encode($data) { return json_encode($data); }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_request($url, $args = []) {
    $GLOBALS['http_requests'][] = ['url' => $url, 'args' => $args];
    if ($GLOBALS['http_error'] !== null) {
        return new WP_Error($GLOBALS['http_error']);
    }
    return ['response' => ['code' => 200], 'body' => '[]'];
}
function wp_remote_get($url, $args = []) { return wp_remote_request($url, $args); }

require __DIR__ . '/wp_compat_stubs.php';
require dirname(__DIR__, 2) . '/wordpress/state.php';

$install = 'http://wp.test/barebits';

// --- Pure selection ----------------------------------------------------------
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=%2Fapi%2Fv1%2Fserver%2Finfo',
    barebits_api_transport_url($install, '/api/v1/server/info', $install),
    'the alongside install is called through api.php directly'
);
assert_eq(
    'https://pay.example.com/api/v1/server/info',
    barebits_api_transport_url('https://pay.example.com', '/api/v1/server/info', $install),
    'a remote server keeps the canonical URL'
);
assert_eq(
    'https://pay.example.com/api/v1/server/info',
    barebits_api_transport_url('https://pay.example.com', '/api/v1/server/info', ''),
    'no install recorded: canonical URL'
);
assert_eq(
    '/api/v1/server/info',
    barebits_api_transport_url('', '/api/v1/server/info', ''),
    'an empty server URL is left alone (callers reject it before requesting)'
);
// A query string in the path travels as real parameters, outside the encoded
// cashupay_path — the same shape the API bridge forwards.
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=%2Fapi%2Fv1%2Finvoices&status=Settled',
    barebits_api_transport_url($install, '/api/v1/invoices?status=Settled', $install),
    'a query string survives the transport rewrite'
);

// --- Gateway base selection (btcpay_gf_url) ----------------------------------
// The BTCPay WooCommerce gateway builds every request URL by concatenation
// onto its configured server URL ({base}/api/v1/..., {base}/i/{invoiceId}).
// For the same-origin alongside install the wiring must hand it api.php's
// query-transport BASE, so the gateway's own calls are one loopback deep too
// — the canonical bare URL rides the API bridge's three-request chain, which
// starves Local WP-sized pools at checkout ("payment could not be started"
// on every order).
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=',
    barebits_gateway_base_url($install, $install),
    'the alongside install gateway base is the api.php query-transport form'
);
assert_eq(
    'https://pay.example.com',
    barebits_gateway_base_url('https://pay.example.com', $install),
    'a remote server keeps the canonical URL as the gateway base'
);
assert_eq(
    'https://pay.example.com',
    barebits_gateway_base_url('https://pay.example.com', ''),
    'no install recorded: canonical gateway base'
);
// Concatenation shapes the gateway actually produces off this base.
$gwBase = barebits_gateway_base_url($install, $install);
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=/api/v1/stores/s1/invoices',
    $gwBase . '/api/v1/stores/s1/invoices',
    'greenfield-php getApiUrl() concatenation lands on the query transport'
);
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=/i/inv123',
    $gwBase . '/i/inv123',
    "the gateway's /i/{invoiceId} re-checkout link lands on the query transport"
);

// --- barebits_api_request routes through the selector ------------------------
$GLOBALS['wp_options'] = [
    'barebits_mode' => 'install',
    'barebits_server_url' => $install,
    'barebits_install_dir' => '/var/www/wp/barebits',
    'barebits_install_url' => $install,
];
$GLOBALS['http_requests'] = [];
$result = barebits_api_request('GET', '/api/v1/stores/abc/webhooks', null, 'key');
assert_eq(200, $result['code'], 'stubbed request succeeds');
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=%2Fapi%2Fv1%2Fstores%2Fabc%2Fwebhooks',
    $GLOBALS['http_requests'][0]['url'],
    'install-mode API requests go to api.php, not through the bridge'
);

// URL mode against a remote server: canonical URL, untouched.
$GLOBALS['wp_options'] = ['barebits_mode' => 'url', 'barebits_server_url' => 'https://pay.example.com'];
$GLOBALS['http_requests'] = [];
barebits_api_request('GET', '/api/v1/stores/abc/webhooks', null, 'key');
assert_eq(
    'https://pay.example.com/api/v1/stores/abc/webhooks',
    $GLOBALS['http_requests'][0]['url'],
    'remote servers keep the canonical URL'
);

// Reconnect-by-URL: the install record survives a "Start over", so URL mode
// pointed back at the install still takes the direct transport.
$GLOBALS['wp_options'] = [
    'barebits_mode' => 'url',
    'barebits_server_url' => $install,
    'barebits_install_dir' => '/var/www/wp/barebits',
    'barebits_install_url' => $install,
];
$GLOBALS['http_requests'] = [];
barebits_api_request('GET', '/api/v1/stores/abc/webhooks', null, 'key');
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=%2Fapi%2Fv1%2Fstores%2Fabc%2Fwebhooks',
    $GLOBALS['http_requests'][0]['url'],
    'a reconnected install is still called through api.php'
);

// --- The probe follows the same selection ------------------------------------
$GLOBALS['http_requests'] = [];
$GLOBALS['http_error'] = 'cURL error 7: connection refused';
barebits_probe_server($install . '/');
assert_eq(
    'http://wp.test/barebits/api.php?cashupay_path=%2Fapi%2Fv1%2Fserver%2Finfo',
    $GLOBALS['http_requests'][0]['url'],
    'probing the recorded install goes through api.php (trailing slash normalized)'
);
$GLOBALS['http_requests'] = [];
barebits_probe_server('https://pay.example.com');
assert_eq(
    'https://pay.example.com/api/v1/server/info',
    $GLOBALS['http_requests'][0]['url'],
    'probing a remote server keeps the canonical URL'
);
$GLOBALS['http_error'] = null;

// --- Same-host timeouts get an actionable message -----------------------------
$GLOBALS['wp_options'] = [
    'barebits_mode' => 'install',
    'barebits_server_url' => $install,
    'barebits_install_dir' => '/var/www/wp/barebits',
    'barebits_install_url' => $install,
];
$GLOBALS['http_error'] = 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received';
$result = barebits_api_request('GET', '/api/v1/stores/abc/webhooks', null, 'key');
assert_true(strpos((string) $result['error'], 'loopback') !== false,
    'a timeout against our own origin explains the loopback restriction');

// A remote server's timeout stays what the transport said — the hint would
// mislead there.
$GLOBALS['wp_options'] = ['barebits_mode' => 'url', 'barebits_server_url' => 'https://pay.example.com'];
$result = barebits_api_request('GET', '/api/v1/stores/abc/webhooks', null, 'key');
assert_true(strpos((string) $result['error'], 'loopback') === false,
    'remote timeouts are not blamed on loopback restrictions');
$GLOBALS['http_error'] = null;

echo "test_wp_api_transport_url: ok\n";
