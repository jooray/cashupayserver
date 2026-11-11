<?php
/**
 * Admin invoices view data for Cashu-related rails.
 *
 * formatForApi exposes mintUrl so the admin UI can label mint-quote Lightning
 * receives as "Lightning (cashu)(<mint host>)" and direct ecash-token
 * receives as "Cashu (<mint host>)". effectiveRail corrects the legacy quirk
 * where token receipts settled with settled_rail='mint': the pair
 * (settled='mint', created='cashu') resolves to 'cashu'.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$store = 'store_cashu_label';
make_store($store, 'https://m.example.com');

function fmt(string $id): array {
    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]);
    assert_not_null($row, "invoice row $id exists");
    return Invoice::formatForApi($row);
}

// ---------------------------------------------------------------------------
// 1. Mint-quote Lightning receive: paymentRail 'mint', mintUrl exposed.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_mint', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '800', 'currency' => 'sat', 'amount_sats' => 800,
    'bolt11' => 'lnbc8u1pexamplemintbolt11',
    'payment_rail' => 'mint', 'settled_rail' => 'mint',
    'mint_url' => 'https://m.example.com',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$m = fmt('inv_mint');
assert_eq('mint', $m['paymentRail'], 'mint-quote receive keeps rail mint');
assert_eq('https://m.example.com', $m['mintUrl'], 'mint rail exposes mintUrl');

// ---------------------------------------------------------------------------
// 2. Direct ecash-token receive settled after the fix: rail 'cashu'.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_token', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '250', 'currency' => 'sat', 'amount_sats' => 250,
    'payment_rail' => 'cashu', 'settled_rail' => 'cashu',
    'mint_url' => 'https://othermint.example.org',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$t = fmt('inv_token');
assert_eq('cashu', $t['paymentRail'], 'token receive reports rail cashu');
assert_eq('https://othermint.example.org', $t['mintUrl'], 'token receive exposes token mint');

// ---------------------------------------------------------------------------
// 3. Legacy token receive (settled as 'mint' before the fix): still 'cashu'.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_legacy', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '100', 'currency' => 'sat', 'amount_sats' => 100,
    'payment_rail' => 'cashu', 'settled_rail' => 'mint',
    'mint_url' => 'https://m.example.com',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
assert_eq('cashu', fmt('inv_legacy')['paymentRail'], 'legacy settled-as-mint token row corrected to cashu');

// ---------------------------------------------------------------------------
// 4. effectiveRail unit behaviour, incl. non-cashu rails left untouched.
// ---------------------------------------------------------------------------
assert_eq('cashu', Invoice::effectiveRail(['settled_rail' => 'mint', 'payment_rail' => 'cashu']),
    'legacy fingerprint resolves to cashu');
assert_eq('mint', Invoice::effectiveRail(['settled_rail' => 'mint', 'payment_rail' => 'mint']),
    'genuine mint settlement stays mint');
assert_eq('cashu', Invoice::effectiveRail(['settled_rail' => null, 'payment_rail' => 'cashu']),
    'unsettled token invoice falls back to created rail');
assert_eq('lnaddress', Invoice::effectiveRail(['settled_rail' => 'lnaddress', 'payment_rail' => 'mint']),
    'other settled rails pass through');
assert_eq('mint', Invoice::effectiveRail(['settled_rail' => null, 'payment_rail' => 'mint']),
    'unsettled mint invoice falls back to created rail');

// ---------------------------------------------------------------------------
// 5. Rails that never touch a mint carry no mintUrl.
// ---------------------------------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_lnaddr', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '500', 'currency' => 'sat', 'amount_sats' => 500,
    'bolt11' => 'lnbc5u1pexamplebolt11',
    'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress',
    'ln_destination' => 'merchant@example.test',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
assert_eq(null, fmt('inv_lnaddr')['mintUrl'], 'lnaddress rail has null mintUrl');

echo "test_invoice_cashu_rail_label: ok\n";
