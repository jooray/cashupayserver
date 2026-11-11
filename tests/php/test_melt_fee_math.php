<?php
/**
 * DO-4: withdrawal fee = inputs − change − invoice amount, never negative, and
 * an amountless BOLT-11 is refused with a clear message BEFORE any network
 * call (the store's mint is a dead port, so reaching the network would throw a
 * connection error instead).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lightning_address.php';

// Old formula was feeReserve − change: with a 3-sat reserve and 13 sats of
// change (selection over-covered by 10) it reported −10.
assert_eq(1, LightningAddress::meltFee(64, 13, 50), 'inputs 64, change 13, amount 50 → fee 1');
assert_eq(3, LightningAddress::meltFee(53, 0, 50), 'inputs 53, no change, amount 50 → fee 3');
assert_eq(0, LightningAddress::meltFee(50, 0, 50), 'exact inputs → fee 0');
assert_eq(0, LightningAddress::meltFee(50, 5, 50), 'never negative');

// Amountless invoice: refused before contacting the (dead) mint.
make_store('s1', 'http://127.0.0.1:1');
$amountless = 'lnbc1pvjluezsp5zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zygspp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdpl2pkx2ctnv5sxxmmwwd5kgetjypeh2ursdae8g6twvus8g6rfwvs8qun0dfjkxaq9qrsgq357wnc5r2ueh7ck6q93dj32dlqnls087fxdwk8qakdyafkq3yap9us6v52vjjsrvywa6rt52cm9r9zqt8r2t7mlcwspyetp5h2tztugp9lfyql';
assert_eq(0, LightningAddress::parseBolt11Amount($amountless), 'fixture invoice parses as amountless');
try {
    LightningAddress::meltToBolt11('s1', $amountless);
    fail('amountless invoice must be refused');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'no amount') !== false,
        'refusal names the reason, not a network error: ' . $e->getMessage());
}

// A normal invoice on the same dead-mint store fails at the network, proving
// the amountless check is the only thing standing before the mint.
try {
    LightningAddress::meltToBolt11('s1', 'lnbc10u1p0xxxxxpp5' . str_repeat('q', 50));
    fail('dead mint must fail');
} catch (Exception $e) {
    assert_true(stripos($e->getMessage(), 'no amount') === false, 'amount-bearing invoice reaches the mint');
}

fwrite(STDERR, "test_melt_fee_math: all assertions passed\n");
