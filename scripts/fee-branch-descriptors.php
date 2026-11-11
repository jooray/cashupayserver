<?php
/**
 * fee-branch-descriptors.php — Bitcoin Core import list for BareBits fee sweeps
 *
 * Every BareBits install derives its on-chain dev-fee addresses under its own
 * BIP32 branch of the shared BareBits zpub:  <zpub>/<branch>/0/<index>
 * (includes/fee_branch.php). Installs released before branches existed
 * derived on the plain  <zpub>/0/<index>  chain, so keep importing it too
 * (installs without a branch now skip the on-chain rail instead). One Bitcoin
 * Core descriptor wallet
 * can watch — and, with the matching xprv, spend — all of it: import one
 * wpkh(xpub/<b>/0/*) descriptor per branch plus the plain chain.
 *
 * This script prints that list as the JSON argument of `importdescriptors`:
 *
 *   php scripts/fee-branch-descriptors.php --fetch                 # N from getbarebits.com/xpub/xpub.php
 *   php scripts/fee-branch-descriptors.php --highest 42            # N given explicitly
 *   php scripts/fee-branch-descriptors.php --highest 42 --from 20  # only branches 20..42 (adding to a wallet)
 *
 *   bitcoin-cli -rpcwallet=barebits-fees importdescriptors "$(php scripts/fee-branch-descriptors.php --fetch)"
 *
 * Options:
 *   --highest N      highest branch to include (required unless --fetch)
 *   --fetch          read N from CASHUPAY_DEV_FEE_BRANCH_ENDPOINT (?format=json)
 *   --from N         first branch to include (default 1). Pass 0 to include
 *                    the plain chain (no branch) too, which is the default
 *                    when --from is omitted.
 *   --xpub KEY       extended public key (default: the dev-fee zpub baked into
 *                    includes/dev_fee.php). Give the matching xprv/zprv instead
 *                    to produce descriptors the wallet can SIGN with.
 *   --network NET    mainnet | testnet | signet | regtest (default: the
 *                    dev-fee network constant)
 *   --type T         P2WPKH (default) | P2SH-P2WPKH
 *   --range R        address range per branch (default 1000; raise it for an
 *                    install that allocated more fee addresses than that)
 *   --timestamp T    "now" (default: skip rescan, right for brand-new branches)
 *                    or a unix time / 0 to make Core rescan from there
 *
 * Checksums are computed locally (Core refuses descriptors without them).
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Descriptor checksum — port of Bitcoin Core's DescriptorChecksum().
// ---------------------------------------------------------------------------
const DESCRIPTOR_INPUT_CHARSET = "0123456789()[],'/*abcdefgh@:\$%{}IJKLMNOPQRSTUVWXYZ&+-.;<=>?!^_|~ijklmnopqrstuvwxyzABCDEFGH`#\"\\ ";
const DESCRIPTOR_CHECKSUM_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

function descriptor_polymod(int $c, int $val): int {
    $c0 = $c >> 35;
    $c = (($c & 0x7ffffffff) << 5) ^ $val;
    if ($c0 & 1)  { $c ^= 0xf5dee51989; }
    if ($c0 & 2)  { $c ^= 0xa9fdca3312; }
    if ($c0 & 4)  { $c ^= 0x1bab10e32d; }
    if ($c0 & 8)  { $c ^= 0x3706b1677a; }
    if ($c0 & 16) { $c ^= 0x644d626ffd; }
    return $c;
}

/** 8-character checksum for a descriptor string WITHOUT the "#..." suffix. */
function descriptor_checksum(string $desc): string {
    $c = 1;
    $cls = 0;
    $clscount = 0;
    $len = strlen($desc);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos(DESCRIPTOR_INPUT_CHARSET, $desc[$i]);
        if ($pos === false) {
            throw new InvalidArgumentException("Invalid character in descriptor: " . $desc[$i]);
        }
        $c = descriptor_polymod($c, $pos & 31);
        $cls = $cls * 3 + ($pos >> 5);
        if (++$clscount === 3) {
            $c = descriptor_polymod($c, $cls);
            $cls = 0;
            $clscount = 0;
        }
    }
    if ($clscount > 0) {
        $c = descriptor_polymod($c, $cls);
    }
    for ($j = 0; $j < 8; $j++) {
        $c = descriptor_polymod($c, 0);
    }
    $c ^= 1;
    $out = '';
    for ($j = 0; $j < 8; $j++) {
        $out .= DESCRIPTOR_CHECKSUM_CHARSET[($c >> (5 * (7 - $j))) & 31];
    }
    return $out;
}

/** Descriptor (with checksum) for one branch; null branch = the plain chain. */
function fee_branch_descriptor(string $key, ?int $branch, string $type): string {
    $path = ($branch === null ? '' : '/' . $branch) . '/0/*';
    $inner = "wpkh({$key}{$path})";
    $desc = $type === 'P2SH-P2WPKH' ? "sh({$inner})" : $inner;
    return $desc . '#' . descriptor_checksum($desc);
}

/**
 * Build the importdescriptors request array.
 *
 * @param string   $key       xpub/tpub (or xprv/tprv for a signing wallet)
 * @param int      $highest   last branch to include
 * @param int      $from      first branch to include; 0 = plain chain first
 * @param int|string $timestamp 'now' or unix time
 */
function fee_branch_import_list(string $key, int $highest, int $from, string $type, int $range, int|string $timestamp): array {
    $out = [];
    $add = function (?int $branch) use (&$out, $key, $type, $range, $timestamp): void {
        $out[] = [
            'desc'      => fee_branch_descriptor($key, $branch, $type),
            'timestamp' => $timestamp,
            'range'     => [0, $range],
            'active'    => false,
            'internal'  => false,
        ];
    };
    if ($from <= 0) {
        $add(null);
        $from = 1;
    }
    for ($b = $from; $b <= $highest; $b++) {
        $add($b);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $opts = getopt('', ['highest:', 'fetch', 'from:', 'xpub:', 'network:', 'type:', 'range:', 'timestamp:', 'help']);
    if (isset($opts['help'])) {
        fwrite(STDERR, (string)preg_replace('/^.*?\/\*\*(.*?)\*\/.*$/s', '$1', file_get_contents(__FILE__)) . "\n");
        exit(0);
    }
    require_once __DIR__ . '/../app/includes/dev_fee.php';
    require_once __DIR__ . '/../app/includes/fee_branch.php';
    require_once __DIR__ . '/../app/includes/onchain/wallet.php';
    require_once __DIR__ . '/../app/includes/safe_http.php';

    $network = (string)($opts['network'] ?? CASHUPAY_DEV_FEE_ONCHAIN_NETWORK);
    $type    = (string)($opts['type'] ?? CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE);
    $range   = max(1, (int)($opts['range'] ?? 1000));
    $from    = isset($opts['from']) ? max(0, (int)$opts['from']) : 0;
    $ts      = (string)($opts['timestamp'] ?? 'now');
    $timestamp = ($ts === 'now') ? 'now' : (int)$ts;

    $rawKey = (string)($opts['xpub'] ?? CASHUPAY_DEV_FEE_ONCHAIN_XPUB);
    // Private keys are passed through untouched (Core accepts xprv/tprv in
    // descriptors); public keys are normalised from zpub/vpub to xpub/tpub.
    $isPrivate = (bool)preg_match('/^[xtyzuv]prv/', $rawKey);
    $key = $isPrivate ? $rawKey : OnchainWallet::toCanonicalXpub($rawKey, $network);

    if (isset($opts['fetch'])) {
        $url = FeeBranch::endpointUrl();
        $sep = str_contains($url, '?') ? '&' : '?';
        $resp = SafeHttp::request($url . $sep . 'format=json', [
            'allowPrivate' => SafeHttp::privateEndpointsAllowed(),
            'userAgent'    => 'cashupayserver-fee-branch',
            'timeout'      => 10,
        ]);
        $decoded = json_decode((string)$resp['body'], true);
        if ((int)$resp['status'] !== 200 || !is_array($decoded) || !isset($decoded['highest_branch'])) {
            fwrite(STDERR, "fetch failed: HTTP {$resp['status']} {$resp['error']}\n");
            exit(2);
        }
        $highest = (int)$decoded['highest_branch'];
    } elseif (isset($opts['highest'])) {
        $highest = (int)$opts['highest'];
    } else {
        fwrite(STDERR, "need --highest N or --fetch (see --help)\n");
        exit(2);
    }

    echo json_encode(fee_branch_import_list($key, $highest, $from, $type, $range, $timestamp), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
