<?php
/**
 * Tests for NWC connections in the store destination chain: the three-list
 * chainFromLists() ordering (lnaddress -> nwc -> noffer), pubkey+secret dedup,
 * type persistence via replaceForStore(), keep:<id> reference resolution (the
 * mechanism that keeps secret-bearing URIs out of the browser round trip),
 * the save-time probe gate for new connections, and that every user-facing
 * surface (errors, probe results, display values) is masked.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';
require_once dirname(__DIR__, 2) . '/app/includes/nwc/client.php';

use swentel\nostr\Key\Key;

$store = 'store_nwc';
make_store($store);

$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();

$noffer = ClinkNoffer::encode([
    'pubkey' => str_repeat('cd', 32),
    'relay' => 'wss://relay.test',
    'offer' => 'shop',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);
$nwcUri = "nostr+walletconnect://{$walletPk}?relay=wss://relay.test&secret={$clientSk}";

// ---------- isValidEntry ----------
assert_true(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_NWC, $nwcUri), 'valid nwc entry');
assert_false(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_NWC, $noffer), 'noffer not valid as nwc');
assert_false(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_NWC, 'me@strike.me'), 'lnaddress not valid as nwc');
assert_false(StoreLnAddresses::isValidEntry(StoreLnAddresses::TYPE_LNADDRESS, $nwcUri), 'nwc not valid as lnaddress');

// ---------- three-list chain order: lnaddress -> nwc -> noffer ----------
$chain = StoreLnAddresses::chainFromLists(['a@strike.me'], [$noffer], [$nwcUri]);
assert_eq(3, count($chain), 'three destinations');
assert_eq('lnaddress', $chain[0]['type'], 'lnaddress first');
assert_eq('nwc', $chain[1]['type'], 'nwc second (before noffers)');
assert_eq($nwcUri, $chain[1]['value'], 'nwc value preserved');
assert_eq('noffer', $chain[2]['type'], 'noffer last');

// Empty third list keeps the two-list behaviour.
$chain2 = StoreLnAddresses::chainFromLists(['a@strike.me'], [$noffer]);
assert_eq(2, count($chain2), 'two-list call unchanged');

// ---------- dedup by wallet pubkey + secret, masked error ----------
$sameConnOtherRelay = "nostr+walletconnect:{$walletPk}?secret={$clientSk}&relay=wss://other.example";
$threw = false;
try {
    StoreLnAddresses::chainFromLists([], [], [$nwcUri, $sameConnOtherRelay]);
} catch (InvalidArgumentException $e) {
    $threw = true;
    assert_true(strpos($e->getMessage(), 'Duplicate') !== false, 'duplicate reported');
    assert_true(strpos($e->getMessage(), $clientSk) === false, 'duplicate error leaks no secret');
}
assert_true($threw, 'same wallet+secret with different relay dedups');

// Different secret to the same wallet is a distinct connection.
$otherSk = $key->generatePrivateKey();
$otherConn = "nostr+walletconnect://{$walletPk}?relay=wss://relay.test&secret={$otherSk}";
assert_eq(2, count(StoreLnAddresses::chainFromLists([], [], [$nwcUri, $otherConn])), 'different secret not a dup');

// ---------- invalid nwc paste: error hides the value ----------
$threw = false;
try {
    StoreLnAddresses::chainFromLists([], [], ['nostr+walletconnect://broken?secret=' . $clientSk]);
} catch (InvalidArgumentException $e) {
    $threw = true;
    assert_true(strpos($e->getMessage(), $clientSk) === false, 'invalid-format error leaks no secret');
    assert_true(strpos($e->getMessage(), 'hidden') !== false, 'invalid-format error says value hidden');
}
assert_true($threw, 'malformed nwc rejected');

// ---------- replaceForStore persists the type; supports_verify forced NULL ----------
StoreLnAddresses::replaceForStore($store, [
    ['type' => 'lnaddress', 'address' => 'a@strike.me', 'supports_verify' => 1],
    ['type' => 'nwc', 'address' => $nwcUri, 'supports_verify' => 1], // 1 must be discarded
    ['type' => 'noffer', 'address' => $noffer],
]);
$list = StoreLnAddresses::listForStore($store);
assert_eq(3, count($list), 'three rows stored');
assert_eq('nwc', $list[1]['type'], 'nwc row type persisted at position 1');
assert_eq($nwcUri, $list[1]['address'], 'nwc URI stored verbatim (server-side only)');
assert_null($list[1]['supports_verify'], 'supports_verify forced NULL for nwc');

// ---------- displayValue masks nwc only ----------
assert_eq('a@strike.me', StoreLnAddresses::displayValue('lnaddress', 'a@strike.me'), 'lnaddress passthrough');
$masked = StoreLnAddresses::displayValue('nwc', $nwcUri);
assert_true(strpos($masked, $clientSk) === false, 'displayValue leaks no secret');
assert_true(strpos($masked, 'relay.test') !== false, 'displayValue names relay host');

// ---------- resolveKeepRefs ----------
$nwcRowId = null;
foreach ($list as $row) {
    if ($row['type'] === 'nwc') { $nwcRowId = $row['id']; }
}
assert_true($nwcRowId !== null, 'stored nwc row id found');

[$resolved, $keptKeys] = StoreLnAddresses::resolveKeepRefs($store, ["keep:{$nwcRowId}", $otherConn, '', '  ']);
assert_eq(2, count($resolved), 'blank entries dropped');
assert_eq($nwcUri, $resolved[0], 'keep ref resolved to stored URI');
assert_eq($otherConn, $resolved[1], 'full URI passes through');
assert_eq(1, count($keptKeys), 'kept key recorded for the resolved row');

$threw = false;
try {
    StoreLnAddresses::resolveKeepRefs($store, ['keep:999999']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'unknown keep ref rejected');

// A ref must not cross store boundaries: another store can't claim this row.
make_store('store_other');
$threw = false;
try {
    StoreLnAddresses::resolveKeepRefs('store_other', ["keep:{$nwcRowId}"]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, "keep ref scoped to its own store");

// A ref to a non-nwc row is rejected too.
$lnRowId = $list[0]['id'];
$threw = false;
try {
    StoreLnAddresses::resolveKeepRefs($store, ["keep:{$lnRowId}"]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'keep ref to a non-nwc row rejected');

// ---------- probe gate ----------

/** Start the mock wallet; returns [proc, port]. */
function start_wallet(array $env): array {
    static $seq = 0;
    $base = 27400 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_NWC_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/php/mock_nwc_wallet.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail('mock NWC wallet failed to start on any port');
}

// Stored connection: probe skipped entirely — the (unreachable) relay in the
// stored URI would fail a probe, so passing proves the grandfather path.
$gate = StoreLnAddresses::probeAndGateChain($store, [
    ['type' => 'nwc', 'value' => $nwcUri],
]);
assert_eq(1, count($gate['entries']), 'stored nwc entry passes without probe');
assert_eq('nwc', $gate['results'][0]['type'], 'result typed nwc');
assert_true(strpos($gate['results'][0]['address'], $clientSk) === false, 'probe result masked');

// New connection against a live wallet that also has spend permissions:
// gate passes, warning surfaces in results.
[$proc, $port] = start_wallet([
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_STATE' => 'pending',
    'MOCK_NWC_METHODS' => 'pay_invoice make_invoice lookup_invoice',
]);
try {
    $newConn = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$otherSk}";
    $gate = StoreLnAddresses::probeAndGateChain($store, [
        ['type' => 'nwc', 'value' => $newConn],
    ]);
    assert_eq(1, count($gate['entries']), 'probed nwc entry accepted');
    assert_true(!empty($gate['results'][0]['warning']), 'spend-permission warning passed through');
    assert_true(strpos($gate['results'][0]['address'], $otherSk) === false, 'probed result masked');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// New connection whose relay is dead: blocked with a masked, actionable error.
$deadConn = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:1/&secret={$otherSk}";
$threw = false;
try {
    StoreLnAddresses::probeAndGateChain($store, [['type' => 'nwc', 'value' => $deadConn]]);
} catch (RuntimeException $e) {
    $threw = true;
    assert_true(strpos($e->getMessage(), $otherSk) === false, 'gate error leaks no secret');
    assert_true(stripos($e->getMessage(), 'connection test') !== false, 'gate error is actionable');
}
assert_true($threw, 'new nwc with dead relay blocked at save');

echo "test_nwc_destinations: ok\n";
