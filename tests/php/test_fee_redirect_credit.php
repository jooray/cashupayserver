<?php
/**
 * Fee-redirect accounting: when a redirect invoice settles, a melts credit
 * (via='redirect', tagged with the fee note) is recorded so DevFee::computeOwed
 * drops the owed amount and the cron won't melt the same fee again. The credit
 * is idempotent — re-running settlement (e.g. a dual-rail invoice observed by
 * both pollers) never double-credits.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_credit';
make_store($store, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);

// Prior revenue so a dev fee is owed well above our redirect invoice amount.
Database::insert('invoices', [
    'id' => 'inv_prior', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '500000', 'currency' => 'sat', 'amount_sats' => 500000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);

$before = DevFee::computeOwed($store);
assert_eq(5000, $before['dev_owed'], 'dev owed 1% of 500k = 5000');
assert_eq(0, $before['dev_paid'], 'nothing paid yet');

// A redirect invoice pointed at the dev fee, still New (not yet counted as
// revenue). amount_sats is the plain sat amount the customer pays.
$rid = 'inv_redirect';
Database::insert('invoices', [
    'id' => $rid, 'store_id' => $store, 'status' => 'New',
    'amount' => '3000', 'currency' => 'sat', 'amount_sats' => 3000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'bc1qexamplefeeaddress0000000000000000000',
    'onchain_amount_sat' => 3000,
    'fee_redirect_note' => FEE_NOTE_DEV,
    'fee_redirect_destination' => 'bc1qexamplefeeaddress0000000000000000000',
    'fee_redirect_rails' => 'onchain',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);

// Settle it (as the on-chain poller would, via updateStatus).
Invoice::updateStatus($rid, 'Settled', null, 'onchain');

// A single redirect credit row exists for this invoice.
$melts = Database::fetchAll("SELECT * FROM melts WHERE invoice_id = ?", [$rid]);
assert_eq(1, count($melts), 'exactly one redirect credit recorded');
assert_eq('redirect', $melts[0]['via'], 'credit tagged via=redirect');
assert_eq(FEE_NOTE_DEV, $melts[0]['note'], 'credit carries the dev fee note');
assert_eq(3000, (int)$melts[0]['amount_sats'], 'credit amount = invoice sats');
assert_eq(0, (int)$melts[0]['network_fee_sats'], 'redirect has no network fee');

// computeOwed reflects it: the redirect invoice now counts as revenue (503000)
// and dev_paid jumped by 3000.
//   dev base = 503000 - 0 networkCost - 0 upstreamPaid = 503000
//   dev gross = floor(503000 * 0.01) = 5030
//   dev_owed  = 5030 - 3000 = 2030
$after = DevFee::computeOwed($store);
assert_eq(3000, $after['dev_paid'], 'dev_paid credited by the redirect');
assert_eq(503000, $after['revenue'], 'redirect invoice now counts as revenue');
assert_eq(2030, $after['dev_owed'], 'dev owed dropped by the redirected amount (less its ~1% residual)');
assert_true($after['dev_owed'] < $before['dev_owed'], 'owed strictly decreased');

// Idempotency: a second settlement pass (dual-rail race / re-poll) must not
// add a second credit.
Invoice::updateStatus($rid, 'Settled', null, 'onchain');
$count = (int) Database::fetchOne(
    "SELECT COUNT(*) AS c FROM melts WHERE invoice_id = ?", [$rid]
)['c'];
assert_eq(1, $count, 'settlement is idempotent: still one credit');

// A normal (non-redirect) invoice settling records NO redirect credit.
Database::insert('invoices', [
    'id' => 'inv_normal', 'store_id' => $store, 'status' => 'New',
    'amount' => '4000', 'currency' => 'sat', 'amount_sats' => 4000,
    'payment_rail' => 'mint',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
Invoice::updateStatus('inv_normal', 'Settled', null, 'mint');
$normalCredits = (int) Database::fetchOne(
    "SELECT COUNT(*) AS c FROM melts WHERE invoice_id = ?", ['inv_normal']
)['c'];
assert_eq(0, $normalCredits, 'normal invoice creates no redirect credit');

// ---- Per-rail attribution helpers -----------------------------------------
$mixedInv = [
    'id' => 'x', 'store_id' => $store, 'status' => 'Settled',
    'fee_redirect_note' => FEE_NOTE_DEV, 'fee_redirect_rails' => 'lightning',
];
assert_true(Invoice::railIsFeeRouted($mixedInv, 'lightning'), 'lightning is fee-routed');
assert_true(!Invoice::railIsFeeRouted($mixedInv, 'onchain'), 'onchain stays with merchant');
// settled_rail maps onto the logical rail: onchain payment on this mixed
// invoice is a MERCHANT payment, not a fee payment.
assert_true(!Invoice::settledRailIsFeeRouted($mixedInv + ['settled_rail' => 'onchain']),
    'merchant on-chain settlement is not a fee payment');
assert_true(Invoice::settledRailIsFeeRouted($mixedInv + ['settled_rail' => 'lnaddress']),
    'fee lightning settlement is a fee payment');
$bothInv = ['fee_redirect_note' => FEE_NOTE_DEV, 'fee_redirect_rails' => 'lightning,onchain'];
assert_true(Invoice::settledRailIsFeeRouted($bothInv + ['settled_rail' => 'onchain']),
    'both-rail fee invoice: on-chain settlement is a fee payment');

// ---- Mixed invoice: merchant rail settles -> NO fee credit ----------------
// Lightning is routed to the dev fee, on-chain stays with the merchant. The
// customer pays on-chain, so the merchant was paid and no fee credit is made.
$mid = 'inv_mixed_merchant';
Database::insert('invoices', [
    'id' => $mid, 'store_id' => $store, 'status' => 'New',
    'amount' => '3000', 'currency' => 'sat', 'amount_sats' => 3000,
    'payment_rail' => 'lnaddress',
    'bolt11' => 'lnbc-fee-lightning-rail',
    'lnurl_verify_url' => 'https://fee.example/verify/abc',
    'onchain_address' => 'bc1qmerchantaddr00000000000000000000000',
    'onchain_amount_sat' => 3000,
    'fee_redirect_note' => FEE_NOTE_DEV,
    'fee_redirect_destination' => 'fees@dev / bc1qmerchant…',
    'fee_redirect_rails' => 'lightning',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
Invoice::updateStatus($mid, 'Settled', null, 'onchain');
$mixedCredits = (int) Database::fetchOne(
    "SELECT COUNT(*) AS c FROM melts WHERE invoice_id = ?", [$mid]
)['c'];
assert_eq(0, $mixedCredits, 'merchant-rail settlement on a mixed invoice: no fee credit');

// ---- Per-rail credit amount: on-chain credits onchain_amount_sat ----------
// amount_sats and onchain_amount_sat deliberately differ; the on-chain rail
// must credit what actually moved on-chain.
$pid = 'inv_perrail_amount';
Database::insert('invoices', [
    'id' => $pid, 'store_id' => $store, 'status' => 'New',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'bc1qfeeaddr2220000000000000000000000000',
    'onchain_amount_sat' => 3000,
    'fee_redirect_note' => FEE_NOTE_DEV,
    'fee_redirect_destination' => 'bc1qfeeaddr2…',
    'fee_redirect_rails' => 'onchain',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
Invoice::updateStatus($pid, 'Settled', null, 'onchain');
$perRail = Database::fetchOne("SELECT * FROM melts WHERE invoice_id = ?", [$pid]);
assert_eq(3000, (int)$perRail['amount_sats'], 'on-chain rail credits onchain_amount_sat, not amount_sats');

echo "test_fee_redirect_credit: ok\n";
