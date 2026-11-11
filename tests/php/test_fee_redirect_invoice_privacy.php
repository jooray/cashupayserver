<?php
/**
 * Fee-redirect privacy: when an invoice's Lightning rail is routed to a fee
 * payee (dev / hosting LNURL), the request for the fee bolt11 carries the
 * amount and nothing else. The fee payee must learn nothing about the sale:
 * no store name, order id, item description, payer note, invoice id, no
 * LUD-12 `comment`, no LUD-18 `payerdata`, no nostr pubkey.
 *
 * Driven through Invoice::create against the shared mock LNURL host, which
 * records every request URI it serves. The store carries a recognisable name
 * and the invoice recognisable metadata so a leak would be caught by a plain
 * substring search over everything the host saw.
 *
 * Scenarios:
 *   1. Dev fee claims the lightning rail (owed >= invoice): the callback hit
 *      has exactly one query parameter (`amount`) and the well-known lookup is
 *      the bare address path.
 *   2. Hosting fee claims the rail (its LNURL is per-store, so it resolves to
 *      a different user on the mock host): same contract.
 *   3. Control: the merchant's own Lightning-address rail sends amount only
 *      too (same helper), so a future memo added for merchants is visible
 *      here as a deliberate contract change, not an accident.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_lnurl_host.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$host = start_mock_lnurl_host();
putenv('CASHU_LNURL_URL_TEMPLATE=' . $host['url_template']);
Config::set('allow_private_endpoints', true);
Config::set('fee_tracking_start_at', 0);

const SECRET_STORE_NAME = 'Acme Secret Widgets Ltd';
const SECRET_ORDER_ID   = 'wc-order-98765';
const SECRET_ITEM_DESC  = 'Blue Widget x3 (gift wrap)';
const SECRET_NOTE       = 'leave at the back door';

/** Insert a settled invoice so DevFee::computeOwed sees it as revenue. */
function paid_invoice(string $storeId, int $sats): void {
    Database::insert('invoices', [
        'id' => 'rev_' . bin2hex(random_bytes(4)), 'store_id' => $storeId, 'status' => 'Settled',
        'amount' => (string)$sats, 'currency' => 'sat', 'amount_sats' => $sats,
        'created_at' => time() - 60, 'expiration_time' => time() + 3600, 'paid_at' => time() - 60,
        'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress',
    ]);
}

/** Every string that must never reach an LNURL host. */
function secrets(array $invoice): array {
    return [
        'store name'   => SECRET_STORE_NAME,
        'order id'     => SECRET_ORDER_ID,
        'item desc'    => SECRET_ITEM_DESC,
        'payer note'   => SECRET_NOTE,
        'invoice id'   => (string)$invoice['id'],
        'lud12'        => 'comment=',
        'lud18'        => 'payerdata',
        'nostr'        => 'nostr=',
        'description'  => 'description=',
        'memo'         => 'memo=',
    ];
}

/**
 * Assert the requests the host saw since $from are exactly one well-known
 * lookup for $expectUser and one callback whose query is `amount=<msats>`
 * and nothing else, with none of the secrets anywhere.
 */
function assert_clean_requests(array $requests, string $expectUser, int $expectSats, array $invoice, string $label): void {
    assert_eq(2, count($requests), "$label: one well-known lookup + one callback, got " . json_encode($requests));
    [$meta, $cb] = $requests;
    assert_eq("/.well-known/lnurlp/{$expectUser}", $meta, "$label: bare well-known path, no query");
    $parts = parse_url($cb);
    assert_eq('/cb', $parts['path'] ?? null, "$label: callback path");
    parse_str((string)($parts['query'] ?? ''), $q);
    assert_eq(['amount'], array_keys($q), "$label: callback query carries only `amount` (got " . ($parts['query'] ?? '') . ')');
    assert_eq((string)($expectSats * 1000), (string)$q['amount'], "$label: amount in msats");
    $all = implode("\n", $requests);
    foreach (secrets($invoice) as $what => $needle) {
        assert_false(stripos($all, $needle) !== false, "$label: $what must not reach the LNURL host (needle: $needle)");
        assert_false(stripos($all, rawurlencode($needle)) !== false, "$label: url-encoded $what must not reach the LNURL host");
    }
}

function invoice_options(): array {
    return [
        'amount' => 5000, 'currency' => 'sat',
        'metadata' => ['orderId' => SECRET_ORDER_ID, 'itemDesc' => SECRET_ITEM_DESC, 'buyerNote' => SECRET_NOTE],
    ];
}

function new_requests(array $before): array {
    global $host;
    return array_slice(mock_lnurl_requests($host['dir']), count($before));
}

$mintStub = 'http://127.0.0.1:1'; // non-routable: the LNURL chain must win

// ---------- 1. Dev fee claims the lightning rail ----------
$s1 = 'store_privacy_dev';
make_store($s1, $mintStub);
Database::update('stores', ['name' => SECRET_STORE_NAME], 'id = ?', [$s1]);
StoreLnAddresses::replaceForStore($s1, ['merchant@example.test']);
paid_invoice($s1, 1_000_000);
$owed = DevFee::computeOwed($s1);
assert_true((int)$owed['dev_owed'] >= 5000, "dev fee owed ({$owed['dev_owed']}) covers the 5k invoice");
[$devUser] = explode('@', (string)CASHUPAY_DEV_FEE_LNURL, 2);

$before = mock_lnurl_requests($host['dir']);
$inv1 = Invoice::create($s1, invoice_options());
assert_eq(FEE_NOTE_DEV, $inv1['fee_redirect_note'], 'dev fee took the invoice');
assert_eq('lightning', $inv1['fee_redirect_rails'], 'lightning rail is fee-routed');
assert_eq((string)CASHUPAY_DEV_FEE_LNURL, $inv1['ln_destination'], 'bolt11 came from the dev LNURL');
assert_clean_requests(new_requests($before), $devUser, 5000, $inv1, 'dev fee');

// ---------- 2. Hosting fee claims the lightning rail ----------
// 10% hosting fee on 1M sats revenue owes 100k > the dev fee's 1-2%, so the
// hosting fee is the largest owed and wins the rail.
$s2 = 'store_privacy_hosting';
make_store($s2, $mintStub);
Database::update('stores', [
    'name' => SECRET_STORE_NAME,
    'hosting_fee_percent' => 10,
    'hosting_fee_destination' => 'hostingpayee@example.test',
], 'id = ?', [$s2]);
StoreLnAddresses::replaceForStore($s2, ['merchant@example.test']);
paid_invoice($s2, 1_000_000);
$owed2 = DevFee::computeOwed($s2);
assert_true((int)$owed2['hosting_owed'] > (int)$owed2['dev_owed'], 'hosting fee is the largest owed');

$before = mock_lnurl_requests($host['dir']);
$inv2 = Invoice::create($s2, invoice_options());
assert_eq(FEE_NOTE_HOSTING, $inv2['fee_redirect_note'], 'hosting fee took the invoice');
assert_eq('hostingpayee@example.test', $inv2['ln_destination'], 'bolt11 came from the hosting LNURL');
assert_clean_requests(new_requests($before), 'hostingpayee', 5000, $inv2, 'hosting fee');

// ---------- 3. Control: merchant Lightning-address rail, no fee owed ----------
$s3 = 'store_privacy_merchant';
make_store($s3, $mintStub);
Database::update('stores', ['name' => SECRET_STORE_NAME], 'id = ?', [$s3]);
StoreLnAddresses::replaceForStore($s3, ['merchant@example.test']);

$before = mock_lnurl_requests($host['dir']);
$inv3 = Invoice::create($s3, invoice_options());
assert_null($inv3['fee_redirect_note'], 'no fee owed -> merchant rail');
assert_eq('lnaddress', $inv3['payment_rail'], 'merchant LN address rail');
assert_clean_requests(new_requests($before), 'merchant', 5000, $inv3, 'merchant lnaddress');

echo "test_fee_redirect_invoice_privacy: ok\n";
