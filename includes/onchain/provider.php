<?php
/**
 * CashuPayServer - Blockchain provider abstraction for on-chain payment polling.
 *
 * Implementations return a normalized view of a watch address's transactions
 * regardless of the underlying data source (public Esplora-style HTTP API,
 * a self-hosted Bitcoin Core node, etc.).
 */

require_once __DIR__ . '/../database.php';

/**
 * A single transaction output observed paying a given watch address.
 */
final class OnchainTxObservation {
    public function __construct(
        public readonly string $txid,
        public readonly int $vout,
        public readonly int $amountSat,
        public readonly int $confirmations,
        public readonly ?int $blockHeight,
    ) {}
}

interface BlockchainProvider {
    /**
     * Return every output across all transactions that pay $address. Includes
     * mempool transactions (confirmations = 0).
     *
     * @return OnchainTxObservation[]
     */
    public function addressTransactions(string $address): array;

    /**
     * Return the current best-known block height. Used as a sanity check and
     * for derived calculations.
     */
    public function currentTipHeight(): int;
}

/**
 * Esplora HTTP backend (mempool.space / blockstream.info compatible).
 *
 * Public endpoints used:
 *   GET /address/{addr}/txs     -> list of txs paying $addr (mempool + confirmed)
 *   GET /blocks/tip/height      -> current chain tip
 *
 * The instance is configured with the base URL of the API (e.g.
 * https://mempool.space/api). Per-network defaults can be obtained via
 * EsploraProvider::defaultUrlForNetwork().
 */
final class EsploraProvider implements BlockchainProvider {
    private const DEFAULTS = [
        'mainnet' => 'https://mempool.space/api',
        'testnet' => 'https://mempool.space/testnet/api',
        'signet'  => 'https://mempool.space/signet/api',
        // No public regtest API exists; users must self-host or use BitcoindRpcProvider.
        'regtest' => null,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 10,
    ) {}

    public static function defaultUrlForNetwork(string $network): ?string {
        return self::DEFAULTS[$network] ?? null;
    }

    public function addressTransactions(string $address): array {
        $txs = $this->httpJson('/address/' . rawurlencode($address) . '/txs');
        $tip = $this->currentTipHeight();
        $out = [];
        foreach ($txs as $tx) {
            $confirmed = (bool)($tx['status']['confirmed'] ?? false);
            $blockHeight = $confirmed ? ($tx['status']['block_height'] ?? null) : null;
            $confs = ($confirmed && $blockHeight !== null) ? max(0, $tip - $blockHeight + 1) : 0;
            foreach ($tx['vout'] ?? [] as $voutIdx => $vout) {
                if (($vout['scriptpubkey_address'] ?? null) !== $address) {
                    continue;
                }
                $out[] = new OnchainTxObservation(
                    txid: $tx['txid'],
                    vout: $voutIdx,
                    amountSat: (int)($vout['value'] ?? 0),
                    confirmations: $confs,
                    blockHeight: $blockHeight,
                );
            }
        }
        return $out;
    }

    public function currentTipHeight(): int {
        $body = $this->httpRaw('/blocks/tip/height');
        return (int)trim($body);
    }

    private function httpRaw(string $path): string {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'CashuPayServer/1.0 (onchain)',
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $err) {
            throw new RuntimeException("Esplora request failed for {$path}: {$err}");
        }
        if ($status >= 400) {
            throw new RuntimeException("Esplora {$path} -> HTTP {$status}: " . substr((string)$body, 0, 200));
        }
        return (string)$body;
    }

    private function httpJson(string $path): array {
        $raw = $this->httpRaw($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Esplora {$path}: malformed JSON response");
        }
        return $decoded;
    }
}

/**
 * Bitcoin Core RPC backend.
 *
 * Designed for tests (the existing bitcoind regtest fixture) and for users
 * who self-host a Bitcoin Core node and want full sovereignty.
 *
 * Address watching is done via importdescriptors with a `wpkh(addr(...))`
 * watch-only descriptor on first sight, then scantxoutset / getrawmempool +
 * listsinceblock to enumerate inbound payments.
 */
final class BitcoindRpcProvider implements BlockchainProvider {
    public function __construct(
        private readonly string $rpcUrl,         // http://user:pass@host:port/
        private readonly int $timeoutSec = 15,
    ) {}

    public function addressTransactions(string $address): array {
        // Make sure the wallet is watching the address (idempotent).
        $this->ensureWatchAddress($address);

        $tip = $this->currentTipHeight();
        $out = [];

        // Confirmed outputs paying $address (scantxoutset gives us UTXOs only;
        // for full history we use listreceivedbyaddress with includeempty=false).
        $entries = $this->rpc('listreceivedbyaddress', [0, true, true, $address]);
        foreach ($entries as $entry) {
            if (($entry['address'] ?? null) !== $address) {
                continue;
            }
            $txids = $entry['txids'] ?? [];
            foreach ($txids as $txid) {
                $tx = $this->rpc('gettransaction', [$txid, true]);
                $details = $tx['details'] ?? [];
                $blockHeight = $tx['blockheight'] ?? null;
                $confs = (int)($tx['confirmations'] ?? 0);
                if ($confs < 0) {
                    // Negative confirmations mean the tx was double-spent / reorged out.
                    continue;
                }
                foreach ($details as $d) {
                    if (($d['address'] ?? null) !== $address) {
                        continue;
                    }
                    if (($d['category'] ?? null) !== 'receive') {
                        continue;
                    }
                    $out[] = new OnchainTxObservation(
                        txid: $txid,
                        vout: (int)($d['vout'] ?? 0),
                        amountSat: (int)round(((float)$d['amount']) * 100_000_000),
                        confirmations: $confs,
                        blockHeight: $confs > 0 ? $blockHeight : null,
                    );
                }
            }
        }
        return $out;
    }

    public function currentTipHeight(): int {
        return (int)$this->rpc('getblockcount', []);
    }

    private function ensureWatchAddress(string $address): void {
        try {
            // timestamp='now' avoids a full rescan: cashupayserver only cares
            // about payments made AFTER the address is allocated for an
            // invoice, and full rescans are slow on real mainnet wallets.
            $descIn = ['desc' => "addr({$address})", 'timestamp' => 'now', 'label' => 'cashupay'];
            $info = $this->rpc('getdescriptorinfo', [$descIn['desc']]);
            $descIn['desc'] = $info['descriptor'];
            $this->rpc('importdescriptors', [[$descIn]]);
        } catch (Throwable $e) {
            // Some node configs may disallow importdescriptors (no watch-only
            // wallet available); log so operators can diagnose, but don't
            // crash — the caller will still see whatever the wallet tracks.
            error_log("BitcoindRpcProvider::ensureWatchAddress({$address}): " . $e->getMessage());
        }
    }

    public function rpc(string $method, array $params): mixed {
        $body = json_encode([
            'jsonrpc' => '1.0',
            'id' => 'cashupay',
            'method' => $method,
            'params' => $params,
        ]);
        $ch = curl_init($this->rpcUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $err) {
            throw new RuntimeException("bitcoind RPC {$method} failed: {$err}");
        }
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("bitcoind RPC {$method}: malformed JSON");
        }
        if (!empty($decoded['error'])) {
            throw new RuntimeException("bitcoind RPC {$method}: " . json_encode($decoded['error']));
        }
        if ($status >= 400 && empty($decoded['result'])) {
            throw new RuntimeException("bitcoind RPC {$method}: HTTP {$status}");
        }
        return $decoded['result'];
    }
}

/**
 * Build the configured provider for a store.
 */
final class OnchainProviderFactory {
    public static function forStore(array $store): BlockchainProvider {
        $kind = $store['onchain_provider'] ?? 'esplora';
        $network = $store['onchain_network'] ?? 'mainnet';
        $url = $store['onchain_provider_url'] ?: null;

        if ($kind === 'bitcoind' || $kind === 'bitcoind-rpc') {
            if (!$url) {
                throw new RuntimeException('bitcoind provider requires onchain_provider_url');
            }
            return new BitcoindRpcProvider($url);
        }

        // Default: Esplora HTTP.
        if (!$url) {
            $url = EsploraProvider::defaultUrlForNetwork($network);
            if (!$url) {
                throw new RuntimeException(
                    "No default Esplora URL for network '{$network}'. "
                    . "Set onchain_provider_url in the store config."
                );
            }
        }
        return new EsploraProvider($url);
    }
}
