<?php
/**
 * Every interface surface that mentions CLINK must carry the receipt-loss
 * warning: CLINK payment receipts are temporary when issued, so a brief
 * network outage can drop one and leave a paid order showing "unpaid".
 * Each warning must recommend Electrum + the CLINK plugin and link
 * electrum.org and github.com/BareBits/electrum_clink.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$root = dirname(__DIR__, 2) . '/app';

// file => expected number of warning instances
$surfaces = [
    'setup.php' => 2, // Method 1 help box + Method 3 noffer (CLINK) section
    'admin.php' => 2, // CLINK noffers group + legacy per-row noffer hint (JS)
];

$marker = 'STRONGLY recommended as opposed to other wallets with CLINK support';

foreach ($surfaces as $file => $expected) {
    $src = file_get_contents($root . '/' . $file);
    assert_true($src !== false, "$file readable");

    // Join JS string concatenations ('… ' + '…') and collapse whitespace so
    // the warning reads as one string regardless of source formatting.
    $flat = preg_replace('/\'\s*\+\s*\'/', '', $src);
    $flat = preg_replace('/\s+/', ' ', $flat);

    $count = substr_count($flat, $marker);
    assert_eq($expected, $count, "$file carries $expected receipt-loss warning(s)");

    // Each instance must sit beside the Electrum + CLINK-plugin links and
    // spell out the consequence (receipts lost in outages; funds are safe).
    $offset = 0;
    for ($i = 1; $i <= $count; $i++) {
        $pos = strpos($flat, $marker, $offset);
        $window = substr($flat, max(0, $pos - 600), 600 + strlen($marker) + 600);
        assert_true(strpos($window, 'https://electrum.org') !== false,
            "$file warning #$i links electrum.org");
        assert_true(strpos($window, 'https://github.com/BareBits/electrum_clink') !== false,
            "$file warning #$i links the CLINK plugin repo");
        assert_true(strpos($window, 'can be lost during small network outages') !== false,
            "$file warning #$i explains receipt loss");
        assert_true(strpos($window, 'no risk to funds') !== false,
            "$file warning #$i notes funds are safe");
        $offset = $pos + strlen($marker);
    }
}

echo "test_clink_receipt_warning: ok\n";
