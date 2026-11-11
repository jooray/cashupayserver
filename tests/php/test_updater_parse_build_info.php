<?php
/**
 * Updater::parseBuildInfo — the line-oriented key=value parser used both
 * for the local BUILD_INFO file and the BUILD_INFO asset fetched from a
 * channel release. parseBuildInfo is private; tests reach it via
 * Reflection because it has no domain-side coupling worth exposing.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/updater.php';

$ref = new ReflectionMethod(Updater::class, 'parseBuildInfo');
$ref->setAccessible(true);
$parse = static fn(string $raw): array => $ref->invoke(null, $raw);

// 1. Happy path: five canonical keys.
$raw = <<<EOT
COMMIT_SHA=abc123
CHANNEL=main
BUILT_AT=2026-06-02T07:00:00Z
VERSION=0.1-alpha
HTACCESS_SHA256=deadbeef
EOT;
$parsed = $parse($raw);
assert_eq('abc123', $parsed['COMMIT_SHA']);
assert_eq('main', $parsed['CHANNEL']);
assert_eq('2026-06-02T07:00:00Z', $parsed['BUILT_AT']);
assert_eq('0.1-alpha', $parsed['VERSION']);
assert_eq('deadbeef', $parsed['HTACCESS_SHA256']);

// 2. Blank lines + comments are skipped.
$parsed = $parse("# header comment\nCOMMIT_SHA=xyz\n\n# trailing\n");
assert_eq(1, count($parsed));
assert_eq('xyz', $parsed['COMMIT_SHA']);

// 3. CRLF line endings (zip extraction on Windows-ish hosts).
$parsed = $parse("COMMIT_SHA=abc\r\nCHANNEL=main\r\n");
assert_eq('abc', $parsed['COMMIT_SHA']);
assert_eq('main', $parsed['CHANNEL']);

// 4. Whitespace around key/value is trimmed.
$parsed = $parse("  COMMIT_SHA =   abc123   \n");
assert_eq('abc123', $parsed['COMMIT_SHA']);

// 5. Value with embedded '=' keeps everything after the first '='.
$parsed = $parse("VERSION=1.0=rc1\n");
assert_eq('1.0=rc1', $parsed['VERSION']);

// 6. Lines without '=' are silently dropped (don't crash on garbage).
$parsed = $parse("garbage_line\nCOMMIT_SHA=ok\nmore garbage\n");
assert_eq(1, count($parsed));
assert_eq('ok', $parsed['COMMIT_SHA']);

// 7. Empty input yields empty array (used as "no BUILD_INFO" sentinel).
assert_eq([], $parse(''));

// 8. Duplicate key: last one wins (deterministic; matches typical key=val
//    handling and lets a regenerated BUILD_INFO override a stale value).
$parsed = $parse("COMMIT_SHA=old\nCOMMIT_SHA=new\n");
assert_eq('new', $parsed['COMMIT_SHA']);

echo "ok\n";
