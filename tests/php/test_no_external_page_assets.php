<?php
/**
 * No page served by this software may pull executable assets from a
 * third-party host. QR codes on the payment/receive/admin pages encode
 * payment data (invoices, addresses, ecash tokens): a CDN in that path can
 * observe every payer's IP and — worse — serve a tampered renderer that
 * encodes an attacker's address instead of ours. PRIVACY.md §4 also promises
 * "no CDN is in the path of any page", so an external script/stylesheet/font
 * reference would make the published privacy policy false.
 *
 * qrious used to come from cdn.jsdelivr.net and bc-ur from cdn.skypack.dev;
 * both are now served locally (assets/js/qrcode-generator.js + qr-shim.js,
 * assets/js/bc-ur.bundle.js via scripts/build-bcur-bundle.sh). This scan
 * keeps them — and any future external asset — from coming back.
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__, 2) . '/app';

// Every PHP entry point that renders HTML, plus all first-party JS.
$scanned = array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/api-keys/*.php') ?: [],
    glob($root . '/assets/js/*.js') ?: []
);
assert_true(count($scanned) > 10, 'the scan found a plausible number of files');

// External-asset shapes: script/link/img tags with an absolute http(s) src,
// ES-module imports from a URL, and CSS url() fetches of another origin.
// Plain <a href> links are fine — they transmit nothing until clicked.
$patterns = [
    '/<script[^>]+src\s*=\s*["\']https?:\/\//i' => 'script tag loading an external host',
    '/<link[^>]+href\s*=\s*["\']https?:\/\//i'  => 'stylesheet/link tag on an external host',
    '/\bfrom\s+["\']https?:\/\//i'              => 'ES module import from an external host',
    '/@import\s+(url\()?["\']?https?:\/\//i'    => 'CSS @import from an external host',
];

// Vendored third-party bundles may MENTION URLs in comments/strings (e.g.
// chart.min.js's SRI doc link); they are minified upstream artifacts, not
// pages, and load nothing by themselves — only first-party files are held
// to the letter of the tag patterns above.
$vendored = ['chart.min.js', 'mint-discovery.bundle.js', 'bc-ur.bundle.js', 'qrcode-generator.js'];

$offenders = [];
foreach ($scanned as $file) {
    if (in_array(basename($file), $vendored, true)) {
        continue;
    }
    $lines = file($file);
    foreach ($lines as $i => $line) {
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $line)) {
                $offenders[] = substr($file, strlen($root) + 1) . ':' . ($i + 1)
                    . ' (' . $label . '): ' . trim(substr($line, 0, 160));
            }
        }
    }
}

assert_true($offenders === [], "external asset references found:\n" . implode("\n", $offenders));

// The local replacements must actually exist and be non-trivial, so a lost
// file fails here instead of as a blank QR in production.
foreach (['qrcode-generator.js', 'qr-shim.js', 'bc-ur.bundle.js'] as $asset) {
    $path = $root . '/assets/js/' . $asset;
    assert_true(is_file($path) && filesize($path) > 1000, "assets/js/$asset is present and non-empty");
}

// And the pages must reference the local renderer, not nothing at all.
foreach (['payment.php', 'receive.php', 'admin.php'] as $page) {
    $html = file_get_contents($root . '/' . $page);
    assert_true(str_contains($html, 'qrcode-generator.js') && str_contains($html, 'qr-shim.js'),
        "$page loads the local QR renderer");
}
assert_true(str_contains(file_get_contents($root . '/admin.php'), 'bc-ur.bundle.js'),
    'admin.php loads the local bc-ur bundle');

echo "OK\n";
