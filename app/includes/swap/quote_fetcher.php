<?php
/**
 * Parallel quote fetcher for submarine-swap providers.
 *
 * Used by SwapProviderFactory::rankedForStore() when the auto-select-cheapest
 * feature is enabled. Fetches /v2/swap/reverse from every Boltz-like provider
 * in parallel via curl_multi, with a graceful sequential fallback for any
 * provider that does not extend BoltzLikeProvider (e.g. mock providers in
 * tests).
 *
 * Also records a structured audit trail of every fetch round so the caller
 * can persist it alongside the chosen provider for forensic / billing review.
 */

require_once __DIR__ . '/provider.php';
require_once __DIR__ . '/boltz_like_provider.php';
require_once __DIR__ . '/../safe_http.php';

final class SwapQuoteFetcher {
    private const REVERSE_PAIR_PATH = '/v2/swap/reverse';
    private const USER_AGENT = 'CashuPayServer/1.0 (swaps)';
    private const CONNECT_TIMEOUT_SEC = 3;

    private static ?array $lastAudit = null;

    /**
     * Fetch reverse-swap pair info from every provider in parallel.
     *
     * @param SwapProvider[] $providers In priority order.
     * @return array<string, ?SwapPairInfo> Map of providerName → quote (null on error).
     */
    public static function fetchAll(array $providers, string $network, int $timeoutSec = 5): array {
        $out = [];
        $audit = [
            'fetched_at'   => time(),
            'network'      => $network,
            'timeout_sec'  => $timeoutSec,
            'providers'    => [],
        ];
        $priorityIndex = [];
        foreach ($providers as $idx => $p) {
            $priorityIndex[$p->getName()] = $idx;
        }

        $boltzLike = [];
        $other = [];
        foreach ($providers as $p) {
            if ($p instanceof BoltzLikeProvider) {
                $boltzLike[$p->getName()] = $p;
            } else {
                $other[$p->getName()] = $p;
            }
        }

        if (!empty($boltzLike)) {
            self::fetchBoltzLikeParallel($boltzLike, $network, $timeoutSec, $out, $audit, $priorityIndex);
        }
        foreach ($other as $name => $provider) {
            self::fetchSequential($name, $provider, $network, $out, $audit, $priorityIndex);
        }

        self::$lastAudit = $audit;
        return $out;
    }

    private static function fetchBoltzLikeParallel(
        array $providers,
        string $network,
        int $timeoutSec,
        array &$out,
        array &$audit,
        array $priorityIndex
    ): void {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($providers as $name => $provider) {
            /** @var BoltzLikeProvider $provider */
            $base = $provider->baseUrl($network);
            if (!$base) {
                $out[$name] = null;
                $audit['providers'][] = self::auditFailure(
                    $name, $priorityIndex[$name] ?? null,
                    "no base URL for network '{$network}'"
                );
                continue;
            }
            $endpointUrl = rtrim($base, '/') . self::REVERSE_PAIR_PATH;
            // Validate up front so curl_multi handles a hostile/private-IP
            // provider URL the same way the serial path does.
            try {
                $validated = \SafeHttp::validateUrl(
                    $endpointUrl,
                    \SafeHttp::privateEndpointsAllowed()
                );
            } catch (\Throwable $e) {
                $out[$name] = null;
                $audit['providers'][] = self::auditFailure(
                    $name, $priorityIndex[$name] ?? null,
                    "URL rejected: {$e->getMessage()}"
                );
                continue;
            }
            $ch = curl_init($endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SEC,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE        => $validated['resolve'],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$name] = $ch;
        }

        if (!empty($handles)) {
            $deadline = microtime(true) + $timeoutSec;
            $running = null;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) break;
                    // -1 return on some platforms means "no fds to wait on"; sleep briefly to avoid spin.
                    if (curl_multi_select($multi, $remaining) === -1) {
                        usleep(20_000);
                    }
                }
            } while ($running > 0 && $status === CURLM_OK);
        }

        foreach ($handles as $name => $ch) {
            $err = curl_error($ch);
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            [$result, $error] = self::interpretBoltzLikeResponse($name, $body, $err, $code);
            $out[$name] = $result;
            $audit['providers'][] = self::auditEntry($name, $priorityIndex[$name] ?? null, $result, $error);
        }
        curl_multi_close($multi);
    }

    /**
     * @return array{0: ?SwapPairInfo, 1: ?string}
     */
    private static function interpretBoltzLikeResponse(string $name, $body, string $err, int $code): array {
        if ($err !== '') {
            return [null, self::REVERSE_PAIR_PATH . " failed: {$err}"];
        }
        if ($body === false || $body === null || $body === '') {
            return [null, self::REVERSE_PAIR_PATH . ': empty response (likely timeout)'];
        }
        if ($code >= 400) {
            return [null, self::REVERSE_PAIR_PATH . " -> HTTP {$code}"];
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return [null, self::REVERSE_PAIR_PATH . ': malformed JSON'];
        }
        try {
            return [BoltzLikeProvider::parseReversePairInfo($decoded, $name), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    private static function fetchSequential(
        string $name,
        SwapProvider $provider,
        string $network,
        array &$out,
        array &$audit,
        array $priorityIndex
    ): void {
        $result = null;
        $error = null;
        try {
            $result = $provider->getReversePairInfo($network);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $out[$name] = $result;
        $audit['providers'][] = self::auditEntry($name, $priorityIndex[$name] ?? null, $result, $error);
    }

    private static function auditEntry(string $name, ?int $priority, ?SwapPairInfo $info, ?string $error): array {
        if ($info === null) {
            return self::auditFailure($name, $priority, $error);
        }
        return [
            'provider'                 => $name,
            'priority_index'           => $priority,
            'reachable'                => true,
            'fee_percent'              => $info->feePercent,
            'lockup_fee_sats'          => $info->lockupFeeSats,
            'claim_fee_estimate_sats'  => $info->claimFeeEstimateSats,
            'min_sats'                 => $info->minSats,
            'max_sats'                 => $info->maxSats,
            'pair_hash'                => $info->pairHash,
            'error'                    => null,
        ];
    }

    private static function auditFailure(string $name, ?int $priority, ?string $error): array {
        return [
            'provider'       => $name,
            'priority_index' => $priority,
            'reachable'      => false,
            'error'          => $error,
        ];
    }

    /**
     * Compute the apples-to-apples total cost for a given target on-chain
     * amount given a provider quote. Used by the ranking algorithm and
     * stored in the audit trail.
     */
    public static function totalCostSats(SwapPairInfo $info, int $targetSats): int {
        $percentSats = (int)ceil($targetSats * $info->feePercent / 100.0);
        return $percentSats + $info->lockupFeeSats + $info->claimFeeEstimateSats;
    }

    public static function lastAuditTrail(): ?array {
        return self::$lastAudit;
    }

    public static function resetAudit(): void {
        self::$lastAudit = null;
    }

    /**
     * Caller hook (SwapProviderFactory::rankedForStore) to record the ranking
     * decision into the audit trail before it gets persisted.
     */
    public static function annotateAudit(array $extra): void {
        if (self::$lastAudit === null) {
            self::$lastAudit = ['providers' => []];
        }
        self::$lastAudit = array_merge(self::$lastAudit, $extra);
    }

    /**
     * Attach computed total-cost / in-range info to a provider's audit entry
     * after the caller has applied the target-amount-specific rules.
     */
    public static function annotateProvider(string $providerName, array $extra): void {
        if (self::$lastAudit === null) return;
        foreach (self::$lastAudit['providers'] ?? [] as $i => $entry) {
            if (($entry['provider'] ?? null) === $providerName) {
                self::$lastAudit['providers'][$i] = array_merge($entry, $extra);
            }
        }
    }
}
