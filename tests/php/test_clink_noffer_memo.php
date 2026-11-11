<?php
/**
 * Tests for the store-name memo cashupayserver attaches to noffer-issued
 * Lightning invoices.
 *
 * Two layers:
 *   1. Unit — Invoice::nofferMemo() composes "<store name> - <itemDesc>",
 *      caps at the CLINK spec's 100-char description limit (mb-safe), and
 *      degrades gracefully when pieces are missing.
 *   2. Integration — Invoice::create() on the noffer rail actually SENDS that
 *      memo as the NIP-69 `description` in the encrypted kind-21001 request.
 *      Driven against the mock relay (which decrypts the request with the
 *      merchant key and dumps the plaintext payload for inspection).
 *
 * The receiving wallet may honor or ignore the description per NIP-69; this
 * suite asserts only what cashupayserver controls — that a spec-compliant
 * best-effort memo is sent. The wallet-side honoring is a separate concern
 * (electrum_clink plugin) covered in that repo.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/clink/client.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

use swentel\nostr\Key\Key;

// ---------------------------------------------------------------------------
// Unit: Invoice::nofferMemo
// ---------------------------------------------------------------------------
assert_eq('Acme Coffee', Invoice::nofferMemo(['name' => 'Acme Coffee'], null),
    'store name only when no metadata');
assert_eq('Acme Coffee', Invoice::nofferMemo(['name' => 'Acme Coffee'], ['itemDesc' => '']),
    'empty itemDesc ignored');
assert_eq('Acme Coffee - 2x Latte', Invoice::nofferMemo(['name' => 'Acme Coffee'], ['itemDesc' => '2x Latte']),
    'store name + itemDesc joined');
assert_eq('2x Latte', Invoice::nofferMemo(['name' => ''], ['itemDesc' => '2x Latte']),
    'note alone when store name blank');
assert_eq('', Invoice::nofferMemo([], null),
    'empty string when nothing to say (so caller can omit the field)');
assert_eq('', Invoice::nofferMemo(['name' => '   '], ['itemDesc' => "  \t "]),
    'whitespace-only pieces trimmed away');

// Cap at 100 chars, mb-safe (multibyte names must not be cut mid-character).
$longName = str_repeat('é', 120);
$memo = Invoice::nofferMemo(['name' => $longName], null);
assert_true(mb_strlen($memo) <= 100, 'memo capped at 100 chars');
assert_eq(str_repeat('é', 100), $memo, 'multibyte truncation keeps whole characters');

// ---------------------------------------------------------------------------
// Unit: order reference (metadata.orderId) in the memo
// ---------------------------------------------------------------------------
assert_eq('Acme Coffee - Order 123 - 2x Latte',
    Invoice::nofferMemo(['name' => 'Acme Coffee'], ['orderId' => '123', 'itemDesc' => '2x Latte']),
    'order reference sits between store name and note');
assert_eq('Acme Coffee - Order 123',
    Invoice::nofferMemo(['name' => 'Acme Coffee'], ['orderId' => 123]),
    'numeric orderId accepted, no note');
assert_eq('Order 123',
    Invoice::nofferMemo(['name' => ''], ['orderId' => '123']),
    'order reference alone when nothing else to say');
assert_eq('Acme Coffee - 2x Latte',
    Invoice::nofferMemo(['name' => 'Acme Coffee'], ['orderId' => '  ', 'itemDesc' => '2x Latte']),
    'blank orderId ignored');
assert_eq('Acme Coffee - 2x Latte',
    Invoice::nofferMemo(['name' => 'Acme Coffee'], ['orderId' => true, 'itemDesc' => '2x Latte']),
    'non-string/number orderId ignored');

// The order reference is NOT privacy-gated: hiding name and note still leaves
// it, so the receiving wallet can match the payment to its order.
assert_eq('Order 123',
    Invoice::nofferMemo(['name' => 'Acme Coffee'],
        ['orderId' => '123', 'itemDesc' => '2x Latte', 'hideStoreName' => true, 'hideNote' => true]),
    'order reference survives hiding both name and note');

// Truncation sheds the note first, then the name — the order reference must
// survive the 100-char cap.
$memo = Invoice::nofferMemo(['name' => 'Acme Coffee'],
    ['orderId' => '123', 'itemDesc' => str_repeat('x', 150)]);
assert_true(mb_strlen($memo) <= 100, 'order memo capped at 100 chars');
assert_true(str_contains($memo, 'Order 123'), 'order reference survives note overflow');
assert_true(str_starts_with($memo, 'Acme Coffee - Order 123 - '), 'name and order intact, note trimmed');

$memo = Invoice::nofferMemo(['name' => str_repeat('n', 150)], ['orderId' => '123']);
assert_true(mb_strlen($memo) <= 100, 'order memo capped when name overflows');
assert_true(str_contains($memo, 'Order 123'), 'order reference survives name overflow');

// ---------------------------------------------------------------------------
// Integration: Invoice::create sends the memo as the NIP-69 description
// ---------------------------------------------------------------------------

/** Start the mock relay/merchant; returns [proc, pipes, port]. */
function start_relay(array $env): array {
    static $seq = 0;
    $base = 26000 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_CLINK_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/mock_clink_relay.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $pipes, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail('mock relay failed to start on any port');
}

function stop_relay($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); }
}

/** Poll for the relay's request dump, returning the decoded payload or null. */
function wait_for_dump(string $path, float $timeoutSec = 5.0): ?array {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        if (is_file($path) && filesize($path) > 0) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        usleep(100000);
    }
    return null;
}

$key = new Key();
$mSk = $key->generatePrivateKey();
$mPk = $key->getPublicKey($mSk);

$dumpFile = sys_get_temp_dir() . '/clink_req_dump_' . bin2hex(random_bytes(4)) . '.json';
[$proc, $pipes, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => 'lnbc100n1mockinvoice0000000',
    'MOCK_CLINK_DUMP' => $dumpFile,
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relay' => "ws://127.0.0.1:$port",
        'offer' => 'shop',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);

    // Stub mint so the store is "lightning capable"; the noffer rail is tried
    // first and wins, so the non-routable mint is never contacted.
    $store = 'store_noffer_memo';
    make_store($store, 'http://127.0.0.1:1');
    Database::update('stores', ['name' => 'Acme Coffee'], 'id = ?', [$store]);
    StoreLnAddresses::replaceForStore($store, [['type' => 'noffer', 'address' => $noffer]]);

    $inv = Invoice::create($store, [
        'amount' => 1000,
        'currency' => 'sat',
        'metadata' => ['itemDesc' => '2x Latte'],
    ]);
    assert_eq('noffer', $inv['payment_rail'], 'invoice rides the noffer rail');
    assert_eq('lnbc100n1mockinvoice0000000', $inv['bolt11'], 'mock bolt11 stored on the invoice');

    $payload = wait_for_dump($dumpFile);
    assert_not_null($payload, 'relay dumped the decrypted request payload');
    assert_eq('Acme Coffee - 2x Latte', $payload['description'] ?? null,
        'store-name memo sent as the NIP-69 description');
    assert_eq('shop', $payload['offer'] ?? null, 'offer id sent in the request');
    assert_eq(1000, $payload['amount_sats'] ?? null, 'amount_sats sent in the request');
} finally {
    stop_relay($proc);
    @unlink($dumpFile);
}

// ---------------------------------------------------------------------------
// Integration: invoice-privacy settings gate what reaches the NIP-69 memo.
// ---------------------------------------------------------------------------

/**
 * Create a noffer invoice and return the description the relay actually saw.
 * Each call spins up its own relay/dump so the cases stay independent.
 */
function noffer_description(string $mSk, string $mPk, string $storeId, callable $configure, array $metadata): ?string {
    $dump = sys_get_temp_dir() . '/clink_req_dump_' . bin2hex(random_bytes(4)) . '.json';
    [$proc, $pipes, $port] = start_relay([
        'MOCK_CLINK_MERCHANT_SK' => $mSk,
        'MOCK_CLINK_BOLT11' => 'lnbc100n1mockinvoice0000000',
        'MOCK_CLINK_DUMP' => $dump,
    ]);
    try {
        $noffer = ClinkNoffer::encode([
            'pubkey' => $mPk,
            'relay' => "ws://127.0.0.1:$port",
            'offer' => 'shop',
            'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
        ]);
        make_store($storeId, 'http://127.0.0.1:1');
        Database::update('stores', ['name' => 'Acme Coffee'], 'id = ?', [$storeId]);
        $configure($storeId);
        StoreLnAddresses::replaceForStore($storeId, [['type' => 'noffer', 'address' => $noffer]]);
        Invoice::create($storeId, ['amount' => 1000, 'currency' => 'sat', 'metadata' => $metadata]);
        $payload = wait_for_dump($dump);
        assert_not_null($payload, 'relay dumped the decrypted request payload');
        // NIP-69 omits an empty description, so a fully-suppressed memo arrives
        // as a missing key — normalise that to '' for the assertions.
        return array_key_exists('description', $payload) ? (string)$payload['description'] : '';
    } finally {
        stop_relay($proc);
        @unlink($dump);
    }
}

$noop = function (string $s): void {};
$hideName = function (string $s): void {
    Database::update('stores', ['hide_store_name_on_invoice' => 1], 'id = ?', [$s]);
};

// Per-store "hide store name" -> the description carries only the note.
assert_eq('2x Latte',
    noffer_description($mSk, $mPk, 'store_noffer_hide_name', $hideName, ['itemDesc' => '2x Latte']),
    'store-level hide store name -> NIP-69 description is note only');

// Per-invoice override still wins: force-show the name on a store that hides it.
assert_eq('Acme Coffee - 2x Latte',
    noffer_description($mSk, $mPk, 'store_noffer_force_show', $hideName,
        ['itemDesc' => '2x Latte', 'hideStoreName' => false]),
    'per-invoice hideStoreName=false force-shows the name over the store default');

// Per-invoice "hide note" on a default store -> name only.
assert_eq('Acme Coffee',
    noffer_description($mSk, $mPk, 'store_noffer_hide_note', $noop,
        ['itemDesc' => '2x Latte', 'hideNote' => true]),
    'per-invoice hideNote -> NIP-69 description is store name only');

// Hide both -> empty description (the field is omitted on the wire).
assert_eq('',
    noffer_description($mSk, $mPk, 'store_noffer_hide_both', $noop,
        ['itemDesc' => '2x Latte', 'hideStoreName' => true, 'hideNote' => true]),
    'hiding both leaves no NIP-69 description');

// WooCommerce-style metadata.orderId reaches the wire as part of the memo.
assert_eq('Acme Coffee - Order 1042 - 2x Latte',
    noffer_description($mSk, $mPk, 'store_noffer_order', $noop,
        ['orderId' => '1042', 'itemDesc' => '2x Latte']),
    'order reference sent in the NIP-69 description');

// …and is NOT silenced by the privacy toggles.
assert_eq('Order 1042',
    noffer_description($mSk, $mPk, 'store_noffer_order_priv', $noop,
        ['orderId' => '1042', 'itemDesc' => '2x Latte', 'hideStoreName' => true, 'hideNote' => true]),
    'order reference survives the privacy toggles on the wire');

echo "test_clink_noffer_memo: OK\n";
