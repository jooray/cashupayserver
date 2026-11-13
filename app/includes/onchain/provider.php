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
 *   (SwapClaimer additionally uses GET /fee-estimates and POST /tx on the
 *   same host chain, see chainForStore().)
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
        $override = self::publicDefaultsOverride();
        if ($override !== null) {
            return $override;
        }
        return self::DEFAULTS[$network] ?? null;
    }

    /**
     * Test / rig seam for the built-in public explorer chain: a comma-
     * separated list of base URLs in CASHUPAY_ESPLORA_DEFAULT_URLS replaces
     * the defaults of EVERY network. Only honoured while private endpoints
     * are allowed (the same opt-in the e2e rigs and LAN deployments set), so
     * a stray environment variable on a stock deployment can never redirect
     * chain watching to an attacker-chosen host. Null = no override.
     *
     * @return string[]|null
     */
    public static function publicDefaultsOverride(): ?array {
        $env = getenv('CASHUPAY_ESPLORA_DEFAULT_URLS');
        if ($env === false || trim($env) === '') {
            return null;
        }
        if (!\SafeHttp::privateEndpointsAllowed()) {
            return null;
        }
        $urls = array_values(array_filter(array_map('trim', explode(',', $env)), fn($u) => $u !== ''));
        return $urls === [] ? null : $urls;
    }

    /**
     * The ordered Esplora host chain for a store, shared by the chain
     * watcher (OnchainProviderFactory) and the swap claimer's fee-estimate /
     * broadcast paths so every Esplora request a store causes follows the
     * same privacy rule:
     *
     *   - no custom URL           -> the built-in public chain for the network
     *                                (mempool.space, then blockstream.info);
     *   - custom URL, fallback off -> [custom] only. Someone pointing at their
     *                                own explorer did so deliberately (usually
     *                                for privacy); its outage must not leak
     *                                watched addresses to public explorers;
     *   - custom URL, fallback on  -> [custom, ...public chain]: the operator
     *                                opted in to keep detecting payments
     *                                through an outage of their own instance.
     *
     * A bitcoind-rpc store has no Esplora URL of its own (its URL is an RPC
     * endpoint), so it gets the public chain, as before. Returns [] when the
     * network has no public explorer (regtest) and nothing was configured.
     *
     * @param array $store stores row (onchain_provider, onchain_provider_url,
     *                     onchain_provider_fallback, onchain_network)
     * @param ?string $network overrides the store's network (swap rows carry
     *                         their own)
     * @return string[]
     */
    public static function chainForStore(array $store, ?string $network = null): array {
        $network = $network ?? ($store['onchain_network'] ?? 'mainnet');
        $kind = $store['onchain_provider'] ?? 'esplora';
        $url = trim((string)($store['onchain_provider_url'] ?? ''));
        $public = self::defaultUrlsForNetwork($network) ?? [];
        if ($kind === 'bitcoind' || $kind === 'bitcoind-rpc' || $url === '') {
            return $public;
        }
        $fallback = (int)($store['onchain_provider_fallback'] ?? 0) === 1;
        if (!$fallback) {
            return [$url];
        }
        // Don't list the custom host twice if it IS one of the defaults.
        return array_values(array_unique(array_merge([$url], $public)));
    }

    /** Longest a save-time probe of an operator-entered URL may take. */
    public const PROBE_TIMEOUT_SEC = 8;

    /**
     * Static (offline) checks for an operator-entered Esplora base URL.
     * Returns the normalised URL (trimmed, no trailing slash); throws
     * InvalidArgumentException with an operator-readable message otherwise.
     *
     * Policy: http(s) only, a plain hostname or IP literal, no userinfo (a
     * bitcoind-style user:pass@ URL is the most likely paste here and must
     * be refused with a hint rather than failing in cron), no query string
     * or fragment, no whitespace / control characters. Whether a private or
     * loopback host is reachable is decided by SafeHttp at probe time (it
     * honours the allow_private_endpoints opt-in), not here.
     */
    public static function normalizeCustomUrl(string $raw): string {
        $url = trim($raw);
        if ($url === '') {
            throw new InvalidArgumentException('Provider URL is empty');
        }
        if (preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            throw new InvalidArgumentException('Provider URL must not contain spaces or control characters');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException(
                'Provider URL must be a full http:// or https:// address, e.g. https://mempool.space/api'
            );
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException(
                "Provider URL must start with http:// or https:// (got {$scheme}://)"
            );
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'Provider URL must not contain a username or password. This field takes an '
                . 'Esplora-style HTTP API (mempool.space / blockstream.info compatible), not a '
                . 'Bitcoin Core RPC URL.'
            );
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Provider URL must not contain a query string or #fragment');
        }
        $host = (string)$parts['host'];
        $hostForCheck = (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']')
            ? substr($host, 1, -1) : $host;
        if (!\SafeHttp::isPlainHostSyntax($hostForCheck)) {
            throw new InvalidArgumentException("Provider URL has an invalid host '{$host}'");
        }
        return rtrim($url, '/');
    }

    /**
     * Live check that $url (already normalised) answers like an Esplora API:
     * GET {url}/blocks/tip/height must return a positive integer. Returns the
     * tip height; throws RuntimeException with an operator-readable message
     * on any failure. Runs through SafeHttp, so a LAN / loopback host is
     * refused unless the allow_private_endpoints opt-in is on; that refusal
     * is explained in the message rather than surfacing as a bare "request
     * failed" later in cron.
     *
     * A common paste is the explorer's web root (https://mempool.space)
     * instead of its API base (https://mempool.space/api). When the probe
     * fails and {url}/api answers, the message suggests the corrected URL
     * rather than silently rewriting what the operator typed.
     */
    public static function probeCustomUrl(string $url, int $timeoutSec = self::PROBE_TIMEOUT_SEC): int {
        try {
            return (new self($url, $timeoutSec))->currentTipHeight();
        } catch (\Throwable $e) {
            $reason = self::describeProbeFailure($e->getMessage());
            $hint = '';
            if (!str_ends_with($url, '/api') && !self::isPrivateEndpointRefusal($e->getMessage())) {
                try {
                    (new self($url . '/api', $timeoutSec))->currentTipHeight();
                    $hint = " Did you mean {$url}/api ?";
                } catch (\Throwable $ignored) {
                    // No luck with /api either; report the original failure.
                }
            }
            throw new RuntimeException(
                "Could not verify the provider URL: {$reason}. Nothing was saved." . $hint
            );
        }
    }

    /** normalizeCustomUrl + probeCustomUrl. Returns the normalised URL. */
    public static function validateCustomUrl(string $raw, int $timeoutSec = self::PROBE_TIMEOUT_SEC): string {
        $url = self::normalizeCustomUrl($raw);
        self::probeCustomUrl($url, $timeoutSec);
        return $url;
    }

    private static function isPrivateEndpointRefusal(string $msg): bool {
        return str_contains($msg, 'private/reserved IP');
    }

    /**
     * Turn fetch()'s "Esplora /path failed on all hosts: host: detail" into
     * one operator-facing sentence.
     */
    private static function describeProbeFailure(string $msg): string {
        if (self::isPrivateEndpointRefusal($msg)) {
            return 'the host is a private / local network address, which this server refuses '
                . 'by default. To use a LAN or localhost explorer, enable private endpoints '
                . '(allow_private_endpoints setting or CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1) and retry';
        }
        if (str_contains($msg, 'DNS resolution failed')) {
            return 'the hostname could not be resolved';
        }
        if (preg_match('/-> HTTP (\d{3})/', $msg, $m)) {
            return "GET /blocks/tip/height returned HTTP {$m[1]}";
        }
        if (str_contains($msg, 'non-numeric body')) {
            return 'GET /blocks/tip/height did not return a block height (is this the API base URL, '
                . 'not the explorer web page?)';
        }
        if (str_contains($msg, 'request failed')) {
            return 'the server could not be reached (connection refused, timeout, or TLS error)';
        }
        // Last resort: the raw detail of the first host, trimmed.
        $detail = trim((string)preg_replace('/^Esplora \S+ failed on all hosts: /', '', $msg));
        return substr($detail, 0, 200);
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

        // Default: Esplora HTTP. The host chain is the store's custom URL
        // (exclusively, unless the operator opted in to the public fallback)
        // or the built-in public chain — see EsploraProvider::chainForStore.
        $urls = EsploraProvider::chainForStore($store, $network);
        if ($urls === []) {
            throw new RuntimeException(
                "No default Esplora URL for network '{$network}'. "
                . "Set onchain_provider_url in the store config."
            );
        }
        return new EsploraProvider($urls);
    }
}
