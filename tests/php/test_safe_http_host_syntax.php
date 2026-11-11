<?php
/**
 * SafeHttp::validateUrl() only resolves and pins plain hosts (LDH labels or
 * IP literals). A host with a trailing dot, percent escape, backslash or
 * non-ASCII would be normalised by libcurl to a different string than the
 * one our CURLOPT_RESOLVE pin is keyed on — the pin would silently not apply
 * and curl would do its own unchecked lookup. Such hosts are refused BEFORE
 * resolution (asserted via the resolver seam: it must never be consulted).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/safe_http.php';

// isPlainHostSyntax matrix
foreach (['example.com', 'mint.example.co.uk', 'cashu_mint', 'a-b.c-d', '8.8.8.8', '[2606:4700::1]', 'x1.y2'] as $h) {
    assert_true(SafeHttp::isPlainHostSyntax($h), "plain host accepted: '$h'");
}
foreach ([
    '', 'example.com.', '.example.com', 'exa mple.com', 'l%6fcalhost', 'münchen.de', 'a\\b.com',
    '-bad.com', 'bad-.com', 'a..b', str_repeat('a', 64) . '.com', str_repeat('a.', 130) . 'com',
    '127.1', '0x7f.1', '2130706433', 'host.123', '[::1', '[not-v6]', 'exa$mple.com',
] as $h) {
    assert_false(SafeHttp::isPlainHostSyntax($h), "odd host refused: '$h'");
}

// The resolver seam: records every lookup and answers a public address.
$lookups = [];
SafeHttp::$resolverForTesting = function (string $host) use (&$lookups): array {
    $lookups[] = $host;
    return ['93.184.216.34'];
};

$v = SafeHttp::validateUrl('https://mint.example/v1/info');
assert_eq(['mint.example:443:93.184.216.34'], $v['resolve'], 'plain host resolved through the seam and pinned');
assert_eq(['mint.example'], $lookups, 'exactly one lookup');

foreach (['https://mint.example./v1', 'https://l%6fcalhost/x', 'https://münchen.de/', 'https://ex%2eample.com/'] as $u) {
    $lookups = [];
    $threw = null;
    try {
        SafeHttp::validateUrl($u);
    } catch (\RuntimeException $e) {
        $threw = $e->getMessage();
    }
    assert_not_null($threw, "odd host refused: $u");
    assert_eq([], $lookups, "odd host never reaches the resolver: $u");
}

// Multi-A rebinding: any private answer refuses the whole host.
SafeHttp::$resolverForTesting = fn(string $h) => ['93.184.216.34', '10.0.0.5'];
$threw = false;
try {
    SafeHttp::validateUrl('https://rebind.example/');
} catch (\RuntimeException $e) {
    $threw = true;
}
assert_true($threw, 'a host with one private A record is refused');

// Resolver returning nothing -> DNS failure, not a pass.
SafeHttp::$resolverForTesting = fn(string $h) => [];
$threw = false;
try {
    SafeHttp::validateUrl('https://nowhere.example/');
} catch (\RuntimeException $e) {
    $threw = true;
}
assert_true($threw, 'unresolvable host refused');

SafeHttp::$resolverForTesting = null;
echo "ok\n";
