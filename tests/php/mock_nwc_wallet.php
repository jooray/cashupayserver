<?php
/**
 * Mock NWC wallet service for tests. Acts as both relay AND wallet (NIP-47):
 * answers the client's kind-13194 info REQ, accepts the encrypted kind-23194
 * request EVENT, decrypts it with the wallet key (NIP-04 or NIP-44, mirroring
 * whichever scheme the client used), and replies with an encrypted kind-23195
 * response for make_invoice / lookup_invoice / get_info.
 *
 * Driven by env vars so the test can script behaviour:
 *   MOCK_NWC_PORT         TCP port to listen on
 *   MOCK_NWC_WALLET_SK    wallet (service) private key hex
 *   MOCK_NWC_ENCRYPTION   advertised schemes in the 13194 `encryption` tag,
 *                         e.g. "nip44_v2 nip04" or "nip04"; empty = no tag
 *                         (legacy wallet, nip04 implied)
 *   MOCK_NWC_NO_INFO      if "1", never publish the info event (client must
 *                         fall back to nip04)
 *   MOCK_NWC_BOLT11       force this bolt11 in make_invoice replies; default
 *                         synthesizes one whose encoded amount matches the
 *                         requested msats (lnbc{sats*10}n1mock…)
 *   MOCK_NWC_STATE        lookup_invoice behaviour: "settled" (state=settled +
 *                         preimage + settled_at), "pending", "legacy_settled"
 *                         (no state field — only preimage/settled_at, tests the
 *                         pre-`state` wallet fallback), "notfound" (NOT_FOUND
 *                         error). Default "pending".
 *   MOCK_NWC_PREIMAGE     preimage hex for settled lookups
 *   MOCK_NWC_ERROR_CODE   if set, every make_invoice/lookup_invoice reply is
 *                         this NIP-47 error code (e.g. RESTRICTED)
 *   MOCK_NWC_METHODS      space-separated methods for get_info's result; empty
 *                         = get_info answers RESTRICTED (connection can't
 *                         introspect itself)
 *   MOCK_NWC_SILENT       if "1", swallow requests without replying (timeout
 *                         path)
 *   MOCK_NWC_BAD_SIG      if "1", replies carry a mangled Schnorr signature
 *                         (impostor simulation — the client must ignore them)
 *   MOCK_NWC_DUMP         if set, append each decrypted request payload as a
 *                         JSON line to this path (lets a test assert methods,
 *                         params, and encryption actually sent)
 *
 * Not a general-purpose relay — just enough NIP-01 framing to exercise the
 * client's info-fetch + subscribe-before-publish round trip.
 */

declare(strict_types=1);

require __DIR__ . '/../../app/vendor/autoload.php';

use swentel\nostr\Key\Key;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Encryption\Nip04;
use swentel\nostr\Encryption\Nip44;
use WebSocket\Server;
use WebSocket\Message\Text;

$port = (int)(getenv('MOCK_NWC_PORT') ?: 0);
$walletSk = (string)getenv('MOCK_NWC_WALLET_SK');

if ($port <= 0 || $walletSk === '') {
    fwrite(STDERR, "mock_nwc_wallet: MOCK_NWC_PORT and MOCK_NWC_WALLET_SK required\n");
    exit(2);
}

$key = new Key();
$walletPk = $key->getPublicKey($walletSk);

$server = new Server($port, false);

$server->onText(function (Server $srv, $conn, Text $message) use ($walletSk, $walletPk) {
    $data = json_decode($message->getContent(), true);
    if (!is_array($data) || !isset($data[0])) {
        return;
    }

    if ($data[0] === 'REQ') {
        $sub = (string)($data[1] ?? 'sub');
        $filter = $data[2] ?? [];
        $kinds = $filter['kinds'] ?? [];
        if (in_array(13194, $kinds, true)) {
            // Info-event fetch: advertise capabilities + encryption support.
            if (getenv('MOCK_NWC_NO_INFO') !== '1') {
                $tags = [];
                $enc = (string)(getenv('MOCK_NWC_ENCRYPTION') ?: '');
                if ($enc !== '') {
                    $tags[] = ['encryption', $enc];
                }
                $info = new Event();
                $info->setKind(13194);
                $info->setTags($tags);
                $info->setContent('make_invoice lookup_invoice get_info');
                (new Sign())->signEvent($info, $walletSk);
                $conn->send(new Text(json_encode(['EVENT', $sub, $info->toArray()])));
            }
        } else {
            // Response subscription — remember it for the reply.
            $conn->setMeta('sub', $sub);
        }
        $conn->send(new Text(json_encode(['EOSE', $sub])));
        return;
    }

    if ($data[0] === 'EVENT' && isset($data[1]) && is_array($data[1])) {
        $reqEvent = $data[1];
        $clientPk = (string)($reqEvent['pubkey'] ?? '');
        $reqId = (string)($reqEvent['id'] ?? '');
        $sub = $conn->getMeta('sub') ?? 'sub';
        $content = (string)($reqEvent['content'] ?? '');

        $conn->send(new Text(json_encode(['OK', $reqId, true, ''])));

        if (getenv('MOCK_NWC_SILENT') === '1') {
            return; // accept but never answer — client must time out
        }

        // Decrypt with whichever scheme the client used (NIP-04 ciphertext is
        // recognizable by its "?iv=" suffix) and mirror it in the reply.
        $usedNip04 = str_contains($content, '?iv=');
        try {
            if ($usedNip04) {
                $plain = Nip04::decrypt($content, $walletSk, $clientPk);
            } else {
                $ck = Nip44::getConversationKey($walletSk, $clientPk);
                $plain = Nip44::decrypt($content, $ck);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "mock_nwc_wallet: decrypt failed: {$e->getMessage()}\n");
            return;
        }
        $request = json_decode($plain, true) ?: [];
        $method = (string)($request['method'] ?? '');
        $params = $request['params'] ?? [];

        $dump = getenv('MOCK_NWC_DUMP');
        if ($dump !== false && $dump !== '') {
            file_put_contents(
                $dump,
                json_encode(['scheme' => $usedNip04 ? 'nip04' : 'nip44', 'payload' => $request]) . "\n",
                FILE_APPEND
            );
        }

        $body = buildResponseBody($method, is_array($params) ? $params : []);
        $reply = new Event();
        $reply->setKind(23195);
        $reply->setTags([['p', $clientPk], ['e', $reqId]]);
        if ($usedNip04) {
            $reply->setContent(Nip04::encrypt(json_encode($body), $walletSk, $clientPk));
        } else {
            $ck = Nip44::getConversationKey($walletSk, $clientPk);
            $reply->setContent(Nip44::encrypt(json_encode($body), $ck));
        }
        (new Sign())->signEvent($reply, $walletSk);
        $replyArr = $reply->toArray();
        if (getenv('MOCK_NWC_BAD_SIG') === '1') {
            // Impostor mode: correctly encrypted content (the conversation key
            // is real) but a mangled Schnorr signature — the client's reply
            // verification must drop this event.
            $replyArr['sig'] = str_repeat('0', 16) . substr($replyArr['sig'], 16);
        }
        $conn->send(new Text(json_encode(['EVENT', $sub, $replyArr])));
        return;
    }

    if ($data[0] === 'CLOSE') {
        // The client CLOSEs each finished subscription; only drop the
        // connection when it matches the live response sub (the info-fetch
        // CLOSE arrives mid-round-trip and must not kill the socket).
        if (($data[1] ?? null) === ($conn->getMeta('sub') ?? null)) {
            $conn->close();
        }
    }
});

/** NIP-47 response body for a decrypted request. */
function buildResponseBody(string $method, array $params): array
{
    $errorCode = getenv('MOCK_NWC_ERROR_CODE');
    if (($method === 'make_invoice' || $method === 'lookup_invoice')
            && $errorCode !== false && $errorCode !== '') {
        return [
            'result_type' => $method,
            'error' => ['code' => (string)$errorCode, 'message' => 'mock error'],
            'result' => null,
        ];
    }

    if ($method === 'make_invoice') {
        $msats = (int)($params['amount'] ?? 0);
        $bolt11 = (string)(getenv('MOCK_NWC_BOLT11') ?: '');
        if ($bolt11 === '') {
            // Synthesize an invoice whose encoded amount matches the request:
            // N sats = N*10 nano-BTC.
            $bolt11 = 'lnbc' . (intdiv($msats, 1000) * 10) . 'n1mockinvoice0000000';
        }
        return [
            'result_type' => 'make_invoice',
            'error' => null,
            'result' => [
                'type' => 'incoming',
                'state' => 'pending',
                'invoice' => $bolt11,
                'payment_hash' => hash('sha256', $bolt11),
                'amount' => $msats,
                'created_at' => time(),
                'expires_at' => time() + (int)($params['expiry'] ?? 3600),
            ],
        ];
    }

    if ($method === 'lookup_invoice') {
        $state = (string)(getenv('MOCK_NWC_STATE') ?: 'pending');
        $preimage = (string)(getenv('MOCK_NWC_PREIMAGE') ?: str_repeat('ab', 32));
        $hash = (string)($params['payment_hash'] ?? '');
        if ($state === 'notfound') {
            return [
                'result_type' => 'lookup_invoice',
                'error' => ['code' => 'NOT_FOUND', 'message' => 'no such invoice'],
                'result' => null,
            ];
        }
        $result = [
            'type' => 'incoming',
            'payment_hash' => $hash,
            'amount' => 1000,
            'created_at' => time() - 10,
        ];
        if ($state === 'settled') {
            $result['state'] = 'settled';
            $result['preimage'] = $preimage;
            $result['settled_at'] = time();
        } elseif ($state === 'legacy_settled') {
            // Pre-`state` wallet: settlement only visible via preimage/settled_at.
            $result['preimage'] = $preimage;
            $result['settled_at'] = time();
        } else {
            $result['state'] = 'pending';
        }
        return ['result_type' => 'lookup_invoice', 'error' => null, 'result' => $result];
    }

    if ($method === 'get_info') {
        $methods = trim((string)(getenv('MOCK_NWC_METHODS') ?: ''));
        if ($methods === '') {
            return [
                'result_type' => 'get_info',
                'error' => ['code' => 'RESTRICTED', 'message' => 'not permitted'],
                'result' => null,
            ];
        }
        return [
            'result_type' => 'get_info',
            'error' => null,
            'result' => [
                'alias' => 'mock-wallet',
                'network' => 'mainnet',
                'methods' => explode(' ', $methods),
            ],
        ];
    }

    return [
        'result_type' => $method,
        'error' => ['code' => 'NOT_IMPLEMENTED', 'message' => "mock: unknown method {$method}"],
        'result' => null,
    ];
}

$server->start();
