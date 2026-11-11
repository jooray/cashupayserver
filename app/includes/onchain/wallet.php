<?php
/**
 * CashuPayServer - On-chain wallet wrapper around bitwasp/bitcoin.
 *
 * Handles xpub parsing/validation and per-invoice address derivation for
 * P2WPKH (native segwit) and P2SH-P2WPKH (wrapped segwit) outputs across
 * mainnet, testnet, signet, and regtest.
 *
 * SLIP-32 alternate prefixes (ypub/zpub/upub/vpub) are accepted by detecting
 * the version bytes and re-encoding to canonical xpub/tpub before handing to
 * bitwasp. The underlying key material is identical; the prefix is only a
 * hint about the intended derivation path, which is the *user's* responsibility
 * to match against their wallet.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Address\ScriptHashAddress;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Network\Networks\BitcoinRegtest as BitwaspBitcoinRegtest;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\WitnessProgram;

/**
 * bitwasp's built-in BitcoinRegtest reports 'tb' as its bech32 HRP because it
 * extends BitcoinTestnet and doesn't override the prefix. Bitcoin Core uses
 * 'bcrt' for regtest; we subclass to match so derived regtest addresses match
 * `bitcoin-cli getnewaddress` output.
 */
final class CashupayBitcoinRegtest extends BitwaspBitcoinRegtest {
    protected $bech32PrefixMap = [
        Network::BECH32_PREFIX_SEGWIT => 'bcrt',
    ];
}

class OnchainWallet {
    /**
     * SLIP-32 version-byte registry.
     * https://github.com/satoshilabs/slips/blob/master/slip-0132.md
     */
    private const SLIP32_PREFIXES = [
        // mainnet
        '0488B21E' => ['prefix' => 'xpub', 'network' => 'mainnet', 'type' => 'P2PKH'],        // BIP44 legacy
        '049D7CB2' => ['prefix' => 'ypub', 'network' => 'mainnet', 'type' => 'P2SH-P2WPKH'],  // BIP49
        '04B24746' => ['prefix' => 'zpub', 'network' => 'mainnet', 'type' => 'P2WPKH'],       // BIP84
        // testnet / signet / regtest share these
        '043587CF' => ['prefix' => 'tpub', 'network' => 'testnet', 'type' => 'P2PKH'],
        '044A5262' => ['prefix' => 'upub', 'network' => 'testnet', 'type' => 'P2SH-P2WPKH'],
        '045F1CF6' => ['prefix' => 'vpub', 'network' => 'testnet', 'type' => 'P2WPKH'],
    ];

    private const CANONICAL_PUBLIC_PREFIX = [
        'mainnet' => '0488B21E',
        'testnet' => '043587CF',
        'signet'  => '043587CF',
        'regtest' => '043587CF',
    ];

    public const SUPPORTED_TYPES = ['P2WPKH', 'P2SH-P2WPKH'];
    public const SUPPORTED_NETWORKS = ['mainnet', 'testnet', 'signet', 'regtest'];

    /**
     * Why this exists: xpub derivation runs elliptic-curve math through
     * paragonie/ecc, which hard-requires ext-gmp with no fallback — and our
     * own base58/taproot code calls gmp_* directly. Shared WordPress hosts
     * frequently ship PHP without GMP, and before this check that surfaced as
     * a fatal error mid-request (which the setup wizard's JS then misreported
     * as "could not reach the server"). Checked up front so the operator gets
     * one actionable sentence instead.
     *
     * Returns null when the environment can run the on-chain stack.
     */
    public static function environmentError(): ?string {
        if (PHP_INT_SIZE < 8) {
            return 'This server runs 32-bit PHP; on-chain wallet features require 64-bit PHP.';
        }
        if (!function_exists('gmp_init')) {
            return 'This server\'s PHP is missing the GMP extension, which is required for'
                . ' xpub-based wallets and submarine swaps. Ask your hosting provider to'
                . ' enable php-gmp, or use a single Bitcoin address instead.';
        }
        return null;
    }

    /**
     * Validate an xpub and return inferred metadata.
     *
     * @return array{valid:bool, error:?string, warnings:array, inferredType:?string, inferredNetwork:?string}
     */
    public static function validateXpub(string $xpub, string $expectedNetwork, ?string $expectedType = null): array {
        $result = [
            'valid' => false,
            'error' => null,
            'warnings' => [],
            'inferredType' => null,
            'inferredNetwork' => null,
        ];

        $xpub = trim($xpub);
        if ($xpub === '') {
            $result['error'] = 'Empty xpub';
            return $result;
        }
        if (!in_array($expectedNetwork, self::SUPPORTED_NETWORKS, true)) {
            $result['error'] = "Unsupported network: {$expectedNetwork}";
            return $result;
        }
        if ($expectedType !== null && !in_array($expectedType, self::SUPPORTED_TYPES, true)) {
            $result['error'] = "Unsupported address type: {$expectedType}";
            return $result;
        }
        if (($envError = self::environmentError()) !== null) {
            $result['error'] = $envError;
            return $result;
        }

        // Decode base58check and pull out the 4-byte version.
        $decoded = self::base58CheckDecode($xpub);
        if ($decoded === null) {
            $result['error'] = 'Invalid xpub: base58check decode failed';
            return $result;
        }
        if (strlen($decoded) !== 78) {
            $result['error'] = 'Invalid xpub: expected 78 bytes after decode, got ' . strlen($decoded);
            return $result;
        }
        $versionHex = strtoupper(bin2hex(substr($decoded, 0, 4)));
        if (!isset(self::SLIP32_PREFIXES[$versionHex])) {
            $result['error'] = "Unknown xpub version bytes: 0x{$versionHex}";
            return $result;
        }
        $meta = self::SLIP32_PREFIXES[$versionHex];
        $result['inferredType'] = $meta['type'];
        $result['inferredNetwork'] = $meta['network'];

        // Network match: SLIP-32 only distinguishes mainnet vs testnet-family.
        $isTestnetFamily = in_array($expectedNetwork, ['testnet', 'signet', 'regtest'], true);
        $expectedFamily = $isTestnetFamily ? 'testnet' : 'mainnet';
        if ($meta['network'] !== $expectedFamily) {
            $result['error'] = sprintf(
                'xpub is for %s but store is configured for %s. Paste a %s-style xpub.',
                $meta['network'], $expectedNetwork,
                $isTestnetFamily ? 'tpub/upub/vpub' : 'xpub/ypub/zpub'
            );
            return $result;
        }

        // Soft warning when the SLIP-32 prefix doesn't match the chosen address type.
        if ($expectedType !== null && $meta['type'] !== 'P2PKH' && $meta['type'] !== $expectedType) {
            $result['warnings'][] = sprintf(
                'This xpub prefix (%s) was created for %s addresses; you selected %s. '
                . 'Generated addresses will not match your wallet unless this is intentional.',
                $meta['prefix'], $meta['type'], $expectedType
            );
        }

        // Try a sanity derivation to catch malformed key material.
        try {
            self::deriveAddress($xpub, $expectedType ?? 'P2WPKH', $expectedNetwork, 0);
            $result['valid'] = true;
        } catch (Throwable $e) {
            $result['error'] = 'xpub failed sanity derivation: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Derive a single receiving address at path m/0/{index} from the given xpub.
     */
    public static function deriveAddress(string $xpub, string $type, string $network, int $index, ?int $branch = null): string {
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported address type: {$type}");
        }
        if (!in_array($network, self::SUPPORTED_NETWORKS, true)) {
            throw new InvalidArgumentException("Unsupported network: {$network}");
        }
        if ($index < 0) {
            throw new InvalidArgumentException('Negative derivation index');
        }
        // $branch inserts one extra non-hardened level ABOVE the receive chain
        // (<xpub>/<branch>/0/<index>) — the per-install dev-fee branch, see
        // includes/fee_branch.php. Null keeps the plain <xpub>/0/<index> path
        // every merchant / hosting-fee xpub uses. 0 is not a valid branch (it
        // would collide with the plain chain's meaning in the sweep wallet).
        if ($branch !== null && ($branch < 1 || $branch > 2147483647)) {
            throw new InvalidArgumentException('Derivation branch out of range');
        }
        if (($envError = self::environmentError()) !== null) {
            throw new RuntimeException($envError);
        }

        $canonicalXpub = self::normalizeToCanonicalXpub(trim($xpub), $network);
        $net = self::bitwaspNetwork($network);

        // Suppress PHP 8 'Use of parent in callables is deprecated' notices
        // emanating from bitwasp/buffertools internals.
        $prev = error_reporting();
        error_reporting($prev & ~E_DEPRECATED);
        try {
            $factory = new HierarchicalKeyFactory();
            $hk = $factory->fromExtended($canonicalXpub, $net);
            $child = $hk->derivePath($branch === null ? "0/{$index}" : "{$branch}/0/{$index}");
            $pubKeyHash = $child->getPublicKey()->getPubKeyHash();

            if ($type === 'P2WPKH') {
                $wp = WitnessProgram::v0($pubKeyHash);
                return (new SegwitAddress($wp))->getAddress($net);
            }
            // P2SH-P2WPKH: wrap the v0 witness program in a P2SH.
            $witScript = ScriptFactory::scriptPubKey()->p2wkh($pubKeyHash);
            return (new ScriptHashAddress($witScript->getScriptHash()))->getAddress($net);
        } finally {
            error_reporting($prev);
        }
    }

    /**
     * Validate a plain Bitcoin address against the configured network.
     * Used by static-address mode where the merchant pastes a single
     * address rather than an xpub. Mainnet/testnet-family mismatch is
     * rejected so a tpub-era address can't accidentally be saved on
     * a mainnet store (and vice versa).
     *
     * @return array{valid:bool, error:?string}
     */
    public static function validateAddress(string $address, string $network): array {
        $address = trim($address);
        if ($address === '') {
            return ['valid' => false, 'error' => 'Empty address'];
        }
        if (!in_array($network, self::SUPPORTED_NETWORKS, true)) {
            return ['valid' => false, 'error' => "Unsupported network: {$network}"];
        }

        // bitwasp first when the environment can run it (keeps behaviour
        // identical on healthy hosts), but two cases fall through to the
        // pure-PHP checker: bitwasp predates bech32m so it rejects valid v1
        // Taproot addresses (bc1p… — the receive default in modern wallets),
        // and on GMP-less hosts its base58 math throws an Error, which a bare
        // catch used to mislabel as "not a valid address". Static-address mode
        // only ever watches the string, so the checksum verification in
        // AddressCheck is the full requirement — no key math needed.
        if (self::environmentError() === null) {
            $net = self::bitwaspNetwork($network);
            $prev = error_reporting();
            error_reporting($prev & ~E_DEPRECATED);
            try {
                (new AddressCreator())->fromString($address, $net);
                return ['valid' => true, 'error' => null];
            } catch (Throwable $e) {
                // Fall through to the pure-PHP checker.
            } finally {
                error_reporting($prev);
            }
        }

        require_once __DIR__ . '/address_check.php';
        if (AddressCheck::validate($address, $network)['valid']) {
            return ['valid' => true, 'error' => null];
        }
        $isTestnetFamily = in_array($network, ['testnet', 'signet', 'regtest'], true);
        $hint = $isTestnetFamily
            ? 'Expected a testnet/signet/regtest address (starts with m/n/2 or tb1/tb1p/bcrt1).'
            : 'Expected a mainnet address (starts with 1/3 or bc1/bc1p).';
        return ['valid' => false, 'error' => "Invalid address for {$network}. {$hint}"];
    }

    /**
     * Derive the first N receive addresses for setup-wizard sanity preview.
     *
     * @return array<int,string>
     */
    public static function deriveFirstN(string $xpub, string $type, string $network, int $n): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = self::deriveAddress($xpub, $type, $network, $i);
        }
        return $out;
    }

    /**
     * Public form of the SLIP-32 normalisation: the xpub/tpub spelling of a
     * zpub/ypub/vpub — what Bitcoin Core descriptors expect (Core rejects
     * zpub inside wpkh(...)). Used by scripts/fee-branch-descriptors.php.
     */
    public static function toCanonicalXpub(string $xpub, string $network): string {
        return self::normalizeToCanonicalXpub(trim($xpub), $network);
    }

    // ---------- internals ----------

    /**
     * Re-encode an xpub with canonical xpub/tpub version bytes so bitwasp's
     * default networks accept it regardless of the SLIP-32 prefix.
     */
    private static function normalizeToCanonicalXpub(string $xpub, string $network): string {
        $decoded = self::base58CheckDecode($xpub);
        if ($decoded === null || strlen($decoded) !== 78) {
            throw new InvalidArgumentException('Malformed xpub');
        }
        $isTestnetFamily = in_array($network, ['testnet', 'signet', 'regtest'], true);
        $target = self::CANONICAL_PUBLIC_PREFIX[$isTestnetFamily ? 'testnet' : 'mainnet'];
        $body = hex2bin($target) . substr($decoded, 4);
        return self::base58CheckEncode($body);
    }

    private static function bitwaspNetwork(string $network) {
        switch ($network) {
            case 'mainnet': return NetworkFactory::bitcoin();
            case 'testnet':
            case 'signet':  return NetworkFactory::bitcoinTestnet();
            case 'regtest': return new CashupayBitcoinRegtest();
            default: throw new InvalidArgumentException("Unsupported network: {$network}");
        }
    }

    private static function base58CheckDecode(string $s): ?string {
        $decoded = self::base58Decode($s);
        if ($decoded === null || strlen($decoded) < 4) {
            return null;
        }
        $payload = substr($decoded, 0, -4);
        $checksum = substr($decoded, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (!hash_equals($expected, $checksum)) {
            return null;
        }
        return $payload;
    }

    private static function base58CheckEncode(string $payload): string {
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return self::base58Encode($payload . $checksum);
    }

    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private static function base58Decode(string $s): ?string {
        $alphabet = self::B58_ALPHABET;
        $num = gmp_init(0);
        for ($i = 0; $i < strlen($s); $i++) {
            $pos = strpos($alphabet, $s[$i]);
            if ($pos === false) {
                return null;
            }
            $num = gmp_add(gmp_mul($num, 58), $pos);
        }
        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        $bytes = $hex === '0' ? '' : hex2bin($hex);
        $leading = 0;
        for ($i = 0; $i < strlen($s) && $s[$i] === '1'; $i++) {
            $leading++;
        }
        return str_repeat("\x00", $leading) . $bytes;
    }

    private static function base58Encode(string $bytes): string {
        $alphabet = self::B58_ALPHABET;
        $leading = 0;
        for ($i = 0; $i < strlen($bytes) && $bytes[$i] === "\x00"; $i++) {
            $leading++;
        }
        $num = gmp_init(bin2hex($bytes) ?: '0', 16);
        $out = '';
        while (gmp_cmp($num, 0) > 0) {
            $rem = gmp_intval(gmp_mod($num, 58));
            $num = gmp_div_q($num, 58);
            $out = $alphabet[$rem] . $out;
        }
        return str_repeat('1', $leading) . $out;
    }
}
