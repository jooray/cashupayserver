<?php
/**
 * CashuPayServer - Blockchain provider abstraction for on-chain payment polling.
 *
 * Implementations return a normalized view of a watch address's transactions
 * regardless of the underlying data source (public Esplora-style HTTP API,
 * a self-hosted Bitcoin Core node, etc.).
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../safe_http.php';
require_once __DIR__ . '/../endpoint_health.php';

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
     * @param ?int $sinceHeight Optional optimization hint: the caller only
     *   cares about txs confirmed at or after this block height (older ones
     *   are filtered out anyway). Implementations may stop paging once they
     *   pass below it. Null = no hint (enumerate everything available).
     * @return OnchainTxObservation[]
     */
    public function addressTransactions(string $address, ?int $sinceHeight = null): array;

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
 * The instance is configured with one or more API base URLs (e.g.
 * https://mempool.space/api). Every request tries the hosts in order and fails
 * over on any error — network failure, HTTP error, or a malformed body — so a
 * single explorer outage (or an explorer serving garbage) does not blind
 * on-chain payment detection. Failed hosts get a short EndpointHealth cooldown
 * so subsequent requests skip them instead of re-paying the timeout.
 * Per-network defaults can be obtained via defaultUrlsForNetwork().
 */
final class EsploraProvider implements BlockchainProvider {
    private const DEFAULTS = [
        'mainnet' => ['https://mempool.space/api', 'https://blockstream.info/api'],
        'testnet' => ['https://mempool.space/testnet/api', 'https://blockstream.info/testnet/api'],
        // blockstream.info has no public signet instance.
        'signet'  => ['https://mempool.space/signet/api'],
        // No public regtest API exists; users must self-host or use BitcoindRpcProvider.
        'regtest' => null,
    ];

    /** @var string[] */
    private readonly array $baseUrls;

    public function __construct(
        string|array $baseUrl,
        private readonly int $timeoutSec = 10,
    ) {
        $urls = array_values(array_filter(array_map('trim', (array)$baseUrl), fn($u) => $u !== ''));
        if (empty($urls)) {
            throw new InvalidArgumentException('EsploraProvider requires at least one base URL');
        }
        $this->baseUrls = $urls;
    }

    public static function defaultUrlForNetwork(string $network): ?string {
        return self::DEFAULTS[$network][0] ?? null;
    }

    /**
     * The full default failover chain for a network, primary first.
     * @return string[]|null
     */
    public static function defaultUrlsForNetwork(string $network): ?array {
        return self::DEFAULTS[$network] ?? null;
    }

    // Esplora returns confirmed transactions 25 per page. A page with fewer
    // than this many confirmed entries is the last page of chain history.
    private const ESPLORA_CHAIN_PAGE = 25;
    // Hard ceiling on pages walked (25 confirmed each) so a hot, heavily
    // re-used address can't make a single poll page forever.
    private const ESPLORA_MAX_PAGES = 40;

    public function addressTransactions(string $address, ?int $sinceHeight = null): array {
        $tip = $this->currentTipHeight();
        $out = [];

        // Esplora paginates /address/{a}/txs: the base call returns mempool txs
        // plus only the FIRST 25 confirmed; older confirmed txs require walking
        // /txs/chain/{last_seen_txid}. Without this loop, a re-used address with
        // >25 confirmed txs would silently drop older payments and an invoice
        // paid to it would never settle. We stop once a page is short, once we
        // page below $sinceHeight (older txs are filtered out anyway), or at a
        // hard page cap.
        $enc = rawurlencode($address);
        $lastSeenTxid = null;
        for ($page = 0; $page < self::ESPLORA_MAX_PAGES; $page++) {
            $path = $lastSeenTxid === null
                ? "/address/{$enc}/txs"
                : "/address/{$enc}/txs/chain/" . rawurlencode($lastSeenTxid);
            $txs = $this->httpJson($path);
            if (empty($txs)) {
                break;
            }

            $confirmedInPage = 0;
            $minHeightInPage = null;
            foreach ($txs as $tx) {
                $confirmed = (bool)($tx['status']['confirmed'] ?? false);
                $blockHeight = $confirmed ? ($tx['status']['block_height'] ?? null) : null;
                // A hostile or MITM'd provider could report an out-of-range
                // block_height to inflate the confirmation count past minConfs /
                // REORG_SAFETY_DEPTH and force a premature settle. A genuinely
                // confirmed tx sits at an integer height in (0, tip]; anything
                // else is treated as unconfirmed (confs=0) so it can't settle.
                if ($confirmed) {
                    if (!is_int($blockHeight) || $blockHeight <= 0 || $blockHeight > $tip) {
                        error_log(sprintf(
                            'EsploraProvider::addressTransactions(%s): tx %s reported out-of-range block_height %s (tip %d); treating as unconfirmed',
                            $address, (string)($tx['txid'] ?? '?'), var_export($blockHeight, true), $tip
                        ));
                        $confirmed = false;
                        $blockHeight = null;
                    } else {
                        $confirmedInPage++;
                        if ($minHeightInPage === null || $blockHeight < $minHeightInPage) {
                            $minHeightInPage = $blockHeight;
                        }
                    }
                }
                $confs = ($confirmed && $blockHeight !== null) ? max(0, $tip - $blockHeight + 1) : 0;
                foreach ($tx['vout'] ?? [] as $voutIdx => $vout) {
                    if (($vout['scriptpubkey_address'] ?? null) !== $address) {
                        continue;
                    }
                    $out[] = new OnchainTxObservation(
                        txid: $tx['txid'],
                        vout: $voutIdx,
                        // Floor at 0: a negative value would corrupt the
                        // received-amount / underpayment math downstream.
                        amountSat: max(0, (int)($vout['value'] ?? 0)),
                        confirmations: $confs,
                        blockHeight: $blockHeight,
                    );
                }
                if (isset($tx['txid'])) {
                    $lastSeenTxid = $tx['txid'];
                }
            }

            // Fewer than a full confirmed page => no more chain history.
            if ($confirmedInPage < self::ESPLORA_CHAIN_PAGE) {
                break;
            }
            // We've paged past the caller's window; older txs are irrelevant.
            if ($sinceHeight !== null && $minHeightInPage !== null && $minHeightInPage < $sinceHeight) {
                break;
            }
            if ($page === self::ESPLORA_MAX_PAGES - 1) {
                error_log("EsploraProvider::addressTransactions({$address}): hit max page cap; older history not walked");
            }
        }
        return $out;
    }

    public function currentTipHeight(): int {
        return $this->fetch('/blocks/tip/height', function (string $raw): int {
            $body = trim($raw);
            // Esplora returns the height as a bare decimal string. Reject anything
            // else: an empty body, a proxy/error HTML page, or whitespace must NOT
            // collapse to (int)0 — a 0 tip poisons the historical-UTXO filter at
            // allocation time (a stored created-tip of 0 disables the filter, so a
            // pre-existing UTXO on a re-used address could wrongly settle an
            // invoice). Throwing here counts as a host failure, so fetch() fails
            // over to the next host and only then lets currentTipBestEffort()
            // degrade to null (skip-filter) instead of a false 0.
            if ($body === '' || !ctype_digit($body)) {
                throw new RuntimeException('non-numeric body: ' . substr($body, 0, 80));
            }
            $height = (int)$body;
            if ($height <= 0) {
                throw new RuntimeException("non-positive height {$height}");
            }
            return $height;
        });
    }

    /**
     * Run one Esplora request with host failover. Hosts are tried in
     * configured order, skipping any inside an EndpointHealth cooldown —
     * unless ALL of them are cooling down, in which case every host is tried
     * anyway (a request that might succeed beats a guaranteed failure, and it
     * keeps single-URL setups completely unaffected by the cooldown).
     *
     * $parse validates and converts the raw body; a malformed body (even under
     * HTTP 200) counts as a host failure and fails over, because an endpoint
     * serving garbage is as unusable as one that is down. Note the tip and the
     * tx pages of one poll may therefore come from different hosts; a
     * one-block tip skew only makes the block-height sanity check treat a
     * brand-new confirmation as unconfirmed for one poll — the safe direction.
     */
    private function fetch(string $path, callable $parse): mixed {
        $hosts = $this->baseUrls;
        $active = array_values(array_filter(
            $hosts,
            fn($h) => !EndpointHealth::isCoolingDown('esplora:' . $h)
        ));
        if (!empty($active)) {
            $hosts = $active;
        }

        $errors = [];
        foreach ($hosts as $base) {
            try {
                $value = $parse($this->httpRawFrom($base, $path));
                EndpointHealth::recordSuccess('esplora:' . $base);
                return $value;
            } catch (\Throwable $e) {
                EndpointHealth::recordFailure('esplora:' . $base);
                $errors[] = $base . ': ' . $e->getMessage();
            }
        }
        throw new RuntimeException("Esplora {$path} failed on all hosts: " . implode(' | ', $errors));
    }

    private function httpRawFrom(string $baseUrl, string $path): string {
        $url = rtrim($baseUrl, '/') . $path;
        // Esplora URL is admin-configured; the operator's allow_private_endpoints
        // opt-in lets them point at a local Bitcoin node.
        $result = \SafeHttp::request($url, [
            'timeout' => $this->timeoutSec,
            'connectTimeout' => 5,
            'followRedirects' => true,
            'headers' => ['Accept: application/json'],
            'userAgent' => 'CashuPayServer/1.0 (onchain)',
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '') {
            throw new RuntimeException("request failed for {$path}: {$result['error']}");
        }
        if ($result['status'] >= 400) {
            throw new RuntimeException("{$path} -> HTTP {$result['status']}: " . substr($result['body'], 0, 200));
        }
        return $result['body'];
    }

    private function httpJson(string $path): array {
        return $this->fetch($path, function (string $raw) use ($path): array {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("{$path}: malformed JSON response");
            }
            return $decoded;
        });
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

    public function addressTransactions(string $address, ?int $sinceHeight = null): array {
        // $sinceHeight is unused here: listreceivedbyaddress already returns the
        // full history for the watched address in one call (no pagination).
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

    /**
     * Split `scheme://user:pass@host...` into a credential-free URL plus the
     * matching `Authorization: Basic` header line (none when the URL carries
     * no userinfo). parse_url percent-decoding is honoured so an encoded
     * password round-trips the way curl would have sent it.
     *
     * @return array{0: string, 1: string[]}
     */
    public static function splitCredentials(string $url): array {
        $parts = parse_url($url);
        if ($parts === false || (!isset($parts['user']) && !isset($parts['pass']))) {
            return [$url, []];
        }
        $user = rawurldecode((string)($parts['user'] ?? ''));
        $pass = rawurldecode((string)($parts['pass'] ?? ''));
        $rebuilt = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return [$rebuilt, ['Authorization: Basic ' . base64_encode($user . ':' . $pass)]];
    }

    public function rpc(string $method, array $params): mixed {
        $body = json_encode([
            'jsonrpc' => '1.0',
            'id' => 'cashupay',
            'method' => $method,
            'params' => $params,
        ]);
        // bitcoind RPC almost always points at localhost; the URL is
        // admin-configured via the store record. Its rpcuser:rpcpassword
        // ride the URL in the usual bitcoind form, but SafeHttp refuses
        // userinfo (it must never reach a log line or confuse host parsing),
        // so they are peeled off here and sent as HTTP basic auth instead.
        [$url, $headers] = self::splitCredentials($this->rpcUrl);
        $headers[] = 'Content-Type: application/json';
        $result = \SafeHttp::request($url, [
            'method' => 'POST',
            'body' => $body,
            'timeout' => $this->timeoutSec,
            'connectTimeout' => 5,
            'headers' => $headers,
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '') {
            throw new RuntimeException("bitcoind RPC {$method} failed: {$result['error']}");
        }
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("bitcoind RPC {$method}: malformed JSON");
        }
        if (!empty($decoded['error'])) {
            throw new RuntimeException("bitcoind RPC {$method}: " . json_encode($decoded['error']));
        }
        if ($result['status'] >= 400 && empty($decoded['result'])) {
            throw new RuntimeException("bitcoind RPC {$method}: HTTP {$result['status']}");
        }
        return $decoded['result'];
    }
}

/**
 * Build the configured provider for a store.
 */
final class OnchainProviderFactory {
    /**
     * Test seam: when set, forStore() returns this provider instead of building
     * a real network-backed one. Production code never assigns it (null), so the
     * normal path is unchanged. Tests use it to drive pollInvoice() with a
     * scripted set of observations (e.g. to exercise reorg reconciliation)
     * without contacting Esplora/bitcoind.
     */
    public static ?BlockchainProvider $testProvider = null;

    public static function forStore(array $store): BlockchainProvider {
        if (self::$testProvider !== null) {
            return self::$testProvider;
        }
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
        if ($url) {
            // An operator-configured URL is used exclusively — no built-in
            // fallback hosts. Someone pointing at their own explorer instance
            // did so deliberately (usually for privacy); its outage must not
            // silently leak watched addresses to public explorers.
            return new EsploraProvider($url);
        }
        $urls = EsploraProvider::defaultUrlsForNetwork($network);
        if (!$urls) {
            throw new RuntimeException(
                "No default Esplora URL for network '{$network}'. "
                . "Set onchain_provider_url in the store config."
            );
        }
        return new EsploraProvider($urls);
    }
}
