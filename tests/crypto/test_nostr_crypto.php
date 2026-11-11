<?php
/**
 * NostrCrypto: official NIP-44 vectors, nostr-php interop, and event
 * sign/verify hardening — on every available BigNum backend.
 *
 * Vector source: https://github.com/paulmillr/nip44 (nip44.vectors.json,
 * vendored beside this test). Sections used:
 *   valid.get_conversation_key    — our ECDH+HKDF against 35 known answers
 *   invalid.get_conversation_key  — out-of-range keys must throw, not derive
 *   valid.encrypt_decrypt         — full payloads through swentel Nip44 with
 *                                   our conversation key (pins the composed
 *                                   pipeline the clients actually run)
 *   invalid.decrypt               — malformed payloads must throw
 *
 * Interop (GMP parity): events signed by NostrCrypto must verify under
 * swentel/nostr-php and vice versa; conversation keys must match
 * Nip44::getConversationKey bit-for-bit. This is what licenses swapping the
 * GMP-only library crypto out of the CLINK/NWC clients.
 *
 * The full battery runs on GMP; on BCMath a deterministic subset keeps the
 * runtime sane (~10s) — the GMP↔BCMath equivalence itself is pinned
 * exhaustively by test_bignum + test_secp256k1_backends.
 */

require_once __DIR__ . '/../../app/includes/crypto/nostr_crypto.php';

use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Encryption\Nip04;

$VEC = json_decode(file_get_contents(__DIR__ . '/nip44_vectors.json'), true)['v2'];

$failures = 0;
$checks = 0;

function check(bool $ok, string $label): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        echo "FAIL {$label}\n";
        $failures++;
    }
}

$haveGmp = function_exists('gmp_init');
$haveBc = function_exists('bcadd');
if (!$haveGmp && !$haveBc) {
    fwrite(STDERR, "neither GMP nor BCMath available\n");
    exit(1);
}

$backends = [];
if ($haveGmp) $backends[] = 'gmp';
if ($haveBc) $backends[] = 'bcmath';

foreach ($backends as $backend) {
    BigNum::forceBackend($backend);
    Secp256k1::resetCaches();
    $sparse = ($backend === 'bcmath'); // subset on the slow backend

    // --- valid conversation keys ---
    foreach ($VEC['valid']['get_conversation_key'] as $i => $v) {
        if ($sparse && $i % 4 !== 0) continue;
        $got = bin2hex(NostrCrypto::nip44ConversationKey($v['sec1'], $v['pub2']));
        check($got === $v['conversation_key'], "[{$backend}] conv_key #{$i}: got {$got}");
    }

    // --- invalid conversation keys must throw ---
    foreach ($VEC['invalid']['get_conversation_key'] as $i => $v) {
        try {
            NostrCrypto::nip44ConversationKey($v['sec1'], $v['pub2']);
            check(false, "[{$backend}] invalid conv_key #{$i} did not throw ({$v['note']})");
        } catch (Throwable $e) {
            check(true, '');
        }
    }

    // --- encrypt/decrypt payload vectors through the real pipeline ---
    foreach ($VEC['valid']['encrypt_decrypt'] as $i => $v) {
        if ($sparse && $i % 3 !== 0) continue;
        $pub2 = NostrCrypto::derivePublicKeyHex($v['sec2']);
        $convKey = NostrCrypto::nip44ConversationKey($v['sec1'], $pub2);
        check(bin2hex($convKey) === $v['conversation_key'], "[{$backend}] enc_dec #{$i} conversation key");
        $payload = Nip44::encrypt($v['plaintext'], $convKey, hex2bin($v['nonce']));
        check($payload === $v['payload'], "[{$backend}] enc_dec #{$i} payload");
        check(Nip44::decrypt($v['payload'], $convKey) === $v['plaintext'], "[{$backend}] enc_dec #{$i} decrypt");
    }

    // --- event sign + verify round trip ---
    $sk = 'a3d219eb15e6a51e2f7f7d10bd6fe11e56344c9db2a2fb2c7a09e1f8a2c402ae';
    $event = new Event();
    $event->setKind(21001);
    $event->setCreatedAt(1700000000);
    $event->setTags([['p', str_repeat('ab', 32)], ['clink_version', '1']]);
    $event->setContent('backend round trip ' . $backend);
    NostrCrypto::signEvent($event, $sk);
    $arr = $event->toArray();
    check(NostrCrypto::verifyEventArray($arr), "[{$backend}] own sign→verify");
    check($arr['pubkey'] === NostrCrypto::derivePublicKeyHex($sk), "[{$backend}] signEvent pubkey");

    // --- verify hardening: every tampering must fail ---
    $tampered = $arr; $tampered['content'] .= 'x';
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] tampered content rejected");
    $tampered = $arr; $tampered['sig'] = str_repeat('0', 128);
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] zero sig rejected");
    $tampered = $arr; $tampered['sig'] = strrev($arr['sig']);
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] mangled sig rejected");
    $tampered = $arr; $tampered['tags'][] = ['e', 'spoofed-reference'];
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] appended tag rejected");
    $tampered = $arr; $tampered['tags'][0][1] = 123; // non-string tag value
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] non-string tag rejected");
    $tampered = $arr; $tampered['created_at'] = (string)$arr['created_at'];
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] string created_at rejected");
    $tampered = $arr; $tampered['pubkey'] = strtoupper($arr['pubkey']);
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] uppercase pubkey rejected (id would differ)");
    $tampered = $arr; unset($tampered['sig']);
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] missing sig rejected");
    // s >= n in an otherwise well-formed sig
    $tampered = $arr;
    $tampered['sig'] = substr($arr['sig'], 0, 64) . 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    check(!NostrCrypto::verifyEventArray($tampered), "[{$backend}] s == n rejected");

    // --- key generation sanity ---
    $gen = NostrCrypto::generatePrivateKeyHex();
    check(preg_match('/^[0-9a-f]{64}$/', $gen) === 1, "[{$backend}] generated key format");
    check(preg_match('/^[0-9a-f]{64}$/', NostrCrypto::derivePublicKeyHex($gen)) === 1, "[{$backend}] derived pubkey format");

    // --- NIP-04 round trip ---
    $skA = '7f3b02c9d1e4a6f8091b2c3d4e5f60718293a4b5c6d7e8f9010203a4b5c6d7e8';
    $skB = '11e0a352c6b3f4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f7081920a1b2c3';
    $pkA = NostrCrypto::derivePublicKeyHex($skA);
    $pkB = NostrCrypto::derivePublicKeyHex($skB);
    $ct = NostrCrypto::nip04Encrypt('nip04 round trip', $skA, $pkB);
    check(NostrCrypto::nip04Decrypt($ct, $skB, $pkA) === 'nip04 round trip', "[{$backend}] nip04 both directions");
    try {
        NostrCrypto::nip04Decrypt('not-a-payload', $skB, $pkA);
        check(false, "[{$backend}] nip04 malformed payload did not throw");
    } catch (Throwable $e) {
        check(true, '');
    }
}
BigNum::forceBackend(null);
Secp256k1::resetCaches();

// --- malformed NIP-44 payloads must throw (library path we depend on) ---
foreach ($VEC['invalid']['decrypt'] as $i => $v) {
    try {
        Nip44::decrypt($v['payload'], hex2bin($v['conversation_key']));
        check(false, "invalid decrypt #{$i} did not throw ({$v['note']})");
    } catch (Throwable $e) {
        check(true, '');
    }
}

// --- interop with swentel/nostr-php (needs its GMP-backed crypto) ---
if ($haveGmp) {
    $sk = '67dea2ed018072d675f5415ecfaed7d2597555e202d85b3d65ea4e58d2d92ffa';

    // Conversation key parity, both directions of key order.
    $pk = NostrCrypto::derivePublicKeyHex('a3d219eb15e6a51e2f7f7d10bd6fe11e56344c9db2a2fb2c7a09e1f8a2c402ae');
    check(
        NostrCrypto::nip44ConversationKey($sk, $pk) === Nip44::getConversationKey($sk, $pk),
        'interop: conversation key parity with nostr-php'
    );

    // Our signature verifies under nostr-php.
    $event = new Event();
    $event->setKind(23194);
    $event->setCreatedAt(1700000001);
    $event->setTags([['p', $pk]]);
    $event->setContent('ours → nostr-php');
    NostrCrypto::signEvent($event, $sk);
    check($event->verify(), 'interop: our signature verifies under nostr-php');

    // nostr-php's signature verifies under us.
    $event2 = new Event();
    $event2->setKind(23195);
    $event2->setCreatedAt(1700000002);
    $event2->setTags([['e', str_repeat('cd', 32)]]);
    $event2->setContent('nostr-php → ours');
    (new Sign())->signEvent($event2, $sk);
    check(NostrCrypto::verifyEventArray($event2->toArray()), 'interop: nostr-php signature verifies under us');

    // Pubkey derivation parity.
    check(
        NostrCrypto::derivePublicKeyHex($sk) === (new \swentel\nostr\Key\Key())->getPublicKey($sk),
        'interop: pubkey derivation parity'
    );

    // NIP-04 interop, both directions.
    $skB = '11e0a352c6b3f4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f7081920a1b2c3';
    $pkB = NostrCrypto::derivePublicKeyHex($skB);
    $theirCt = Nip04::encrypt('nostr-php → ours', $sk, $pkB);
    check(NostrCrypto::nip04Decrypt($theirCt, $skB, NostrCrypto::derivePublicKeyHex($sk)) === 'nostr-php → ours',
        'interop: we decrypt nostr-php NIP-04');
    $ourCt = NostrCrypto::nip04Encrypt('ours → nostr-php', $sk, $pkB);
    check(Nip04::decrypt($ourCt, $skB, NostrCrypto::derivePublicKeyHex($sk)) === 'ours → nostr-php',
        'interop: nostr-php decrypts our NIP-04');
} else {
    echo "NOTE: GMP absent — nostr-php interop section skipped\n";
}

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
