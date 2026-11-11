<?php
/**
 * CashuPayServer — NWC (Nostr Wallet Connect, NIP-47) connection URI codec
 *
 * A connection string points this server at a merchant's wallet service:
 *
 *   nostr+walletconnect://<hex wallet pubkey>?relay=wss://…&secret=<hex>&lud16=…
 *
 *   pubkey  32-byte hex — the wallet service's nostr identity (required)
 *   relay   websocket URL the wallet service listens on (required, may repeat)
 *   secret  32-byte hex — OUR nostr private key for this connection (required)
 *   lud16   optional lightning address the wallet suggests for display
 *
 * The `secret` is spendable-adjacent key material: anyone holding the full URI
 * can issue whatever requests the wallet granted this connection. It must
 * never be echoed back to browsers or logs — use displayLabel() for any
 * user-facing or logged representation.
 *
 * Deliberately self-contained (no relay/crypto dependencies) so it is cheap
 * to unit-test and safe to call from the admin/setup validators, mirroring
 * ClinkNoffer.
 */

declare(strict_types=1);

class NwcUri
{
    public const SCHEME = 'nostr+walletconnect';

    /**
     * Cheap shape check for admin/runtime validators: does this string parse
     * into a structurally valid NWC connection URI? Never throws.
     */
    public static function isValid(string $uri): bool
    {
        try {
            self::parse($uri);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Parse a connection URI into its parts. Throws InvalidArgumentException
     * on any structural problem (wrong scheme, malformed pubkey/secret,
     * missing or non-websocket relay).
     *
     * Both spellings seen in the wild are accepted: the canonical
     * `nostr+walletconnect://<pubkey>?…` and the no-slashes
     * `nostr+walletconnect:<pubkey>?…` some wallets emit.
     *
     * @return array{
     *   pubkey:string, relay:string, relays:string[], secret:string,
     *   lud16:?string
     * }
     */
    public static function parse(string $uri): array
    {
        $uri = trim($uri);
        if (!preg_match('~^' . preg_quote(self::SCHEME, '~') . ':(//)?~i', $uri, $m)) {
            throw new \InvalidArgumentException('Not a nostr+walletconnect:// URI');
        }
        $rest = substr($uri, strlen(self::SCHEME) + 1 + strlen($m[1] ?? ''));

        $qPos = strpos($rest, '?');
        if ($qPos === false) {
            throw new \InvalidArgumentException('NWC URI has no query parameters');
        }
        $pubkey = strtolower(substr($rest, 0, $qPos));
        $query = substr($rest, $qPos + 1);

        if (!preg_match('/^[0-9a-f]{64}$/', $pubkey)) {
            throw new \InvalidArgumentException('NWC wallet pubkey must be 64 hex characters');
        }

        // parse_str would fold repeated relay= params into one; walk the pairs
        // manually so every relay survives.
        $relays = [];
        $secret = null;
        $lud16 = null;
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $k = $eq === false ? $pair : substr($pair, 0, $eq);
            $v = $eq === false ? '' : rawurldecode(substr($pair, $eq + 1));
            switch (strtolower($k)) {
                case 'relay':
                    $relays[] = $v;
                    break;
                case 'secret':
                    $secret = strtolower($v);
                    break;
                case 'lud16':
                    $lud16 = $v;
                    break;
                // Unknown params are ignored per spec forward-compatibility.
            }
        }

        if ($relays === []) {
            throw new \InvalidArgumentException('NWC URI is missing a relay parameter');
        }
        foreach ($relays as $relay) {
            if (!preg_match('~^wss?://[^\s]+$~i', $relay)) {
                throw new \InvalidArgumentException("NWC relay must be a ws:// or wss:// URL: {$relay}");
            }
        }
        if ($secret === null || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
            throw new \InvalidArgumentException('NWC secret must be 64 hex characters');
        }

        return [
            'pubkey' => $pubkey,
            'relay' => $relays[0],
            'relays' => $relays,
            'secret' => $secret,
            'lud16' => ($lud16 !== null && $lud16 !== '') ? $lud16 : null,
        ];
    }

    /**
     * Stable identity for duplicate detection: the wallet pubkey plus the
     * connection secret. Two pastes of the same connection with reordered or
     * extra query params (or scheme-slash variants) collapse to one key;
     * different connections to the same wallet stay distinct.
     */
    public static function dedupKey(string $uri): string
    {
        $parsed = self::parse($uri);
        return $parsed['pubkey'] . ':' . $parsed['secret'];
    }

    /**
     * Secret-free display form for UIs, notifications, and logs, e.g.
     * "NWC wallet a1b2c3d4… via relay.example.com". This is the ONLY
     * representation of a connection that may leave the server.
     */
    public static function displayLabel(string $uri): string
    {
        try {
            $parsed = self::parse($uri);
        } catch (\Throwable $e) {
            // Never risk echoing a malformed-but-secret-bearing string back.
            return 'NWC connection (unparsed)';
        }
        $host = parse_url($parsed['relay'], PHP_URL_HOST) ?: $parsed['relay'];
        return 'NWC wallet ' . substr($parsed['pubkey'], 0, 8) . '… via ' . $host;
    }
}
