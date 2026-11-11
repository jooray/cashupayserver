<?php
/**
 * Shared HTTP client implementation for Boltz v2-compatible swap providers.
 *
 * Both Zeus and Boltz expose the same v2 endpoint surface; only base URLs
 * differ. Subclasses define {@see baseUrl()} for each supported network.
 */

require_once __DIR__ . '/provider.php';
require_once __DIR__ . '/../safe_http.php';

abstract class BoltzLikeProvider implements SwapProvider {
    protected int $timeoutSec = 15;

    /**
     * Return the API base URL for the given network, or null if this provider
     * does not support that network. Public so SwapQuoteFetcher can build
     * curl_multi handles without subclassing.
     */
    abstract public function baseUrl(string $network): ?string;

    public function isReachable(string $network): bool {
        $base = $this->baseUrl($network);
        if (!$base) return false;
        try {
            $this->httpGet($network, '/v2/swap/reverse');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getReversePairInfo(string $network): SwapPairInfo {
        $data = $this->httpGet($network, '/v2/swap/reverse');
        return self::parseReversePairInfo($data, $this->getName());
    }

    /**
     * Parse a /v2/swap/reverse JSON response into SwapPairInfo. Shared between
     * the per-provider getReversePairInfo() path and the parallel
     * SwapQuoteFetcher path so both produce identical results.
     */
    public static function parseReversePairInfo(array $data, string $providerName): SwapPairInfo {
        // The response is a 2-level map keyed by from/to currency.
        // We only care about BTC/BTC for v1.
        $pair = $data['BTC']['BTC'] ?? null;
        if (!is_array($pair)) {
            throw new RuntimeException($providerName . ': BTC/BTC reverse pair not advertised');
        }
        return new SwapPairInfo(
            feePercent:           (float)($pair['fees']['percentage'] ?? 0),
            lockupFeeSats:        (int)($pair['fees']['minerFees']['lockup'] ?? 0),
            claimFeeEstimateSats: (int)($pair['fees']['minerFees']['claim'] ?? 0),
            minSats:              (int)($pair['limits']['minimal'] ?? 0),
            maxSats:              (int)($pair['limits']['maximal'] ?? PHP_INT_MAX),
            pairHash:             $pair['hash'] ?? null,
        );
    }

    public function createReverseSwap(
        string $network,
        int $onchainAmountSats,
        string $claimPublicKeyHex,
        string $preimageHashHex
    ): SwapCreateResult {
        if ($onchainAmountSats <= 0) {
            throw new InvalidArgumentException('onchainAmount must be positive');
        }
        $body = [
            'from' => 'BTC',
            'to' => 'BTC',
            'onchainAmount' => $onchainAmountSats,
            'claimPublicKey' => $claimPublicKeyHex,
            'preimageHash' => $preimageHashHex,
        ];
        $resp = $this->httpPostJson($network, '/v2/swap/reverse', $body);

        // Required fields
        $required = ['id', 'invoice', 'lockupAddress', 'refundPublicKey', 'timeoutBlockHeight', 'swapTree'];
        foreach ($required as $k) {
            if (!isset($resp[$k])) {
                throw new RuntimeException($this->getName() . ': reverse-swap response missing ' . $k);
            }
        }
        $tree = $resp['swapTree'];
        $claimOutHex = $tree['claimLeaf']['output'] ?? null;
        $refundOutHex = $tree['refundLeaf']['output'] ?? null;
        if (!$claimOutHex || !$refundOutHex) {
            throw new RuntimeException($this->getName() . ': swapTree missing claim/refund leaf output');
        }
        // hex2bin() returns false (coerced to "") on odd-length/non-hex input,
        // which would persist empty leaf scripts and make the on-chain claim
        // permanently unbuildable AFTER the HTLC is funded. Reject up front so
        // the swap is never created against malformed tree data.
        if (!ctype_xdigit($claimOutHex) || strlen($claimOutHex) % 2 !== 0
            || !ctype_xdigit($refundOutHex) || strlen($refundOutHex) % 2 !== 0) {
            throw new RuntimeException($this->getName() . ': swapTree leaf output is not valid hex');
        }
        $refundPubHex = (string)$resp['refundPublicKey'];
        if (!ctype_xdigit($refundPubHex) || strlen($refundPubHex) !== 66) {
            throw new RuntimeException($this->getName() . ': refundPublicKey is not a 33-byte hex key');
        }

        // Boltz returns the actual LN invoice amount in the response, but the
        // field name varies. We decode it from the BOLT11 if not present.
        $invoiceAmount = isset($resp['invoiceAmount'])
            ? (int)$resp['invoiceAmount']
            : $this->decodeBolt11AmountSats((string)$resp['invoice']);

        return new SwapCreateResult(
            swapId: (string)$resp['id'],
            invoice: (string)$resp['invoice'],
            invoiceAmountSats: $invoiceAmount,
            onchainAmountSats: $onchainAmountSats,
            lockupAddress: (string)$resp['lockupAddress'],
            refundPublicKeyHex: (string)$resp['refundPublicKey'],
            timeoutBlockHeight: (int)$resp['timeoutBlockHeight'],
            claimLeafScript: hex2bin($claimOutHex),
            refundLeafScript: hex2bin($refundOutHex),
            rawResponse: $resp,
        );
    }

    public function getSwapStatus(string $network, string $swapId): ?SwapStatus {
        try {
            $resp = $this->httpGet($network, '/v2/swap/' . rawurlencode($swapId));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
        return new SwapStatus(
            status: (string)($resp['status'] ?? ''),
            lockupTxHex: $resp['transaction']['hex'] ?? null,
            preimage: $resp['preimage'] ?? null,
            raw: $resp,
        );
    }

    public function broadcastTx(string $network, string $rawTxHex): string {
        $resp = $this->httpPostJson($network, '/v2/chain/BTC/transaction', ['hex' => $rawTxHex]);
        if (!isset($resp['id'])) {
            throw new RuntimeException($this->getName() . ': broadcast response missing id');
        }
        return (string)$resp['id'];
    }

    public function cancelInvoice(string $network, string $swapId): void {
        // Boltz exposes /v2/swap/reverse/{id}/invoice which cancels the held
        // HTLC. Best-effort: swallow errors so the cron loop can move on.
        try {
            $this->httpPostJson($network, '/v2/swap/reverse/' . rawurlencode($swapId) . '/invoice', []);
        } catch (Throwable $e) {
            error_log($this->getName() . " cancelInvoice({$swapId}): " . $e->getMessage());
        }
    }

    // -------- HTTP plumbing --------

    protected function httpGet(string $network, string $path): array {
        $base = $this->baseUrl($network);
        if (!$base) {
            throw new RuntimeException($this->getName() . ": no base URL for network '{$network}'");
        }
        $url = rtrim($base, '/') . $path;
        // Swap-provider URL is admin-configured; private endpoints honored
        // via the operator's opt-in for self-hosted Boltz mirrors.
        $result = \SafeHttp::request($url, [
            'timeout' => $this->timeoutSec,
            'connectTimeout' => 5,
            'headers' => ['Accept: application/json'],
            'userAgent' => 'CashuPayServer/1.0 (swaps)',
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '') {
            throw new RuntimeException($this->getName() . " GET {$path} failed: {$result['error']}");
        }
        if ($result['status'] >= 400) {
            throw new RuntimeException($this->getName() . " GET {$path} -> HTTP {$result['status']}: " . substr($result['body'], 0, 200));
        }
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException($this->getName() . " GET {$path}: malformed JSON");
        }
        return $decoded;
    }

    protected function httpPostJson(string $network, string $path, array $body): array {
        $base = $this->baseUrl($network);
        if (!$base) {
            throw new RuntimeException($this->getName() . ": no base URL for network '{$network}'");
        }
        $url = rtrim($base, '/') . $path;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException($this->getName() . ": failed to encode request body");
        }
        $result = \SafeHttp::request($url, [
            'method' => 'POST',
            'body' => $json,
            'timeout' => $this->timeoutSec,
            'connectTimeout' => 5,
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            'userAgent' => 'CashuPayServer/1.0 (swaps)',
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '') {
            throw new RuntimeException($this->getName() . " POST {$path} failed: {$result['error']}");
        }
        $decoded = json_decode($result['body'], true);
        if ($result['status'] >= 400) {
            $msg = $decoded['error'] ?? substr($result['body'], 0, 200);
            throw new RuntimeException($this->getName() . " POST {$path} -> HTTP {$result['status']}: {$msg}");
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($this->getName() . " POST {$path}: malformed JSON");
        }
        return $decoded;
    }

    /**
     * Minimal BOLT11 amount decoder for the case where Boltz doesn't include
     * `invoiceAmount` in its response. Parses the amount section between the
     * HRP prefix (lnbc / lntb / lnbcrt / lnsig) and the timestamp/data section.
     *
     * Returns 0 if the invoice has no explicit amount (BOLT11 allows zero-amount
     * invoices but Boltz never issues them for swaps).
     */
    protected function decodeBolt11AmountSats(string $invoice): int {
        $inv = strtolower(trim($invoice));
        $prefixes = ['lnbcrt', 'lnbc', 'lntb', 'lnsig'];
        $hrp = '';
        foreach ($prefixes as $p) {
            if (str_starts_with($inv, $p)) { $hrp = $p; break; }
        }
        if ($hrp === '') {
            throw new RuntimeException('Unrecognized BOLT11 prefix');
        }
        $i = strlen($hrp);
        // Amount: digits followed by optional multiplier (m/u/n/p).
        $numStart = $i;
        while ($i < strlen($inv) && ctype_digit($inv[$i])) $i++;
        if ($i === $numStart) return 0;
        $num = (int)substr($inv, $numStart, $i - $numStart);
        $mult = 1.0;
        if ($i < strlen($inv) && in_array($inv[$i], ['m', 'u', 'n', 'p'], true)) {
            $mult = match ($inv[$i]) {
                'm' => 0.001,
                'u' => 0.000_001,
                'n' => 0.000_000_001,
                'p' => 0.000_000_000_001,
            };
        }
        $btc = $num * $mult;
        return (int)round($btc * 100_000_000);
    }
}
