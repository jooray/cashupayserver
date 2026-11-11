<?php
/**
 * Mock CLINK/Nostr relay for tests. Acts as both relay AND merchant service:
 * accepts a REQ subscription, receives the payer's encrypted kind-21001 request
 * (the EVENT), decrypts it with the merchant key, and replies with an encrypted
 * kind-21001 carrying {bolt11} (or an {error,code} when MOCK_CLINK_ERROR is set).
 *
 * Driven by env vars so the test can script behaviour:
 *   MOCK_CLINK_PORT       TCP port to listen on
 *   MOCK_CLINK_MERCHANT_SK merchant (receiver) private key hex
 *   MOCK_CLINK_BOLT11     bolt11 to return on success
 *   MOCK_CLINK_ERROR_CODE if set, reply with this NIP-69 error code instead
 *   MOCK_CLINK_SEND_RECEIPT if "1", also send a {res:'ok'} receipt after the reply
 *   MOCK_CLINK_RECEIPT_AFTER_EOSE if "1", send the receipt AFTER the EOSE —
 *                        simulating a spec-compliant relay that retains no
 *                        ephemeral events, where the receipt only ever arrives
 *                        live on an open subscription (needs MOCK_CLINK_SEND_RECEIPT=1)
 *   MOCK_CLINK_SILENT    if "1", accept the request (relay OK) but never send
 *                        the merchant reply — the client must time out
 *   MOCK_CLINK_DUMP      if set, decrypt the payer's request and write the
 *                        plaintext JSON payload to this path (lets a test assert
 *                        what the payer actually sent, e.g. the description memo)
 *
 * Not a general-purpose relay — just enough of NIP-01 framing to exercise the
 * client's subscribe-before-publish round trip.
 */

declare(strict_types=1);

require __DIR__ . '/../../app/vendor/autoload.php';

use swentel\nostr\Key\Key;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Encryption\Nip44;
use WebSocket\Server;
use WebSocket\Message\Text;

$port = (int)(getenv('MOCK_CLINK_PORT') ?: 0);
$merchantSk = (string)getenv('MOCK_CLINK_MERCHANT_SK');
$bolt11 = (string)(getenv('MOCK_CLINK_BOLT11') ?: 'lnbc10n1mockinvoice');
$errorCode = getenv('MOCK_CLINK_ERROR_CODE');
$sendReceipt = getenv('MOCK_CLINK_SEND_RECEIPT') === '1';
$receiptAfterEose = getenv('MOCK_CLINK_RECEIPT_AFTER_EOSE') === '1';

if ($port <= 0 || $merchantSk === '') {
    fwrite(STDERR, "mock_clink_relay: MOCK_CLINK_PORT and MOCK_CLINK_MERCHANT_SK required\n");
    exit(2);
}

$key = new Key();
$merchantPk = $key->getPublicKey($merchantSk);

$server = new Server($port, false);

// Per-connection subscription id, captured from the REQ.
$server->onText(function (Server $srv, $conn, Text $message) use (
    $merchantSk, $merchantPk, $bolt11, $errorCode, $sendReceipt, $receiptAfterEose
) {
    $data = json_decode($message->getContent(), true);
    if (!is_array($data) || !isset($data[0])) {
        return;
    }
    if ($data[0] === 'REQ') {
        $sub = (string)($data[1] ?? 'sub');
        $conn->setMeta('sub', $sub);
        // Simulate a retention-friendly relay: when configured to send a
        // receipt, replay one to any subscription whose filter references a
        // request (#e) and payer (#p). This lets the cron-style re-subscribe
        // (fetchReceipt) observe a receipt it didn't see live.
        $filter = $data[2] ?? [];
        $payerPk = $filter['#p'][0] ?? null;
        $reqId = $filter['#e'][0] ?? null;
        if ($sendReceipt && $errorCode === false && $payerPk && $reqId && !$receiptAfterEose) {
            $receipt = makeMerchantEvent($merchantSk, (string)$payerPk, (string)$reqId, ['res' => 'ok']);
            $conn->send(new Text(json_encode(['EVENT', $sub, $receipt])));
        }
        $conn->send(new Text(json_encode(['EOSE', $sub])));
        // Spec-compliant-relay mode: nothing retained, so the receipt shows up
        // only live, after EOSE — exactly what a subscriber that hangs up at
        // EOSE would miss and a live-listen window catches.
        if ($sendReceipt && $errorCode === false && $payerPk && $reqId && $receiptAfterEose) {
            $receipt = makeMerchantEvent($merchantSk, (string)$payerPk, (string)$reqId, ['res' => 'ok']);
            $conn->send(new Text(json_encode(['EVENT', $sub, $receipt])));
        }
        return;
    }
    if ($data[0] === 'EVENT' && isset($data[1]) && is_array($data[1])) {
        $reqEvent = $data[1];
        $payerPk = (string)($reqEvent['pubkey'] ?? '');
        $reqId = (string)($reqEvent['id'] ?? '');
        $sub = $conn->getMeta('sub') ?? 'sub';

        // Optionally decrypt + dump the payer's request payload so a test can
        // assert what was sent (e.g. the NIP-69 description memo). Best-effort.
        $dump = getenv('MOCK_CLINK_DUMP');
        if ($dump !== false && $dump !== '') {
            try {
                $ck = Nip44::getConversationKey($merchantSk, $payerPk);
                $plain = Nip44::decrypt((string)($reqEvent['content'] ?? ''), $ck);
                file_put_contents($dump, $plain);
            } catch (\Throwable $e) {
                file_put_contents($dump, json_encode(['decrypt_error' => $e->getMessage()]));
            }
        }

        // Acknowledge the publish (relay OK).
        $conn->send(new Text(json_encode(['OK', $reqId, true, ''])));

        if (getenv('MOCK_CLINK_SILENT') === '1') {
            return; // accept but never answer — client must time out
        }

        // Build the encrypted reply addressed back to the payer.
        if ($errorCode !== false && $errorCode !== '') {
            $body = ['error' => 'mock error', 'code' => (int)$errorCode];
            if ((int)$errorCode === 5) {
                $body['range'] = ['min' => 10, 'max' => 1000000];
            }
        } else {
            $body = ['bolt11' => $bolt11];
        }
        $reply = makeMerchantEvent($merchantSk, $payerPk, $reqId, $body);
        $conn->send(new Text(json_encode(['EVENT', $sub, $reply])));
        return;
    }
    if ($data[0] === 'CLOSE') {
        // Client done; close the connection so the test subprocess can exit.
        $conn->close();
    }
});

function makeMerchantEvent(string $merchantSk, string $payerPk, string $reqId, array $body): array
{
    $ck = Nip44::getConversationKey($merchantSk, $payerPk);
    $ct = Nip44::encrypt(json_encode($body), $ck);
    $e = new Event();
    $e->setKind(21001);
    $e->setTags([['p', $payerPk], ['e', $reqId]]);
    $e->setContent($ct);
    (new Sign())->signEvent($e, $merchantSk);
    return $e->toArray();
}

$server->start();
