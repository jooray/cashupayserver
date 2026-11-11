<?php
/**
 * CashuPayServer — NWC client (NIP-47 over Nostr)
 *
 * Speaks the *client* side of Nostr Wallet Connect against the merchant's
 * wallet service: given a connection URI we ask the wallet to mint a BOLT11
 * (`make_invoice`) and later confirm settlement (`lookup_invoice`). Pure PHP
 * (no exec, no ext-sodium) on top of swentel/nostr-php's event/relay layer +
 * the same websocket client the CLINK payer uses, with the crypto (BIP340
 * signing/verification, NIP-04/NIP-44 keys) done by the in-repo NostrCrypto
 * stack so it runs on ext-gmp or ext-bcmath. Same shared-hosting constraint:
 * every call is one short open→request→reply→close round trip, no resident
 * process.
 *
 * This client deliberately knows only three methods:
 *
 *   make_invoice    — invoice creation (receive) and auto-cashout (the melt
 *                     path pays the returned BOLT11 from the mint balance).
 *   lookup_invoice  — settlement checks from the payment-page poll and cron.
 *   get_info        — save-time probe only, to warn when a connection was
 *                     granted spend permissions it doesn't need here.
 *
 * Nothing in this class can move funds out of the merchant's wallet; the
 * spend-capable NIP-47 methods (pay_invoice etc.) are never sent.
 *
 * Encryption: NIP-04 is the NIP-47 baseline; when the wallet's kind-13194
 * info event advertises nip44_v2 in its `encryption` tag we use NIP-44 and
 * tag the request accordingly. The connection's `secret` is our long-lived
 * nostr identity for this wallet (unlike CLINK's throwaway keys), so the URI
 * must never leak — use NwcUri::displayLabel() for anything user-facing.
 */

declare(strict_types=1);

require_once __DIR__ . '/uri.php';
require_once __DIR__ . '/../crypto/nostr_crypto.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use swentel\nostr\Event\Event;
use swentel\nostr\Encryption\Nip44;

/** Structured NWC failure carrying the NIP-47 error code where known. */
class NwcException extends \RuntimeException
{
    /** NIP-47 codes: UNAUTHORIZED, RESTRICTED, NOT_FOUND, RATE_LIMITED, INTERNAL, … '' = local/transport. */
    public string $nwcCode;

    public function __construct(string $message, string $nwcCode = '')
    {
        parent::__construct($message);
        $this->nwcCode = $nwcCode;
    }
}

class NwcClient
{
    public const KIND_INFO = 13194;
    public const KIND_REQUEST = 23194;
    public const KIND_RESPONSE = 23195;

    /** NIP-47 methods that let a connection move funds OUT of the wallet. */
    public const SPEND_METHODS = [
        'pay_invoice', 'multi_pay_invoice', 'pay_keysend', 'multi_pay_keysend',
    ];

    // Total wall-clock budget for one request→reply round trip (relay connect,
    // info-event fetch, wallet's invoice work). The budget covers the whole
    // connection URI: every relay in it draws from the same deadline, so a
    // multi-relay URI can't multiply the wait when the wallet is offline.
    // Override in user_config.php.
    public const DEFAULT_TIMEOUT_SEC = 10;

    // Don't open yet another relay socket when less than this much of the
    // budget is left — the round trip could never complete in time.
    private const MIN_ATTEMPT_SEC = 0.5;

    /**
     * NWC signs kind-23194 events and derives NIP-04/NIP-44 keys, which
     * needs bignum math — ext-gmp or ext-bcmath (the in-repo NostrCrypto
     * stack runs on either), the same constraint as the CLINK noffer stack.
     * Checked up front by the onboarding wizard and admin settings so the
     * operator gets one actionable sentence instead of a per-invoice
     * error_log line. Returns null when NWC can run here. See
     * environmentNotice() for the non-blocking "BCMath fallback active"
     * advisory.
     */
    public static function environmentError(): ?string
    {
        if (PHP_INT_SIZE < 8) {
            return 'This server runs 32-bit PHP; NWC connections require 64-bit PHP.';
        }
        if (!NostrCrypto::available()) {
            return 'This server\'s PHP has neither the GMP nor the BCMath extension; one'
                . ' of them is required to sign the Nostr requests NWC (Nostr Wallet'
                . ' Connect) works over. Ask your hosting provider to enable php-gmp'
                . ' (preferred) or php-bcmath, or use a Lightning address instead.';
        }
        return null;
    }

    /**
     * Non-blocking advisory when NWC runs on the BCMath fallback: it works,
     * but GMP is much faster and the better-audited bignum. Null when GMP is
     * active or when the feature can't run at all.
     */
    public static function environmentNotice(): ?string
    {
        if (self::environmentError() !== null || !NostrCrypto::usingBcmathFallback()) {
            return null;
        }
        return 'NWC is running on PHP\'s BCMath extension. This works, but enabling'
            . ' the GMP extension (php-gmp) makes the cryptography roughly 100x faster'
            . ' and uses the more battle-tested math library — worth asking your'
            . ' hosting provider for.';
    }

    /**
     * Ask the wallet to mint a BOLT11 for exactly $amountSats.
     *
     * The returned invoice's encoded amount is verified against the request
     * before anything is persisted or paid — a wallet (or impostor on the
     * relay) returning a different amount throws rather than reaching the
     * customer or the melt path.
     *
     * @return array{bolt11:string, payment_hash:string, pubkey:string, relay:string}
     * @throws NwcException on decode/transport/protocol/amount error.
     */
    public static function makeInvoice(
        string $uri,
        int $amountSats,
        ?string $description = null,
        ?int $expirySec = null,
        ?int $timeoutSec = null
    ): array {
        if ($amountSats <= 0) {
            throw new NwcException("Invalid NWC invoice amount: {$amountSats}");
        }
        $params = ['amount' => $amountSats * 1000]; // NIP-47 amounts are msats
        if ($description !== null && $description !== '') {
            $params['description'] = $description;
        }
        if ($expirySec !== null && $expirySec > 0) {
            $params['expiry'] = $expirySec;
        }

        $reply = self::request($uri, 'make_invoice', $params, $timeoutSec);
        $result = $reply['result'];
        $bolt11 = (string)($result['invoice'] ?? '');
        $paymentHash = strtolower((string)($result['payment_hash'] ?? ''));
        if ($bolt11 === '') {
            throw new NwcException('NWC wallet returned no invoice');
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $paymentHash)) {
            throw new NwcException('NWC wallet returned no payment_hash');
        }
        $encodedSats = self::parseBolt11AmountSats($bolt11);
        if ($encodedSats !== $amountSats) {
            throw new NwcException(
                "NWC wallet returned an invoice for {$encodedSats} sats, expected {$amountSats}"
            );
        }
        return [
            'bolt11' => $bolt11,
            'payment_hash' => $paymentHash,
            'pubkey' => $reply['pubkey'],
            'relay' => $reply['relay'],
        ];
    }

    /**
     * Check whether an invoice we minted earlier has settled.
     *
     * found=false means the wallet answered NOT_FOUND (it no longer knows the
     * invoice) — callers treat that as still-pending rather than an error, so
     * a wallet that prunes expired invoices doesn't spam the logs. paid=true
     * only on the wallet's explicit say-so: state === 'settled', or (older
     * wallets that predate `state`) a non-empty preimage / settled_at.
     *
     * @return array{found:bool, paid:bool, preimage:?string, state:?string}
     * @throws NwcException on transport/protocol error (NOT on NOT_FOUND).
     */
    public static function lookupInvoice(string $uri, string $paymentHash, ?int $timeoutSec = null): array
    {
        try {
            $reply = self::request($uri, 'lookup_invoice', ['payment_hash' => $paymentHash], $timeoutSec);
        } catch (NwcException $e) {
            if ($e->nwcCode === 'NOT_FOUND') {
                return ['found' => false, 'paid' => false, 'preimage' => null, 'state' => null];
            }
            throw $e;
        }
        $result = $reply['result'];
        $state = isset($result['state']) ? strtolower((string)$result['state']) : null;
        $preimage = isset($result['preimage']) && is_string($result['preimage']) && $result['preimage'] !== ''
            ? $result['preimage'] : null;
        if ($state !== null) {
            $paid = $state === 'settled';
        } else {
            // Pre-`state` wallets: settled_at / preimage are only present once paid.
            $paid = $preimage !== null || !empty($result['settled_at']);
        }
        return ['found' => true, 'paid' => $paid, 'preimage' => $preimage, 'state' => $state];
    }

    /**
     * Save-time gate for a newly entered connection string: prove the two
     * capabilities this feature needs by exercising them for real — mint a
     * 1-sat test invoice (short expiry, never paid) and look it up. Then a
     * best-effort get_info: where the wallet reports per-connection methods
     * (e.g. Alby Hub) and they include a spend method, surface a warning —
     * the operator should use a receive-only connection. get_info being
     * denied or unsupported is NOT an error and produces no warning.
     *
     * @return array{ok:bool, error:?string, warning:?string}
     */
    public static function probeConnection(string $uri, ?int $timeoutSec = null): array
    {
        try {
            $made = self::makeInvoice($uri, 1, 'NWC connection test', 120, $timeoutSec);
            $looked = self::lookupInvoice($uri, $made['payment_hash'], $timeoutSec);
            if (!$looked['found']) {
                return [
                    'ok' => false,
                    'error' => 'The wallet minted a test invoice but could not look it up again '
                        . '(lookup_invoice returned NOT_FOUND); payments could never be confirmed.',
                    'warning' => null,
                ];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'warning' => null];
        }

        $warning = null;
        try {
            $info = self::request($uri, 'get_info', new \stdClass(), $timeoutSec);
            $methods = $info['result']['methods'] ?? null;
            if (is_array($methods)) {
                $granted = array_intersect(self::SPEND_METHODS, array_map('strval', $methods));
                if ($granted !== []) {
                    $warning = 'This connection can also SPEND from the wallet ('
                        . implode(', ', $granted) . '). BareBits only ever creates and checks '
                        . 'invoices — for safety, issue a receive-only connection '
                        . '(make_invoice + lookup_invoice) in your wallet and use that instead.';
                }
            }
        } catch (\Throwable $e) {
            // Best-effort: a restricted connection often can't get_info at all.
        }
        return ['ok' => true, 'error' => null, 'warning' => $warning];
    }

    // ---- internals ----

    /**
     * One full NIP-47 round trip: connect to the first reachable relay,
     * discover the wallet's encryption support (kind 13194), subscribe for
     * the kind-23195 response, publish the encrypted kind-23194 request, and
     * return the decrypted reply.
     *
     * @param array|object $params method params ({} must serialize as an object)
     * @return array{result:array, pubkey:string, relay:string}
     * @throws NwcException
     */
    private static function request(string $uri, string $method, $params, ?int $timeoutSec = null): array
    {
        if (($envError = self::environmentError()) !== null) {
            throw new NwcException($envError);
        }
        $parsed = NwcUri::parse($uri); // throws on malformed
        $timeout = $timeoutSec ?? self::timeoutSec();
        // One deadline for the whole destination, started before the first
        // connect: connect time and every relay in the URI spend the same
        // budget, so the caller's timeout bounds real wall clock.
        $deadline = microtime(true) + $timeout;

        $lastError = null;
        foreach ($parsed['relays'] as $relayUrl) {
            if ($lastError !== null && self::remaining($deadline) < self::MIN_ATTEMPT_SEC) {
                break; // budget spent; another connect could never finish
            }
            try {
                return self::requestViaRelay($relayUrl, $parsed, $method, $params, $timeout, $deadline);
            } catch (NwcException $e) {
                // A definitive wallet-side error is the answer regardless of
                // which relay carried it; only transport-ish failures ("no
                // response", connect errors) justify trying the next relay.
                if ($e->nwcCode !== '') {
                    throw $e;
                }
                $lastError = $e;
            }
        }
        throw $lastError ?? new NwcException('No NWC relay reachable');
    }

    /** @param array|object $params */
    private static function requestViaRelay(
        string $relayUrl,
        array $parsed,
        string $method,
        $params,
        int $timeout,
        float $deadline
    ): array {
        $skHex = $parsed['secret'];
        $walletPubkey = $parsed['pubkey'];
        $clientPubkey = NostrCrypto::derivePublicKeyHex($skHex);

        $client = null;
        $subId = bin2hex(random_bytes(8));
        try {
            $client = new \WebSocket\Client($relayUrl);
            // The connect (TCP + websocket upgrade) spends budget too — a
            // black-holing relay host can't stretch the wait past the deadline.
            $client->setTimeout(max(0.1, self::remaining($deadline)));
            $client->connect();

            $useNip44 = self::walletSupportsNip44($client, $walletPubkey, $deadline);

            // Build + encrypt the request.
            $payload = json_encode(['method' => $method, 'params' => $params]);
            if ($useNip44) {
                $convKey = NostrCrypto::nip44ConversationKey($skHex, $walletPubkey);
                $content = Nip44::encrypt($payload, $convKey);
            } else {
                $convKey = null;
                $content = NostrCrypto::nip04Encrypt($payload, $skHex, $walletPubkey);
            }

            $event = new Event();
            $event->setKind(self::KIND_REQUEST);
            $tags = [['p', $walletPubkey]];
            if ($useNip44) {
                $tags[] = ['encryption', 'nip44_v2'];
            }
            // Stale requests should die at the relay rather than surprise the
            // wallet after we've stopped listening (NIP-40 expiration).
            $tags[] = ['expiration', (string)(time() + $timeout + 30)];
            $event->setTags($tags);
            $event->setContent($content);
            NostrCrypto::signEvent($event, $skHex);
            $signed = $event->toArray();
            $requestId = (string)$signed['id'];
            $createdAt = (int)$signed['created_at'];

            // Subscribe-before-publish on the one socket: the response is an
            // expiring event emitted right after the wallet sees our request,
            // so the subscription must already be live or we'd miss it.
            $filter = [
                'kinds' => [self::KIND_RESPONSE],
                'authors' => [$walletPubkey],
                '#e' => [$requestId],
                'since' => max(0, $createdAt - 1),
            ];
            // The info probe may have shrunk the socket timeout to its last
            // sliver; give the publish writes the full remaining budget.
            $client->setTimeout(max(0.1, self::remaining($deadline)));
            $client->text(json_encode(['REQ', $subId, $filter]));
            $client->text(json_encode(['EVENT', $signed]));

            while (($left = self::remaining($deadline)) > 0) {
                // Shrink the socket timeout to what's left of the budget: the
                // deadline check alone can't stop a blocking read entered just
                // before it from overrunning by a further full socket timeout.
                $client->setTimeout(max(0.05, $left));
                $msg = self::receiveText($client);
                if ($msg === null) {
                    break; // timeout / closed
                }
                $data = json_decode($msg, true);
                if (!is_array($data) || !isset($data[0])) {
                    continue;
                }
                if ($data[0] === 'EVENT' && ($data[1] ?? null) === $subId && isset($data[2]) && is_array($data[2])) {
                    $ev = $data[2];
                    // Only the wallet may answer; the filter asks for this but
                    // the relay is untrusted.
                    if (!hash_equals($walletPubkey, (string)($ev['pubkey'] ?? ''))) {
                        continue;
                    }
                    // Defense in depth: require a valid Schnorr signature by
                    // the wallet key before trusting the content. Conversation
                    // -key secrecy already authenticates the ciphertext, but a
                    // signature check means an impostor needs to break BOTH
                    // the ECDH layer and BIP340 to speak for the wallet.
                    if (!NostrCrypto::verifyEventArray($ev)) {
                        continue;
                    }
                    $plain = self::decryptResponse((string)($ev['content'] ?? ''), $skHex, $walletPubkey, $convKey);
                    if ($plain === null) {
                        continue;
                    }
                    $reply = json_decode($plain, true);
                    if (!is_array($reply)) {
                        continue;
                    }
                    if (isset($reply['error']) && is_array($reply['error']) && ($reply['error']['code'] ?? '') !== '') {
                        throw new NwcException(
                            'NWC wallet error: ' . (string)($reply['error']['message'] ?? $reply['error']['code']),
                            (string)$reply['error']['code']
                        );
                    }
                    $result = $reply['result'] ?? null;
                    if (!is_array($result)) {
                        throw new NwcException('NWC wallet returned an empty result');
                    }
                    return ['result' => $result, 'pubkey' => $walletPubkey, 'relay' => $relayUrl];
                } elseif ($data[0] === 'CLOSED' && ($data[1] ?? null) === $subId) {
                    break;
                }
                // EOSE / OK / NOTICE: keep waiting for the wallet's response.
            }
            throw new NwcException("No response from NWC wallet within {$timeout}s");
        } catch (NwcException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new NwcException('NWC relay error: ' . $e->getMessage());
        } finally {
            self::closeQuietly($client, $subId);
        }
    }

    /**
     * Read the wallet's replaceable kind-13194 info event off the already-open
     * socket and decide the encryption scheme: nip44_v2 when advertised in the
     * `encryption` tag, else the NIP-04 baseline (absence of the tag means the
     * wallet predates negotiation). A missing info event (relay doesn't store
     * it) also falls back to NIP-04. Bounded by its own small slice of the
     * overall deadline so a silent relay can't eat the whole request budget.
     */
    private static function walletSupportsNip44(\WebSocket\Client $client, string $walletPubkey, float $deadline): bool
    {
        $subId = bin2hex(random_bytes(8));
        $infoDeadline = min($deadline, microtime(true) + 3.0);
        $supports = false;
        try {
            $client->text(json_encode(['REQ', $subId, [
                'kinds' => [self::KIND_INFO],
                'authors' => [$walletPubkey],
                'limit' => 1,
            ]]));
            while (($left = $infoDeadline - microtime(true)) > 0) {
                // Same shrink as the response loop: keep a silent relay from
                // blocking a read past the probe's slice of the budget.
                $client->setTimeout(max(0.05, $left));
                $msg = self::receiveText($client);
                if ($msg === null) {
                    break;
                }
                $data = json_decode($msg, true);
                if (!is_array($data) || !isset($data[0])) {
                    continue;
                }
                if ($data[0] === 'EVENT' && ($data[1] ?? null) === $subId && isset($data[2]) && is_array($data[2])) {
                    foreach (($data[2]['tags'] ?? []) as $tag) {
                        if (is_array($tag) && ($tag[0] ?? null) === 'encryption'
                            && str_contains((string)($tag[1] ?? ''), 'nip44_v2')) {
                            $supports = true;
                        }
                    }
                    break; // limit:1 — first event is the answer
                } elseif ($data[0] === 'EOSE' && ($data[1] ?? null) === $subId) {
                    break; // relay has no stored info event
                } elseif ($data[0] === 'CLOSED' && ($data[1] ?? null) === $subId) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to the baseline on any hiccup.
        }
        try {
            $client->text(json_encode(['CLOSE', $subId]));
        } catch (\Throwable $e) {
            // ignore
        }
        return $supports;
    }

    /**
     * Decrypt a kind-23195 payload, tolerating wallets that answer in the
     * other scheme than the one we negotiated (seen in the wild during
     * wallets' nip44 rollouts). Returns null when neither scheme works —
     * the caller skips the event rather than failing the whole request.
     */
    private static function decryptResponse(string $content, string $skHex, string $walletPubkey, ?string $nip44ConvKey): ?string
    {
        if ($content === '') {
            return null;
        }
        // NIP-04 ciphertext is "<base64>?iv=<base64>"; NIP-44 is bare base64.
        $schemes = str_contains($content, '?iv=') ? ['nip04', 'nip44'] : ['nip44', 'nip04'];
        foreach ($schemes as $scheme) {
            try {
                if ($scheme === 'nip04') {
                    return NostrCrypto::nip04Decrypt($content, $skHex, $walletPubkey);
                }
                $convKey = $nip44ConvKey ?? NostrCrypto::nip44ConversationKey($skHex, $walletPubkey);
                return Nip44::decrypt($content, $convKey);
            } catch (\Throwable $e) {
                // try the other scheme
            }
        }
        return null;
    }

    /**
     * Amount encoded in a BOLT11 invoice, in sats (0 = amountless invoice).
     * Kept in sync with LightningAddress::parseBolt11Amount — duplicated here
     * (small, pure) so includes/nwc stays as self-contained as includes/clink
     * and doesn't drag the wallet/mint stack into the settings validators.
     */
    private static function parseBolt11AmountSats(string $bolt11): int
    {
        $bolt11 = strtolower(trim($bolt11));
        if (!preg_match('/^ln(?:bc|tbs|tb|bcrt)(\d+)?([munp]?)1/', $bolt11, $m)) {
            return 0;
        }
        if (!isset($m[1]) || $m[1] === '') {
            return 0; // amountless
        }
        $amount = (int)$m[1];
        switch ($m[2] ?? '') {
            case '':
                return $amount * 100000000;      // whole BTC
            case 'm':
                return $amount * 100000;         // milli-BTC
            case 'u':
                return $amount * 100;            // micro-BTC
            case 'n':
                return (int)ceil($amount / 10);  // nano-BTC (0.1 sat)
            case 'p':
                return (int)ceil($amount / 10000); // pico-BTC (0.0001 sat)
        }
        return 0;
    }

    /** Receive the next text frame's content, or null on timeout/close; '' for control noise. */
    private static function receiveText(\WebSocket\Client $client): ?string
    {
        try {
            $msg = $client->receive();
        } catch (\Throwable $e) {
            return null; // timeout or closed
        }
        if ($msg === null) {
            return null;
        }
        if (method_exists($msg, 'getOpcode') && $msg->getOpcode() !== 'text') {
            return ''; // control frame (ping/pong); caller keeps looping
        }
        return $msg->getContent();
    }

    private static function closeQuietly(?\WebSocket\Client $client, string $subId): void
    {
        if ($client === null) {
            return;
        }
        try {
            if ($client->isConnected()) {
                $client->text(json_encode(['CLOSE', $subId]));
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private static function timeoutSec(): int
    {
        if (defined('NWC_TIMEOUT_SEC')) {
            return (int)NWC_TIMEOUT_SEC;
        }
        return self::DEFAULT_TIMEOUT_SEC;
    }

    /** Seconds left before $deadline, floored at 0. */
    private static function remaining(float $deadline): float
    {
        return max(0.0, $deadline - microtime(true));
    }
}
