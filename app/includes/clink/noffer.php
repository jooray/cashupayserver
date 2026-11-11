<?php
/**
 * CashuPayServer — CLINK noffer (NIP-69) codec
 *
 * A "noffer" is a static Lightning payment code: a bech32 (NIP-19 style) string
 * prefixed with `noffer` carrying a TLV payload that points a payer at a Nostr
 * service which mints invoices on demand. It is the CLINK successor to LNURL —
 * see https://clinkme.dev/specs.html and NIP-69.
 *
 * TLV item types (NIP-69):
 *   0  receiver service public key — 32 raw bytes (required)
 *   1  relay URL the service subscribes on — UTF-8 (required; may repeat)
 *   2  offer identifier string — UTF-8 (required)
 *   3  pricing-type flag — 1 byte: 0 fixed, 1 variable, 2 spontaneous (optional)
 *   4  price in sats — big-endian integer (optional, display only)
 *
 * This codec is deliberately self-contained (no relay/crypto dependencies) so it
 * is cheap to unit-test and safe to call from the admin validators. It performs
 * a full bech32 checksum verification — unlike swentel/nostr-php's Nip19 decoder,
 * which skips the checksum and caps the length below what real noffers need.
 */

declare(strict_types=1);

class ClinkNoffer
{
    public const PREFIX = 'noffer';

    /** Pricing-type flags (TLV type 3). */
    public const PRICE_FIXED = 0;
    public const PRICE_VARIABLE = 1;
    public const PRICE_SPONTANEOUS = 2;

    /** TLV item types. */
    private const T_PUBKEY = 0;
    private const T_RELAY = 1;
    private const T_OFFER = 2;
    private const T_PRICE_TYPE = 3;
    private const T_PRICE = 4;

    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32_CONST = 1; // bech32 (NIP-19), not bech32m

    /**
     * Cheap shape check for admin/runtime validators: does this string decode
     * into a structurally valid noffer? Never throws.
     */
    public static function isValid(string $str): bool
    {
        try {
            self::decode($str);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Decode a `noffer1…` string into its parts. Throws InvalidArgumentException
     * on any structural problem (bad prefix, bad checksum, missing required TLV).
     *
     * @return array{
     *   pubkey:string, relay:string, relays:string[], offer:string,
     *   price_type:?int, price:?int
     * }
     */
    public static function decode(string $str): array
    {
        $str = trim($str);
        // CLINK strings are sometimes shared with a lightning: scheme prefix,
        // mirroring how wallets handle LNURL/bolt11. Strip it if present.
        if (stripos($str, 'lightning:') === 0) {
            $str = substr($str, 10);
        }
        [$hrp, $bytes] = self::bech32DecodeToBytes($str);
        if ($hrp !== self::PREFIX) {
            throw new \InvalidArgumentException("Not a noffer string (prefix: {$hrp})");
        }

        $tlv = self::parseTlv($bytes);

        if (!isset($tlv[self::T_PUBKEY][0]) || strlen($tlv[self::T_PUBKEY][0]) !== 32) {
            throw new \InvalidArgumentException('noffer missing/!32-byte pubkey (TLV 0)');
        }
        if (!isset($tlv[self::T_RELAY][0]) || $tlv[self::T_RELAY][0] === '') {
            throw new \InvalidArgumentException('noffer missing relay (TLV 1)');
        }
        if (!isset($tlv[self::T_OFFER][0]) || $tlv[self::T_OFFER][0] === '') {
            throw new \InvalidArgumentException('noffer missing offer id (TLV 2)');
        }

        $pubkey = bin2hex($tlv[self::T_PUBKEY][0]);
        $relays = $tlv[self::T_RELAY];
        $offer = $tlv[self::T_OFFER][0];

        $priceType = null;
        if (isset($tlv[self::T_PRICE_TYPE][0]) && $tlv[self::T_PRICE_TYPE][0] !== '') {
            $priceType = ord($tlv[self::T_PRICE_TYPE][0][0]);
        }
        $price = null;
        if (isset($tlv[self::T_PRICE][0]) && $tlv[self::T_PRICE][0] !== '') {
            $price = 0;
            foreach (str_split($tlv[self::T_PRICE][0]) as $b) {
                $price = ($price << 8) | ord($b);
            }
        }

        return [
            'pubkey' => $pubkey,
            'relay' => $relays[0],
            'relays' => $relays,
            'offer' => $offer,
            'price_type' => $priceType,
            'price' => $price,
        ];
    }

    /**
     * Encode parts into a `noffer1…` string. Used by tests and any future
     * "generate our own offer" path. $parts: pubkey(hex32), relay|relays,
     * offer, optional price_type, optional price.
     */
    public static function encode(array $parts): string
    {
        $pubkeyHex = (string)($parts['pubkey'] ?? '');
        if (strlen($pubkeyHex) !== 64 || !ctype_xdigit($pubkeyHex)) {
            throw new \InvalidArgumentException('encode: pubkey must be 32-byte hex');
        }
        $relays = $parts['relays'] ?? (isset($parts['relay']) ? [$parts['relay']] : []);
        if (empty($relays)) {
            throw new \InvalidArgumentException('encode: at least one relay required');
        }
        $offer = (string)($parts['offer'] ?? '');
        if ($offer === '') {
            throw new \InvalidArgumentException('encode: offer id required');
        }

        $payload = self::tlvItem(self::T_PUBKEY, hex2bin($pubkeyHex));
        foreach ($relays as $relay) {
            $payload .= self::tlvItem(self::T_RELAY, (string)$relay);
        }
        $payload .= self::tlvItem(self::T_OFFER, $offer);
        if (isset($parts['price_type']) && $parts['price_type'] !== null) {
            $payload .= self::tlvItem(self::T_PRICE_TYPE, chr((int)$parts['price_type'] & 0xff));
        }
        if (isset($parts['price']) && $parts['price'] !== null) {
            $n = (int)$parts['price'];
            $be = '';
            do {
                $be = chr($n & 0xff) . $be;
                $n >>= 8;
            } while ($n > 0);
            $payload .= self::tlvItem(self::T_PRICE, $be);
        }

        return self::bech32EncodeFromBytes(self::PREFIX, $payload);
    }

    // ---- TLV helpers ----

    /** Encode one TLV item (type, length, value) as raw bytes. */
    private static function tlvItem(int $type, string $value): string
    {
        $len = strlen($value);
        if ($len > 255) {
            throw new \InvalidArgumentException("TLV value too long for 1-byte length: {$len}");
        }
        return chr($type) . chr($len) . $value;
    }

    /**
     * Parse a TLV byte string into [type => [value, value, ...]]. Unknown types
     * are kept (NIP-19 says ignore unrecognised TLVs, but keeping them is
     * harmless and lets callers inspect). A truncated trailing item throws.
     */
    private static function parseTlv(string $bytes): array
    {
        $out = [];
        $i = 0;
        $n = strlen($bytes);
        while ($i < $n) {
            if ($i + 2 > $n) {
                throw new \InvalidArgumentException('Truncated TLV header');
            }
            $type = ord($bytes[$i]);
            $len = ord($bytes[$i + 1]);
            $i += 2;
            if ($i + $len > $n) {
                throw new \InvalidArgumentException('Truncated TLV value');
            }
            $out[$type][] = substr($bytes, $i, $len);
            $i += $len;
        }
        return $out;
    }

    // ---- bech32 (no length cap, checksum-verified) ----

    /** @return array{0:string,1:string} [hrp, raw 8-bit byte string] */
    private static function bech32DecodeToBytes(string $s): array
    {
        if ($s === '' || strtolower($s) !== $s && strtoupper($s) !== $s) {
            throw new \InvalidArgumentException('bech32: mixed case');
        }
        $s = strtolower($s);
        $pos = strrpos($s, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($s)) {
            throw new \InvalidArgumentException('bech32: no separator');
        }
        $hrp = substr($s, 0, $pos);
        $data = [];
        for ($i = $pos + 1, $m = strlen($s); $i < $m; $i++) {
            $d = strpos(self::CHARSET, $s[$i]);
            if ($d === false) {
                throw new \InvalidArgumentException('bech32: bad charset char');
            }
            $data[] = $d;
        }
        $chk = self::polymod(array_merge(self::hrpExpand($hrp), $data));
        if ($chk !== self::BECH32_CONST) {
            throw new \InvalidArgumentException('bech32: bad checksum');
        }
        $bytes = self::convertBits(array_slice($data, 0, -6), 5, 8, false);
        if ($bytes === null) {
            throw new \InvalidArgumentException('bech32: bad padding');
        }
        return [$hrp, implode('', array_map('chr', $bytes))];
    }

    private static function bech32EncodeFromBytes(string $hrp, string $bytes): string
    {
        $data = self::convertBits(array_map('ord', str_split($bytes)), 8, 5, true);
        $values = array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0]);
        $polymod = self::polymod($values) ^ self::BECH32_CONST;
        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }
        $out = $hrp . '1';
        foreach (array_merge($data, $checksum) as $d) {
            $out .= self::CHARSET[$d];
        }
        return $out;
    }

    private static function polymod(array $values): int
    {
        $gen = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $gen[$i];
                }
            }
        }
        return $chk;
    }

    private static function hrpExpand(string $hrp): array
    {
        $out = [];
        foreach (str_split($hrp) as $c) {
            $out[] = ord($c) >> 5;
        }
        $out[] = 0;
        foreach (str_split($hrp) as $c) {
            $out[] = ord($c) & 31;
        }
        return $out;
    }

    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): ?array
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        $maxAcc = (1 << ($fromBits + $toBits - 1)) - 1;
        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) {
                return null;
            }
            $acc = (($acc << $fromBits) | $value) & $maxAcc;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad) {
            if ($bits > 0) {
                $ret[] = ($acc << ($toBits - $bits)) & $maxv;
            }
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxv) !== 0) {
            return null;
        }
        return $ret;
    }
}
