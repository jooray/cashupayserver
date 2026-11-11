<?php
/**
 * Security tests for CLINK payment-receipt verification. The payment page
 * forwards a raw signed event off its live subscription; the server must only
 * settle on a genuine merchant-signed kind-21001 {res:'ok'} receipt for the
 * right request. All trust is the merchant's Schnorr signature — the browser
 * relays but must not be able to forge a "paid".
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/client.php';

use swentel\nostr\Key\Key;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Encryption\Nip44;

$key = new Key();
$mSk = $key->generatePrivateKey();           // merchant (receiver) identity
$mPk = $key->getPublicKey($mSk);
$eSk = $key->generatePrivateKey();           // payer ephemeral identity (ours)
$ePk = $key->getPublicKey($eSk);
$reqId = str_repeat('aa', 32);

$ctx = [
    'relay' => 'wss://unused.test',
    'receiver_pubkey' => $mPk,
    'ephemeral_sk' => $eSk,
    'ephemeral_pubkey' => $ePk,
    'request_event_id' => $reqId,
    'created_at' => 1700000000,
];

/** Build a kind-21001 event from $signerSk addressed to $ePk, body NIP-44 encrypted. */
function mkEvent(string $signerSk, string $ePk, string $reqId, array $body): array {
    $ck = Nip44::getConversationKey($signerSk, $ePk);
    $e = new Event();
    $e->setKind(21001);
    $e->setTags([['p', $ePk], ['e', $reqId]]);
    $e->setContent(Nip44::encrypt(json_encode($body), $ck));
    (new Sign())->signEvent($e, $signerSk);
    return $e->toArray();
}

// ---------- happy path ----------
$ok = mkEvent($mSk, $ePk, $reqId, ['res' => 'ok']);
assert_true(ClinkClient::verifyReceiptEvent($ok, $ctx)['paid'], 'valid merchant receipt → paid');

// Each rejection also names its reason — that's what the server logs when a
// forwarded receipt is refused, so a stuck payment screen is diagnosable.

// ---------- res != ok ----------
$notok = mkEvent($mSk, $ePk, $reqId, ['res' => 'GFY', 'error' => 'nope']);
$v = ClinkClient::verifyReceiptEvent($notok, $ctx);
assert_false($v['paid'], 'res!=ok → not paid');
assert_eq('res is not ok', $v['reason'] ?? null, 'res!=ok reason');

// ---------- impostor author (different key signs a {res:ok}) ----------
$xSk = $key->generatePrivateKey();
$impostor = mkEvent($xSk, $ePk, $reqId, ['res' => 'ok']);
$v = ClinkClient::verifyReceiptEvent($impostor, $ctx);
assert_false($v['paid'], 'wrong author → not paid');
assert_eq('author is not the offer receiver', $v['reason'] ?? null, 'wrong author reason');

// ---------- wrong request id (e-tag mismatch) ----------
$wrongRef = mkEvent($mSk, $ePk, str_repeat('bb', 32), ['res' => 'ok']);
$v = ClinkClient::verifyReceiptEvent($wrongRef, $ctx);
assert_false($v['paid'], 'wrong e-tag → not paid');
assert_eq('no e-tag referencing our request', $v['reason'] ?? null, 'wrong e-tag reason');

// ---------- tampered signature ----------
$tampered = mkEvent($mSk, $ePk, $reqId, ['res' => 'ok']);
$tampered['sig'][10] = $tampered['sig'][10] === 'a' ? 'b' : 'a';
$v = ClinkClient::verifyReceiptEvent($tampered, $ctx);
assert_false($v['paid'], 'tampered sig → not paid');
assert_eq('invalid signature', $v['reason'] ?? null, 'tampered sig reason');

// ---------- wrong kind ----------
$wrongKind = mkEvent($mSk, $ePk, $reqId, ['res' => 'ok']);
$wrongKind['kind'] = 1;
$v = ClinkClient::verifyReceiptEvent($wrongKind, $ctx);
assert_false($v['paid'], 'wrong kind → not paid');
assert_eq('wrong kind', $v['reason'] ?? null, 'wrong kind reason');

// ---------- undecryptable content (encrypted to a different key) ----------
// Genuinely merchant-signed and correctly tagged, but the body is NIP-44'd to
// someone else — only the decryption fails.
$ckWrong = Nip44::getConversationKey($mSk, $key->getPublicKey($xSk));
$e = new Event();
$e->setKind(21001);
$e->setTags([['p', $ePk], ['e', $reqId]]);
$e->setContent(Nip44::encrypt(json_encode(['res' => 'ok']), $ckWrong));
(new Sign())->signEvent($e, $mSk);
$v = ClinkClient::verifyReceiptEvent($e->toArray(), $ctx);
assert_false($v['paid'], 'undecryptable content → not paid');
assert_eq('undecryptable content', $v['reason'] ?? null, 'undecryptable content reason');

echo "test_clink_receipt_verify: ok\n";
