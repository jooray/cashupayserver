<?php
/**
 * Unit tests for the NWC (NIP-47) connection-URI codec: parsing both scheme
 * spellings, multi-relay handling, strict pubkey/secret/relay validation, the
 * pubkey+secret dedup identity, and — security-critical — that the display
 * label never contains the connection secret.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/nwc/uri.php';

$pk = str_repeat('a1', 32);
$sk = str_repeat('b2', 32);

// ---------- canonical form ----------
$uri = "nostr+walletconnect://{$pk}?relay=wss%3A%2F%2Frelay.example.com&secret={$sk}&lud16=me%40example.com";
assert_true(NwcUri::isValid($uri), 'canonical URI validates');
$p = NwcUri::parse($uri);
assert_eq($pk, $p['pubkey'], 'pubkey parsed');
assert_eq('wss://relay.example.com', $p['relay'], 'url-encoded relay decoded');
assert_eq($sk, $p['secret'], 'secret parsed');
assert_eq('me@example.com', $p['lud16'], 'lud16 decoded');

// ---------- no-slashes spelling + multiple relays ----------
$uri2 = "nostr+walletconnect:{$pk}?relay=wss://a.example&relay=ws://127.0.0.1:7777&secret={$sk}";
assert_true(NwcUri::isValid($uri2), 'no-slashes spelling validates');
$p2 = NwcUri::parse($uri2);
assert_eq('wss://a.example', $p2['relay'], 'first relay is primary');
assert_eq(2, count($p2['relays']), 'both relays kept');
assert_eq('ws://127.0.0.1:7777', $p2['relays'][1], 'repeated relay param preserved');
assert_eq(null, $p2['lud16'], 'lud16 optional');

// ---------- case folding ----------
$p3 = NwcUri::parse('nostr+walletconnect://' . strtoupper($pk) . "?relay=wss://r.example&secret=" . strtoupper($sk));
assert_eq($pk, $p3['pubkey'], 'pubkey hex lowercased');
assert_eq($sk, $p3['secret'], 'secret hex lowercased');

// ---------- dedup identity ----------
assert_eq(
    NwcUri::dedupKey($uri),
    NwcUri::dedupKey("nostr+walletconnect:{$pk}?secret={$sk}&relay=wss://other.example&foo=bar"),
    'dedup key ignores relay/param differences (same wallet + secret)'
);
$otherSecret = str_repeat('c3', 32);
assert_true(
    NwcUri::dedupKey($uri) !== NwcUri::dedupKey("nostr+walletconnect://{$pk}?relay=wss://r.example&secret={$otherSecret}"),
    'different secret = different connection'
);

// ---------- display label must never leak the secret ----------
$label = NwcUri::displayLabel($uri);
assert_true(strpos($label, $sk) === false, 'label contains no secret');
assert_true(strpos($label, substr($sk, 0, 16)) === false, 'label contains no secret prefix');
assert_true(strpos($label, 'relay.example.com') !== false, 'label names the relay host');
assert_true(strpos($label, substr($pk, 0, 8)) !== false, 'label names the wallet pubkey prefix');
$badLabel = NwcUri::displayLabel('nostr+walletconnect://garbage?secret=' . $sk);
assert_true(strpos($badLabel, $sk) === false, 'unparseable input label leaks nothing');

// ---------- rejects ----------
foreach ([
    'not a uri at all',
    'noffer1qqsw9hxeteypq',
    'lightning:noffer1qqs',
    "nostr+walletconnect://{$pk}",                                          // no query
    "nostr+walletconnect://{$pk}?relay=wss://r.example",                    // no secret
    "nostr+walletconnect://{$pk}?secret={$sk}",                             // no relay
    "nostr+walletconnect://{$pk}?relay=https://r.example&secret={$sk}",     // non-ws relay
    "nostr+walletconnect://{$pk}?relay=wss://r.example&secret=abcd",        // short secret
    "nostr+walletconnect://shortkey?relay=wss://r.example&secret={$sk}",    // bad pubkey
    "nostr+walletconnect://{$pk}zz?relay=wss://r.example&secret={$sk}",     // non-hex pubkey
    "walletconnect://{$pk}?relay=wss://r.example&secret={$sk}",             // wrong scheme
] as $bad) {
    assert_true(!NwcUri::isValid($bad), 'rejected: ' . substr($bad, 0, 40));
}

echo "test_nwc_uri_codec: ok\n";
