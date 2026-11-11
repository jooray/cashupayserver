<?php
/**
 * Admin invoices view data: Lightning rails surface the bolt11 as the "TxID"
 * (rendered copy-only, no block-explorer link) and the LN address / LNURL it
 * was sent to as the destination. formatForApi flags both so the JS knows not
 * to wrap them in a mempool.space link. On-chain / swap rails are unaffected.
 *
 * Also covers the new ln_destination surfacing for a fee-redirect lightning
 * invoice: the fee payee's LNURL shows up as the destination and the
 * feeRedirect block reports settledToFee.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$store = 'store_lndest';
make_store($store, 'https://m.example.com');

function fmt(string $id): array {
    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]);
    assert_not_null($row, "invoice row $id exists");
    return Invoice::formatForApi($row);
}

// ---------------------------------------------------------------------------
// 1. lnaddress rail with a destination -> bolt11 as txid + LN address dest.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_lnaddr', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'bolt11' => 'lnbc50u1pexamplebolt11invoicestring',
    'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress',
    'ln_destination' => 'merchant@example.test',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$a = fmt('inv_lnaddr');
assert_eq('lnbc50u1pexamplebolt11invoicestring', $a['txid'] ?? null, 'lnaddress txid = bolt11');
assert_eq(true, $a['txidIsLightning'] ?? null, 'lnaddress txid flagged lightning');
assert_eq('merchant@example.test', $a['destination'] ?? null, 'lnaddress destination = LN address');
assert_eq(true, $a['destinationIsLightning'] ?? null, 'lnaddress destination flagged lightning');

// ---------------------------------------------------------------------------
// 2. mint rail with a bolt11 but no LN destination -> txid only, no dest.
//    (paid to the mint; there is no lnurl destination to show)
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_mint', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '800', 'currency' => 'sat', 'amount_sats' => 800,
    'bolt11' => 'lnbc8u1pexamplemintbolt11',
    'payment_rail' => 'mint', 'settled_rail' => 'mint',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$m = fmt('inv_mint');
assert_eq('lnbc8u1pexamplemintbolt11', $m['txid'] ?? null, 'mint txid = bolt11');
assert_eq(true, $m['txidIsLightning'] ?? null, 'mint txid flagged lightning');
assert_true(!isset($m['destination']), 'mint rail exposes no lnurl destination');
assert_true(!isset($m['destinationIsLightning']), 'mint rail has no lightning dest flag');

// ---------------------------------------------------------------------------
// 3. on-chain rail unaffected: real address destination, no lightning flags.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_oc', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '25000', 'currency' => 'sat', 'amount_sats' => 25000,
    'payment_rail' => 'onchain', 'settled_rail' => 'onchain',
    'onchain_address' => 'bc1qexampleonchainaddr00000000000000000',
    'onchain_amount_sat' => 25000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$o = fmt('inv_oc');
assert_eq('bc1qexampleonchainaddr00000000000000000', $o['destination'] ?? null, 'onchain destination = address');
assert_true(!isset($o['txidIsLightning']), 'onchain txid not flagged lightning');
assert_true(!isset($o['destinationIsLightning']), 'onchain destination not flagged lightning');

// ---------------------------------------------------------------------------
// 4. Fee-redirect lightning invoice settled to the fee: the fee LNURL is the
//    destination and feeRedirect.settledToFee is true.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_feeln', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '2000', 'currency' => 'sat', 'amount_sats' => 2000,
    'bolt11' => 'lnbc20u1pexamplefeebolt11',
    'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress',
    'ln_destination' => 'dev-fee@example.test',
    'fee_redirect_note' => FEE_NOTE_DEV,
    'fee_redirect_destination' => 'dev-fee@example.test',
    'fee_redirect_rails' => 'lightning',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$f = fmt('inv_feeln');
assert_eq('dev-fee@example.test', $f['destination'] ?? null, 'fee-redirect LN destination surfaced');
assert_eq(true, $f['destinationIsLightning'] ?? null, 'fee-redirect destination flagged lightning');
assert_not_null($f['feeRedirect'] ?? null, 'feeRedirect block present');
assert_eq(true, $f['feeRedirect']['settledToFee'], 'settled to fee');
assert_eq(false, $f['feeRedirect']['mixed'], 'pure lightning fee invoice is not mixed');

echo "OK\n";
