<?php
/**
 * CashuPayServer - Pure-PHP Bitcoin address validation.
 *
 * Validates the address encodings we accept for static-address mode without
 * any bignum extension: bech32 v0 (P2WPKH/P2WSH), bech32m v1 (P2TR), and
 * base58check (P2PKH/P2SH). bech32 checksums are 30-bit integer math and
 * base58 decoding of a 25-byte payload is a short byte-wise long division, so
 * none of this needs GMP — which shared WordPress hosts frequently lack.
 *
 * OnchainWallet::validateAddress uses this as its fallback when the
 * bitwasp/GMP path is unavailable or throws, so a merchant on a GMP-less host
 * still gets a real checksum verification (a typo'd address is rejected), not
 * a shrug. Kept dependency-free on purpose: nothing here may call gmp_* or
 * touch vendor/.
 */

class AddressCheck {
    private const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32_CONST = 1;          // BIP173 (witness v0)
    private const BECH32M_CONST = 0x2BC830A3; // BIP350 (witness v1+)
    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Per-network encoding parameters. */
    private const NETWORKS = [
        'mainnet' => ['hrp' => 'bc',   'p2pkh' => 0x00, 'p2sh' => 0x05],
        'testnet' => ['hrp' => 'tb',   'p2pkh' => 0x6F, 'p2sh' => 0xC4],
        'signet'  => ['hrp' => 'tb',   'p2pkh' => 0x6F, 'p2sh' => 0xC4],
        'regtest' => ['hrp' => 'bcrt', 'p2pkh' => 0x6F, 'p2sh' => 0xC4],
    ];

    /**
     * Validate an address against a network.
     *
     * Accepts the same set the GMP path accepts: base58check P2PKH/P2SH,
     * bech32 v0 with a 20- or 32-byte program, and bech32m v1 with a 32-byte
     * program (P2TR). Unknown witness versions are rejected — we can't build
     * a watch-only view of an output type we don't understand.
     *
     * @return array{valid:bool, error:?string}
     */
    public static function validate(string $address, string $network): array {
        $address = trim($address);
        if ($address === '') {
            return ['valid' => false, 'error' => 'Empty address'];
        }
        if (!isset(self::NETWORKS[$network])) {
            return ['valid' => false, 'error' => "Unsupported network: {$network}"];
        }
        $params = self::NETWORKS[$network];

        // A '1'-separated string that starts with a known HRP is a segwit
        // attempt; validate it as such rather than letting it fall through to
        // base58 (where it would fail with a misleading error).
        $lower = strtolower($address);
        if (strpos($lower, $params['hrp'] . '1') === 0) {
            return self::validateSegwit($address, $params['hrp']);
        }
        return self::validateBase58($address, $params);
    }

    /** @return array{valid:bool, error:?string} */
    private static function validateSegwit(string $address, string $expectedHrp): array {
        // BIP173: all-lowercase or all-uppercase, never mixed.
        if ($address !== strtolower($address) && $address !== strtoupper($address)) {
            return ['valid' => false, 'error' => 'Mixed-case bech32 address'];
        }
        $s = strtolower($address);
        if (strlen($s) > 90) {
            return ['valid' => false, 'error' => 'Address too long'];
        }
        $pos = strrpos($s, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($s)) {
            return ['valid' => false, 'error' => 'Malformed bech32 address'];
        }
        $hrp = substr($s, 0, $pos);
        if ($hrp !== $expectedHrp) {
            return ['valid' => false, 'error' => 'Wrong network prefix'];
        }
        $data = [];
        for ($i = $pos + 1; $i < strlen($s); $i++) {
            $d = strpos(self::BECH32_CHARSET, $s[$i]);
            if ($d === false) {
                return ['valid' => false, 'error' => 'Invalid character in address'];
            }
            $data[] = $d;
        }
        if (count($data) < 7) { // version + >=0 program + 6 checksum
            return ['valid' => false, 'error' => 'Address too short'];
        }

        $check = self::polymod(array_merge(self::hrpExpand($hrp), $data));
        $version = $data[0];
        // v0 uses the bech32 constant, v1+ the bech32m constant (BIP350).
        $expectedConst = $version === 0 ? self::BECH32_CONST : self::BECH32M_CONST;
        if ($check !== $expectedConst) {
            return ['valid' => false, 'error' => 'Bad address checksum'];
        }

        $program = self::convertBits(array_slice($data, 1, -6), 5, 8, false);
        if ($program === null) {
            return ['valid' => false, 'error' => 'Malformed witness program'];
        }
        $len = count($program);
        if ($version === 0 && ($len === 20 || $len === 32)) {
            return ['valid' => true, 'error' => null];
        }
        if ($version === 1 && $len === 32) {
            return ['valid' => true, 'error' => null];
        }
        return ['valid' => false, 'error' => 'Unsupported witness version or program length'];
    }

    /**
     * @param array{hrp:string, p2pkh:int, p2sh:int} $params
     * @return array{valid:bool, error:?string}
     */
    private static function validateBase58(string $address, array $params): array {
        $decoded = self::base58Decode($address);
        if ($decoded === null || strlen($decoded) !== 25) {
            return ['valid' => false, 'error' => 'Malformed base58 address'];
        }
        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (!hash_equals($expected, $checksum)) {
            return ['valid' => false, 'error' => 'Bad address checksum'];
        }
        $version = ord($payload[0]);
        if ($version !== $params['p2pkh'] && $version !== $params['p2sh']) {
            return ['valid' => false, 'error' => 'Wrong network version byte'];
        }
        return ['valid' => true, 'error' => null];
    }

    // -------- primitives --------

    private static function polymod(array $values): int {
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

    private static function hrpExpand(string $hrp): array {
        $out = [];
        foreach (str_split($hrp) as $c) $out[] = ord($c) >> 5;
        $out[] = 0;
        foreach (str_split($hrp) as $c) $out[] = ord($c) & 31;
        return $out;
    }

    /** @return int[]|null */
    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): ?array {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        $maxAcc = (1 << ($fromBits + $toBits - 1)) - 1;
        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) return null;
            $acc = (($acc << $fromBits) | $value) & $maxAcc;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad) {
            if ($bits > 0) $ret[] = ($acc << ($toBits - $bits)) & $maxv;
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxv) !== 0) {
            return null;
        }
        return $ret;
    }

    /**
     * Base58 decode via byte-wise long division — no bignum extension.
     * Addresses decode to 25 bytes, so the quadratic loop is ~35x25 steps.
     */
    private static function base58Decode(string $s): ?string {
        if ($s === '' || strlen($s) > 90) {
            return null;
        }
        $num = []; // little-endian byte accumulator
        foreach (str_split($s) as $ch) {
            $carry = strpos(self::B58_ALPHABET, $ch);
            if ($carry === false) {
                return null;
            }
            $count = count($num);
            for ($i = 0; $i < $count; $i++) {
                $carry += $num[$i] * 58;
                $num[$i] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $num[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }
        $leading = strspn($s, '1');
        return str_repeat("\x00", $leading) . implode('', array_map('chr', array_reverse($num)));
    }
}
