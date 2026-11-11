<?php
/**
 * CashuPayServer - BOLT11 expiry reader
 *
 * The payment page refreshes a long-lived invoice's Lightning QR when the
 * underlying BOLT11 lapses, so the server has to know WHEN that is. A mint
 * quote carries its own expiry, but the direct-receive rails (LNURL, NWC,
 * Strike, CLINK noffer) hand back a bare BOLT11 — the lifetime is encoded in
 * the invoice itself: the 35-bit creation timestamp plus the optional `x`
 * tagged field (seconds, default 3600 per BOLT #11).
 *
 * Pure PHP, no bignum: every field here fits in 64 bits. The checksum is not
 * verified — the string came from a wallet we chose to trust for the bolt11
 * itself; a malformed string simply yields null and the caller falls back to
 * the default invoice window.
 */
declare(strict_types=1);

final class Bolt11Expiry {
    /** BOLT #11: an invoice without an `x` field expires after 3600 s. */
    public const DEFAULT_EXPIRY_SEC = 3600;

    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const TAG_EXPIRY = 6; // 'x'
    private const SIGNATURE_CHARS = 104; // 520 bits / 5

    /**
     * Unix timestamp at which the invoice expires, or null when the string
     * cannot be parsed as a BOLT11 invoice.
     */
    public static function expiresAt(string $bolt11): ?int {
        $parsed = self::parse($bolt11);
        if ($parsed === null) {
            return null;
        }
        return $parsed['timestamp'] + $parsed['expiry'];
    }

    /**
     * @return array{timestamp:int, expiry:int}|null
     */
    public static function parse(string $bolt11): ?array {
        $s = strtolower(trim($bolt11));
        if (str_starts_with($s, 'lightning:')) {
            $s = substr($s, 10);
        }
        if (!preg_match('/^ln[a-z0-9]+$/', $s)) {
            return null;
        }
        $sep = strrpos($s, '1');
        if ($sep === false || $sep < 2) {
            return null;
        }
        $data = substr($s, $sep + 1);
        // timestamp (7) + signature (104) + checksum (6) is the floor.
        if (strlen($data) < 7 + self::SIGNATURE_CHARS + 6) {
            return null;
        }
        $data = substr($data, 0, -6); // drop the bech32 checksum
        $data = substr($data, 0, -self::SIGNATURE_CHARS); // drop the signature

        $values = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos(self::CHARSET, $data[$i]);
            if ($v === false) {
                return null;
            }
            $values[] = $v;
        }

        $timestamp = 0;
        for ($i = 0; $i < 7; $i++) {
            $timestamp = ($timestamp << 5) | $values[$i];
        }

        $expiry = self::DEFAULT_EXPIRY_SEC;
        $pos = 7;
        $n = count($values);
        while ($pos + 3 <= $n) {
            $type = $values[$pos];
            $dataLen = ($values[$pos + 1] << 5) | $values[$pos + 2];
            $pos += 3;
            if ($pos + $dataLen > $n) {
                return null; // truncated tagged field
            }
            if ($type === self::TAG_EXPIRY) {
                // Up to 12 chars (60 bits) is already far beyond any sane
                // expiry; refuse anything that could overflow the int.
                if ($dataLen > 12) {
                    return null;
                }
                $x = 0;
                for ($i = 0; $i < $dataLen; $i++) {
                    $x = ($x << 5) | $values[$pos + $i];
                }
                $expiry = $x;
            }
            $pos += $dataLen;
        }
        if ($timestamp <= 0) {
            return null;
        }
        return ['timestamp' => $timestamp, 'expiry' => $expiry];
    }
}
