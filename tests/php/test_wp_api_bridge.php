<?php
/**
 * WordPress API bridge (wordpress/api-bridge.php) — request matching.
 *
 * On rewrite-hostile hosts the alongside install's /api/v1 URLs fall through
 * the web server into WordPress, and the bridge replays them against the
 * install's api.php. barebits_api_bridge_path is the pure gatekeeper: it must
 * claim exactly the install's API namespace — both the /api/v1 form and the
 * BTCPay-compatible /v1 alias, at any install depth — and nothing else, since
 * a false match would swallow a real WordPress page and a missed match leaves
 * the API dead on the very hosts the bridge exists for. The live proxying is
 * driven end to end by the hostile-host browser journey test.
 *
 * The wp.org review gate (2026-09, second round) also lives here: nothing
 * from the request may be replayed raw, so the pure query re-encoder and
 * body validator are pinned pair by pair — including every spelling of a
 * smuggled cashupay_path parameter and the JSON/size refusals — and nothing
 * from the response is echoed raw either: the response validator and the
 * wp_json_encode relay re-encode are pinned below (escape late). The live
 * HTTP half of the same gate is tests/wordpress/test_wp_api_bridge_live.py.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', '/tmp/');
function add_action($hook, $callable) {}

require __DIR__ . '/wp_compat_stubs.php';
require dirname(__DIR__, 2) . '/wordpress/api-bridge.php';

// --- No install, nothing claimed --------------------------------------------
assert_eq(null, barebits_api_bridge_path('/barebits/api/v1/server/info', ''),
    'no install URL claims nothing');

$install = 'http://wp.test/barebits';

// --- The install's API namespace is claimed, verbatim ------------------------
assert_eq('/api/v1/server/info',
    barebits_api_bridge_path('/barebits/api/v1/server/info', $install),
    'the Greenfield path under the install is claimed');
assert_eq('/api/v1/stores/abc/invoices',
    barebits_api_bridge_path('/barebits/api/v1/stores/abc/invoices', $install),
    'deep API paths are claimed');
assert_eq('/v1/server/info',
    barebits_api_bridge_path('/barebits/v1/server/info', $install),
    'the BTCPay-compatible /v1 alias is claimed, unrewritten (api.php normalizes it)');

// A trailing slash on the recorded install URL never changes the match.
assert_eq('/api/v1/server/info',
    barebits_api_bridge_path('/barebits/api/v1/server/info', $install . '/'),
    'a trailing slash on the install URL is normalized');

// Installs deeper than one segment (WordPress in a subdirectory, or the
// wp-content fallback target) match on their full path.
assert_eq('/api/v1/server/info',
    barebits_api_bridge_path('/wp-content/barebits/api/v1/server/info', 'http://wp.test/wp-content/barebits'),
    'a nested install path is honored');
assert_eq(null,
    barebits_api_bridge_path('/barebits/api/v1/server/info', 'http://wp.test/wp-content/barebits'),
    'and only its full path matches');

// --- Everything else stays WordPress's ---------------------------------------
assert_eq(null, barebits_api_bridge_path('/barebits/payment.php', $install),
    'non-API install URLs are not claimed');
assert_eq(null, barebits_api_bridge_path('/barebits/setup.php', $install),
    'the wizard is not claimed');
assert_eq(null, barebits_api_bridge_path('/barebits/api/v2/thing', $install),
    'unknown API versions are not claimed');
assert_eq(null, barebits_api_bridge_path('/api/v1/server/info', $install),
    'the site root API path (not under the install) is not claimed');
assert_eq(null, barebits_api_bridge_path('/barebits-blog/api/v1/x', $install),
    'a prefix-similar sibling path is not claimed');
assert_eq(null, barebits_api_bridge_path('/barebits', $install),
    'the bare install URL is not claimed');
assert_eq(null, barebits_api_bridge_path('/barebits/api/v1', $install),
    'the namespace root without a trailing segment is not claimed');
// An install URL with no path at all can never happen (the installer always
// appends a directory segment) — but if it ever did, claiming every /api/v1
// URL on the site would be the wrong failure mode.
assert_eq(null, barebits_api_bridge_path('/api/v1/server/info', 'http://wp.test'),
    'a pathless install URL claims nothing');

// --- Query re-encoding (wp.org review: sanitize/validate QUERY_STRING) -------
// Ordinary parameters ride through byte-for-byte in meaning: order and
// duplicate keys preserved, +-as-space and %-escapes normalized to one
// canonical percent-encoded form.
assert_eq('', barebits_api_bridge_query(''), 'empty query stays empty');
assert_eq('skip=0&take=5', barebits_api_bridge_query('skip=0&take=5'),
    'plain parameters are unchanged');
assert_eq('dup=1&dup=2', barebits_api_bridge_query('dup=1&dup=2'),
    'duplicate keys survive in order (parse_str-style rebuilds would collapse them)');
assert_eq('flag', barebits_api_bridge_query('flag'),
    'a valueless flag parameter keeps its shape');
assert_eq('q=a%20b', barebits_api_bridge_query('q=a+b'),
    '+ means space and is re-encoded as %20');
assert_eq('q=a%2Bb', barebits_api_bridge_query('q=a%2Bb'),
    'an escaped literal plus stays a literal plus');
assert_eq('items%5B%5D=1&items%5B%5D=2', barebits_api_bridge_query('items[]=1&items[]=2'),
    'bracketed array keys are percent-encoded, still the same PHP parameter');
assert_eq('a=1', barebits_api_bridge_query('&&a=1&'),
    'empty fragments are dropped');
assert_eq('a=1', barebits_api_bridge_query('a=1&=orphan'),
    'a pair with an empty name is dropped');
assert_eq('a=b%20c%22%3C%3E%27', barebits_api_bridge_query('a=b c"<>\''),
    'raw URL-hostile bytes come out fully percent-encoded');
assert_eq('a=%25zz', barebits_api_bridge_query('a=%zz'),
    'a malformed %-escape is neutralized into literal, well-formed bytes');

// The bridge's own transport parameter can never be overridden from outside:
// every spelling PHP would parse as cashupay_path is stripped.
assert_eq('x=1', barebits_api_bridge_query('cashupay_path=/evil&x=1'),
    'a smuggled cashupay_path is dropped');
assert_eq('x=1', barebits_api_bridge_query('x=1&%63ashupay_path=/evil'),
    'percent-encoded spellings of the name are dropped too');
assert_eq('x=1', barebits_api_bridge_query('cashupay.path=/evil&cashupay+path=/evil&x=1'),
    'dot/space spellings PHP folds to underscores are dropped');
assert_eq('x=1', barebits_api_bridge_query('cashupay_path[]=/evil&x=1'),
    'array spellings that would clobber the scalar parameter are dropped');
assert_eq('cashupay_pathx=1', barebits_api_bridge_query('cashupay_pathx=1'),
    'a merely prefix-similar name is NOT dropped');

// --- Body validation (wp.org review: validate php://input) -------------------
// The install's API parses every body as JSON and nothing else, so the
// bridge forwards exactly that: nothing, or valid JSON within the cap.
assert_eq(null, barebits_api_bridge_body_refusal(''), 'an empty body is fine');
assert_eq(null, barebits_api_bridge_body_refusal('{"amount":"1.23","currency":"USD"}'),
    'a JSON object is fine');
assert_eq(null, barebits_api_bridge_body_refusal('null'),
    'any valid JSON document is fine, scalars included');

$refusal = barebits_api_bridge_body_refusal('amount=1&currency=USD');
assert_eq(400, $refusal[0] ?? null, 'a non-JSON body is refused with 400');
assert_eq('bridge-invalid-body', $refusal[1] ?? null, 'non-JSON refusal carries its error code');
$refusal = barebits_api_bridge_body_refusal('   ');
assert_eq(400, $refusal[0] ?? null, 'a whitespace-only body is not JSON either');
$refusal = barebits_api_bridge_body_refusal('{"amount":');
assert_eq(400, $refusal[0] ?? null, 'truncated JSON is refused');

// The size cap: valid JSON exactly at the cap passes; one byte over — even
// perfectly valid JSON — is refused as oversized (checked before parsing).
$atCap = '"' . str_repeat('a', BAREBITS_BRIDGE_MAX_BODY_BYTES - 2) . '"';
assert_eq(null, barebits_api_bridge_body_refusal($atCap), 'a body exactly at the cap passes');
$refusal = barebits_api_bridge_body_refusal('"' . str_repeat('a', BAREBITS_BRIDGE_MAX_BODY_BYTES - 1) . '"');
assert_eq(413, $refusal[0] ?? null, 'one byte over the cap is refused with 413');
assert_eq('bridge-body-too-large', $refusal[1] ?? null, 'oversize refusal carries its error code');

// --- Response validation (wp.org review: escape late — no raw relay) ---------
// The install's api.php answers every bridged endpoint with JSON; the bridge
// refuses anything else (an HTML fatal-error page, say) with 502 instead of
// echoing it, and re-emits a valid body through wp_json_encode.
assert_eq(null, barebits_api_bridge_response_refusal(''),
    'an empty response body (HEAD, 204) is fine');
assert_eq(null, barebits_api_bridge_response_refusal('{"code":"service-unavailable","message":"x"}'),
    'a JSON response body is fine');
assert_eq(null, barebits_api_bridge_response_refusal('null'),
    'any valid JSON document is fine, scalars included');
$refusal = barebits_api_bridge_response_refusal('<html><body>Fatal error</body></html>');
assert_eq(502, $refusal[0] ?? null, 'a non-JSON upstream body is refused with 502');
assert_eq('bridge-invalid-response', $refusal[1] ?? null, 'non-JSON response refusal carries its error code');
$refusal = barebits_api_bridge_response_refusal('{"truncated":');
assert_eq(502, $refusal[0] ?? null, 'truncated upstream JSON is refused too');

// The relay's re-encode — the same expression the bridge echoes
// (wp_json_encode(json_decode($body), $flags)) — is semantically lossless
// for API payloads: {} vs [] survives, slashes and Unicode stay literal,
// a 1.0 stays a float, and key order is preserved.
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
assert_eq('{"b":{},"a":[],"url":"https://x/y?a=1","rate":1.0,"n":null,"s":"café"}',
    wp_json_encode(json_decode('{"b":{},"a":[],"url":"https:\/\/x\/y?a=1","rate":1.0,"n":null,"s":"café"}'), $flags),
    'the relay re-encode round-trips an API payload without changing its meaning');

// --- Authorization header recovery -------------------------------------------
$_SERVER['HTTP_AUTHORIZATION'] = 'token abc';
assert_eq('token abc', barebits_api_bridge_authorization(), 'HTTP_AUTHORIZATION is used');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'token xyz';
assert_eq('token xyz', barebits_api_bridge_authorization(), 'the REDIRECT_ variant is the fallback');
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
assert_eq('', barebits_api_bridge_authorization(), 'no header means empty, never null');

echo "test_wp_api_bridge: ok\n";
