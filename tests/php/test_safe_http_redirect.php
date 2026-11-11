<?php
/**
 * SafeHttp follows redirects MANUALLY so every hop is re-validated against the
 * private-IP policy. curl's own CURLOPT_FOLLOWLOCATION would re-resolve a
 * cross-host Location with no isPrivateIp check (the RESOLVE pin only covers the
 * first host) — a classic SSRF bypass: trusted-host 302 -> 169.254.169.254.
 *
 * We can't run a real redirect chain offline, so we test the two building
 * blocks the loop relies on:
 *   - resolveRedirectTarget(): correct RFC-3986-style reference resolution,
 *   - validateUrl(): rejects a private/reserved destination (which is what the
 *     loop calls on each resolved hop).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/safe_http.php';

// ---- resolveRedirectTarget --------------------------------------------------
$base = 'https://mint.example.com/info/v1?x=1';

assert_eq('https://evil.test/meta',
    SafeHttp::resolveRedirectTarget($base, 'https://evil.test/meta'),
    'absolute URL used as-is');

assert_eq('https://other.test/p',
    SafeHttp::resolveRedirectTarget($base, '//other.test/p'),
    'protocol-relative inherits scheme');

assert_eq('https://mint.example.com/abs',
    SafeHttp::resolveRedirectTarget($base, '/abs'),
    'absolute-path keeps authority');

assert_eq('https://mint.example.com/info/rel',
    SafeHttp::resolveRedirectTarget($base, 'rel'),
    'relative-path resolves against base directory');

// Authority + port preserved on path-relative refs.
assert_eq('https://h.test:8443/x/y',
    SafeHttp::resolveRedirectTarget('https://h.test:8443/x/old', 'y'),
    'port preserved in authority');

assert_null(SafeHttp::resolveRedirectTarget($base, ''), 'empty Location -> null');

// ---- validateUrl rejects private/reserved hops ------------------------------
$rejected = function (string $url): bool {
    try { SafeHttp::validateUrl($url, false); return false; }
    catch (\Throwable $e) { return true; }
};

assert_true($rejected('http://169.254.169.254/latest/meta-data/'),
    'link-local (cloud metadata) rejected');
assert_true($rejected('http://127.0.0.1/'), 'loopback rejected');
assert_true($rejected('http://10.0.0.5/'), 'RFC1918 10/8 rejected');
assert_true($rejected('http://[::1]/'), 'IPv6 loopback rejected');
assert_true($rejected('ftp://example.com/'), 'non-http scheme rejected');

// A public IP literal validates (no DNS needed).
$ok = SafeHttp::validateUrl('https://1.1.1.1/', false);
assert_eq('1.1.1.1', $ok['host'], 'public IP literal accepted');

// allowPrivate opt-in lets a local node through (self-hosters).
$priv = SafeHttp::validateUrl('http://127.0.0.1:3000/', true);
assert_eq('127.0.0.1', $priv['host'], 'allowPrivate permits loopback');

echo "PASS test_safe_http_redirect\n";
exit(0);
