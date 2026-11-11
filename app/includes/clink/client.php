<?php
/**
 * CashuPayServer — CLINK noffer client (NIP-69 over Nostr)
 *
 * Implements the *payer* side of CLINK: given a merchant's `noffer` string we
 * ask their Nostr service for a BOLT11 invoice, and later confirm the customer
 * paid by reading the merchant's kind-21001 payment receipt.
 *
 * Two distinct jobs, both pure-PHP (no exec, no ext-sodium) on top of
 * swentel/nostr-php's event/relay layer (a pure-PHP websocket client) with
 * the crypto — BIP340 Schnorr, x-only ECDH, NIP-44 keys — done by the in-repo
 * NostrCrypto/BigNum stack, which runs on ext-gmp or ext-bcmath (nostr-php's
 * own crypto hard-requires GMP, which shared WordPress hosts often lack):
 *
 *   requestInvoice()  — used at invoice creation (receive) and at cashout
 *                       (withdraw). Generates a throwaway identity, sends an
 *                       encrypted kind-21001 request to the offer's relay, and
 *                       returns the BOLT11 the merchant service mints back.
 *
 *   fetchReceipt()    — used by cron (best-effort) and verifyReceiptEvent() by
 *                       the payment page (primary). Confirms settlement via the
 *                       merchant's kind-21001 `{res:'ok'}` receipt, validated by
 *                       the merchant's signature.
 *
 * Why ephemeral keys: CLINK requests carry no long-lived identity, and the
 * receipt is NIP-44-encrypted to the key that made the request. We persist the
 * throwaway secret on the invoice so both the page and cron can decrypt later.
 *
 * Receipt-retention caveat: kind 21001 is in Nostr's ephemeral range
 * (20000–29999); relays are "not expected to store" these (NIP-01). The payment
 * page holds a live subscription and is the reliable path; cron re-subscribing
 * later is best-effort and only works on relays that retain ephemeral events.
 */

declare(strict_types=1);

require_once __DIR__ . '/noffer.php';
require_once __DIR__ . '/../crypto/nostr_crypto.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use swentel\nostr\Event\Event;
use swentel\nostr\Encryption\Nip44;

/** Structured CLINK failure carrying a NIP-69 error code where known. */
class ClinkException extends \RuntimeException
{
    /** NIP-69 codes: 1 invalid offer, 2 temp fail, 3 expired, 4 unsupported, 5 invalid amount. 0 = local/transport. */
    public int $clinkCode;

    public function __construct(string $message, int $clinkCode = 0)
    {
        parent::__construct($message);
        $this->clinkCode = $clinkCode;
    }
}

class ClinkClient
{
    public const KIND = 21001;

    /**
     * Why this exists: the CLINK payer path Schnorr-signs Nostr events and
     * derives NIP-44 conversation keys, which needs bignum math — ext-gmp or
     * ext-bcmath (the in-repo NostrCrypto stack runs on either). Shared
     * WordPress hosts frequently ship PHP without GMP, and before this check
     * that surfaced only as a per-invoice error_log line while the checkout
     * silently lost its Lightning option. The mirror of
     * OnchainWallet::environmentError() for the noffer stack: checked up
     * front by the onboarding wizard and the admin settings so the operator
     * gets one actionable sentence instead.
     *
     * Returns null when the environment can run the CLINK client. See
     * environmentNotice() for the non-blocking "BCMath fallback active"
     * advisory.
     */
    public static function environmentError(): ?string
    {
        if (PHP_INT_SIZE < 8) {
            return 'This server runs 32-bit PHP; CLINK noffers require 64-bit PHP.';
        }
        if (!NostrCrypto::available()) {
            return 'This server\'s PHP has neither the GMP nor the BCMath extension; one'
                . ' of them is required to sign the Nostr payment requests CLINK noffers'
                . ' work over. Ask your hosting provider to enable php-gmp (preferred)'
                . ' or php-bcmath, or use a Lightning address instead.';
        }
        return null;
    }

    /**
     * Non-blocking advisory when noffers run on the BCMath fallback: they
     * work, but GMP is much faster and the better-audited bignum library.
     * Null when GMP is active or when the feature can't run at all.
     */
    public static function environmentNotice(): ?string
    {
        if (self::environmentError() !== null || !NostrCrypto::usingBcmathFallback()) {
            return null;
        }
        return 'CLINK noffers are running on PHP\'s BCMath extension. This works, but'
            . ' enabling the GMP extension (php-gmp) makes the cryptography roughly'
            . ' 100x faster and uses the more battle-tested math library — worth'
            . ' asking your hosting provider for.';
    }

    // Total wall-clock budget for a request→reply round trip. Relays + the
    // merchant's invoice generation are the slow parts; keep it snappy enough
    // not to wedge invoice creation but tolerant of a sluggish relay. The
    // budget covers the whole offer: every relay in the noffer draws from the
    // same deadline, so extra relays add reach, not checkout wait. Override
    // in user_config.php.
    public const DEFAULT_TIMEOUT_SEC = 10;

    // Don't open yet another relay socket when less than this much of the
    // budget is left — the round trip could never complete in time.
    private const MIN_ATTEMPT_SEC = 0.5;

    /**
     * Ask a noffer's service for a BOLT11 invoice.
     *
     * @param string   $noffer     the noffer1… code
     * @param int|null $amountSats amount to request (required for spontaneous
     *                             offers; merchants pin it for fixed/variable)
     * @param string|null $description optional payer note (<=100 chars per spec)
     * @param int|null $timeoutSec  override DEFAULT_TIMEOUT_SEC
     *
     * @return array{
     *   bolt11:string, relay:string, receiver_pubkey:string, offer:string,
     *   ephemeral_sk:string, ephemeral_pubkey:string, request_event_id:string,
     *   created_at:int
     * }
     * @throws ClinkException on decode/transport/protocol error.
     */
    public static function requestInvoice(
        string $noffer,
        ?int $amountSats = null,
        ?string $description = null,
        ?int $timeoutSec = null
    ): array {
        // Fail with the actionable environment message rather than the raw
        // Error nostr-php raises from gmp_* two frames deeper.
        if (($envError = self::environmentError()) !== null) {
            throw new ClinkException($envError, 0);
        }
        $decoded = ClinkNoffer::decode($noffer); // throws on malformed
        $timeout = $timeoutSec ?? self::timeoutSec();

        if ($description !== null && mb_strlen($description) > 100) {
            $description = mb_substr($description, 0, 100);
        }

        $skHex = NostrCrypto::generatePrivateKeyHex();
        $pkHex = NostrCrypto::derivePublicKeyHex($skHex);
        $receiverPubkey = $decoded['pubkey'];

        // Encrypted request payload. amount is conditional: the spec carries it
        // for spontaneous offers and ignores it for fixed/variable, so we only
        // include it when we have one.
        $payload = ['offer' => $decoded['offer']];
        if ($amountSats !== null && $amountSats > 0) {
            // The reference SDK sends amount in sats under `amount_sats`; some
            // services also read `amount`. Send both for compatibility.
            $payload['amount_sats'] = $amountSats;
            $payload['amount'] = $amountSats;
        }
        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        $convKey = NostrCrypto::nip44ConversationKey($skHex, $receiverPubkey);
        $content = Nip44::encrypt(json_encode($payload), $convKey);

        $event = new Event();
        $event->setKind(self::KIND);
        $event->setTags([['p', $receiverPubkey], ['clink_version', '1']]);
        $event->setContent($content);
        NostrCrypto::signEvent($event, $skHex);
        $signed = $event->toArray();
        $requestId = (string)$signed['id'];
        $createdAt = (int)$signed['created_at'];

        // Subscribe-before-publish on one socket: the reply is an ephemeral
        // event the service emits *after* seeing our request, so the
        // subscription must already be live or we'd miss it. Walk every relay
        // in the offer under ONE deadline (started before the first connect,
        // so connect time spends budget too): a definitive reply from any
        // relay is the answer; a silent or unreachable relay just hands the
        // remaining budget to the next one.
        $deadline = microtime(true) + $timeout;
        $reply = null;
        $relayUsed = null;
        $lastTransportError = null;
        $sawTimeout = false;
        foreach ($decoded['relays'] as $i => $relayUrl) {
            if ($i > 0 && self::remaining($deadline) < self::MIN_ATTEMPT_SEC) {
                break; // budget spent; another connect could never finish
            }
            try {
                $reply = self::roundTrip($relayUrl, $signed, $pkHex, $requestId, $createdAt, $deadline, $convKey);
            } catch (ClinkException $e) {
                $lastTransportError = $e; // connect/handshake failure — try the next relay
                continue;
            }
            if ($reply !== null) {
                $relayUsed = $relayUrl; // bolt11 or service error — either is definitive
                break;
            }
            $sawTimeout = true; // reachable but silent — try the next relay with what's left
        }
        if ($reply === null) {
            if ($sawTimeout || $lastTransportError === null) {
                throw new ClinkException('No response from noffer service within timeout', 2);
            }
            throw $lastTransportError;
        }

        if (isset($reply['error']) || isset($reply['code'])) {
            $code = isset($reply['code']) ? (int)$reply['code'] : 0;
            $msg = isset($reply['error']) ? (string)$reply['error'] : 'CLINK service error';
            if ($code === 5 && isset($reply['range']) && is_array($reply['range'])) {
                $min = $reply['range']['min'] ?? '?';
                $max = $reply['range']['max'] ?? '?';
                $msg .= " (acceptable range: {$min}–{$max} sats)";
            }
            throw new ClinkException($msg, $code);
        }
        if (!isset($reply['bolt11']) || !is_string($reply['bolt11']) || $reply['bolt11'] === '') {
            throw new ClinkException('CLINK service returned no bolt11', 2);
        }

        return [
            'bolt11' => (string)$reply['bolt11'],
            // The relay that actually answered — the receipt subscription
            // (payment page + cron) should listen where the service is known
            // to be reachable, not blindly on the offer's first relay.
            'relay' => $relayUsed,
            'receiver_pubkey' => $receiverPubkey,
            'offer' => $decoded['offer'],
            'ephemeral_sk' => $skHex,
            'ephemeral_pubkey' => $pkHex,
            'request_event_id' => $requestId,
            'created_at' => $createdAt,
        ];
    }

    /**
     * Re-subscribe to the relay and look for a payment receipt referencing our
     * original request. Returns ['paid'=>bool] — paid=true only when a
     * merchant-signed receipt with {res:'ok'} is found.
     *
     * With $waitAfterEose=false (the cron batch poll) this reads only what the
     * relay retained — nothing, on relays that treat kind 21001 as ephemeral
     * per spec. With $waitAfterEose=true (the payment-page single poll) the
     * subscription stays open until $timeoutSec, so a receipt broadcast live
     * during the window is caught even when the relay retains nothing and the
     * customer's browser subscription is broken.
     *
     * @param array{relay:string,receiver_pubkey:string,ephemeral_sk:string,
     *              ephemeral_pubkey:string,request_event_id:string,created_at:int} $ctx
     */
    public static function fetchReceipt(array $ctx, ?int $timeoutSec = null, bool $waitAfterEose = false): array
    {
        $timeout = $timeoutSec ?? self::timeoutSec();
        $convKey = NostrCrypto::nip44ConversationKey($ctx['ephemeral_sk'], $ctx['receiver_pubkey']);
        $found = null;
        // Interpret events as they arrive so a live-window listen returns the
        // moment the genuine receipt lands instead of idling out the window.
        $stopWhen = function (array $ev) use ($ctx, $convKey, &$found): bool {
            if (self::interpretReceipt($ev, $ctx, $convKey)['paid']) {
                $found = $ev;
                return true;
            }
            return false;
        };
        self::collectEvents(
            $ctx['relay'],
            $ctx['ephemeral_pubkey'],
            $ctx['request_event_id'],
            (int)$ctx['created_at'],
            $timeout,
            $waitAfterEose,
            $stopWhen
        );
        return $found !== null ? ['paid' => true, 'event' => $found] : ['paid' => false];
    }

    /**
     * Primary (payment-page) path: the browser forwards a raw signed event it
     * received on its live subscription; verify it really is a paid receipt for
     * this invoice. All trust comes from the merchant's signature — the browser
     * can relay but cannot forge.
     *
     * @param array $rawEvent decoded nostr event (id,pubkey,kind,tags,content,sig)
     * @param array $ctx      same shape as fetchReceipt()'s $ctx
     */
    public static function verifyReceiptEvent(array $rawEvent, array $ctx): array
    {
        $convKey = NostrCrypto::nip44ConversationKey($ctx['ephemeral_sk'], $ctx['receiver_pubkey']);
        return self::interpretReceipt($rawEvent, $ctx, $convKey);
    }

    // ---- internals ----

    /**
     * Validate + decrypt a candidate receipt event. Returns ['paid'=>bool] plus
     * a 'reason' on rejection so callers can log WHY a forwarded receipt was
     * refused. A genuine receipt must: be kind 21001, be authored by the
     * offer's receiver pubkey, reference our request id via an `e` tag, carry
     * a valid Schnorr signature, and decrypt to a JSON object whose `res` is
     * `ok`.
     */
    private static function interpretReceipt(array $ev, array $ctx, string $convKey): array
    {
        if ((int)($ev['kind'] ?? 0) !== self::KIND) {
            return ['paid' => false, 'reason' => 'wrong kind'];
        }
        if (!hash_equals((string)$ctx['receiver_pubkey'], (string)($ev['pubkey'] ?? ''))) {
            return ['paid' => false, 'reason' => 'author is not the offer receiver'];
        }
        if (!self::tagsReferenceEvent($ev['tags'] ?? [], (string)$ctx['request_event_id'])) {
            return ['paid' => false, 'reason' => 'no e-tag referencing our request'];
        }
        // Verify the Schnorr signature over the canonical event id (which
        // NostrCrypto re-derives from the presented content — a receipt with
        // a genuine (id,sig) pair but altered tags/content fails here).
        try {
            if (!NostrCrypto::verifyEventArray($ev)) {
                return ['paid' => false, 'reason' => 'invalid signature'];
            }
        } catch (\Throwable $e) {
            return ['paid' => false, 'reason' => 'invalid signature'];
        }
        // Decrypt + inspect the receipt body.
        try {
            $plain = Nip44::decrypt((string)($ev['content'] ?? ''), $convKey);
            $data = json_decode($plain, true);
        } catch (\Throwable $e) {
            return ['paid' => false, 'reason' => 'undecryptable content'];
        }
        if (is_array($data) && isset($data['res']) && $data['res'] === 'ok') {
            return ['paid' => true];
        }
        return ['paid' => false, 'reason' => 'res is not ok'];
    }

    private static function tagsReferenceEvent(array $tags, string $eventId): bool
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'e' && ($tag[1] ?? null) === $eventId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Open a socket, subscribe for the reply, publish the request, and return
     * the first decrypted reply payload (bolt11 or error) — or null when
     * $deadline passes without one.
     */
    private static function roundTrip(
        string $relayUrl,
        array $signedEvent,
        string $ephemeralPubkey,
        string $requestId,
        int $createdAt,
        float $deadline,
        string $convKey
    ): ?array {
        $client = null;
        $subId = bin2hex(random_bytes(8));
        try {
            $client = new \WebSocket\Client($relayUrl);
            // The connect (TCP + websocket upgrade) spends budget too — a
            // black-holing relay host can't stretch the wait past the deadline.
            $client->setTimeout(max(0.1, self::remaining($deadline)));
            $client->connect();

            $filter = self::replyFilter($ephemeralPubkey, $requestId, $createdAt);
            $client->text(json_encode(['REQ', $subId, $filter]));
            $client->text(json_encode(['EVENT', $signedEvent]));

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
                if ($data[0] === 'EVENT' && ($data[1] ?? null) === $subId && isset($data[2])) {
                    $ev = $data[2];
                    // Only accept replies actually authored by the receiver.
                    if (!hash_equals($signedEvent['tags'][0][1] ?? '', (string)($ev['pubkey'] ?? ''))) {
                        continue;
                    }
                    try {
                        $plain = Nip44::decrypt((string)($ev['content'] ?? ''), $convKey);
                        $parsed = json_decode($plain, true);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if (is_array($parsed)) {
                        // The bolt11 reply is what we're after; ignore a stray
                        // receipt that might race in (handled separately).
                        if (isset($parsed['bolt11']) || isset($parsed['error']) || isset($parsed['code'])) {
                            return $parsed;
                        }
                    }
                } elseif ($data[0] === 'CLOSED' && ($data[1] ?? null) === $subId) {
                    break;
                }
                // EOSE / OK / NOTICE: keep waiting for the merchant's reply.
            }
            return null;
        } catch (\Throwable $e) {
            throw new ClinkException('CLINK relay error: ' . $e->getMessage(), 2);
        } finally {
            self::closeQuietly($client, $subId);
        }
    }

    /**
     * Collect events matching our reply filter until EOSE or timeout. Used by
     * the cron receipt poll, where we read whatever the relay still holds.
     *
     * $waitAfterEose keeps the subscription open past EOSE until the timeout —
     * a short live-listen window for ephemeral receipts spec-compliant relays
     * never retain. $stopWhen, when given, is called with each event and ends
     * the collection early by returning true (e.g. "that's the receipt").
     *
     * @return array<int,array> raw event arrays
     */
    private static function collectEvents(
        string $relayUrl,
        string $ephemeralPubkey,
        string $requestId,
        int $createdAt,
        int $timeout,
        bool $waitAfterEose = false,
        ?callable $stopWhen = null
    ): array {
        $client = null;
        $subId = bin2hex(random_bytes(8));
        $out = [];
        // One deadline covering connect and every read, socket timeout shrunk
        // to what's left before each read — same enforcement as roundTrip().
        $deadline = microtime(true) + $timeout;
        try {
            $client = new \WebSocket\Client($relayUrl);
            $client->setTimeout(max(0.1, self::remaining($deadline)));
            $client->connect();
            $filter = self::replyFilter($ephemeralPubkey, $requestId, $createdAt);
            $client->text(json_encode(['REQ', $subId, $filter]));

            while (($left = self::remaining($deadline)) > 0) {
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
                    $out[] = $data[2];
                    if ($stopWhen !== null && $stopWhen($data[2])) {
                        break;
                    }
                } elseif ($data[0] === 'EOSE' && ($data[1] ?? null) === $subId) {
                    if (!$waitAfterEose) {
                        break; // we have all stored events the relay will give us
                    }
                    // Live-listen mode: the relay retained nothing (or not the
                    // receipt); keep the subscription open until the deadline
                    // to catch a receipt broadcast while we're connected.
                } elseif ($data[0] === 'CLOSED' && ($data[1] ?? null) === $subId) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // best-effort: swallow transport errors, return what we have
            error_log('[clink] receipt poll relay error: ' . $e->getMessage());
        } finally {
            self::closeQuietly($client, $subId);
        }
        return $out;
    }

    /** Reply/receipt subscription filter: kind 21001 to us, referencing our request. */
    private static function replyFilter(string $ephemeralPubkey, string $requestId, int $createdAt): array
    {
        return [
            'kinds' => [self::KIND],
            '#p' => [$ephemeralPubkey],
            '#e' => [$requestId],
            'since' => max(0, $createdAt - 1),
        ];
    }

    /** Receive the next text frame's content, or null on timeout/close/control noise. */
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
        if (defined('CLINK_NOFFER_TIMEOUT_SEC')) {
            return (int)CLINK_NOFFER_TIMEOUT_SEC;
        }
        return self::DEFAULT_TIMEOUT_SEC;
    }

    /** Seconds left before $deadline, floored at 0. */
    private static function remaining(float $deadline): float
    {
        return max(0.0, $deadline - microtime(true));
    }
}
