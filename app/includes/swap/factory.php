<?php
/**
 * Factory: build SwapProvider instances in the configured preference order.
 *
 * Adding a new provider means:
 *   1. Implement SwapProvider (typically by extending BoltzLikeProvider).
 *   2. Add the name → class entry to {@see REGISTRY} below.
 *   3. The per-store swaps_provider_order can now include the new name.
 */

require_once __DIR__ . '/provider.php';
require_once __DIR__ . '/zeus_provider.php';
require_once __DIR__ . '/boltz_provider.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quote_fetcher.php';

final class SwapProviderFactory {
    /**
     * Default registry. Tests may override via {@see setRegistry()} to inject
     * mock providers; pass `null` to reset.
     */
    private const DEFAULT_REGISTRY = [
        'zeus'  => ZeusSwapProvider::class,
        'boltz' => BoltzSwapProvider::class,
    ];
    private static ?array $registryOverride = null;

    /**
     * Test seam: override the provider registry. Each value may be either a
     * class name (instantiated on demand) or a pre-built SwapProvider instance.
     */
    public static function setRegistry(?array $registry): void {
        self::$registryOverride = $registry;
    }

    private static function registry(): array {
        return self::$registryOverride ?? self::DEFAULT_REGISTRY;
    }

    /**
     * Build the store's configured providers in preference order (null
     * storeId → built-in default order). Unknown names in the configured
     * order are silently skipped (so misconfiguration doesn't brick invoice
     * creation).
     *
     * @return SwapProvider[]
     */
    public static function orderedForStore(?string $storeId): array {
        $out = [];
        $reg = self::registry();
        foreach (SwapsConfig::providerOrderForStore($storeId) as $name) {
            $entry = $reg[$name] ?? null;
            if ($entry === null) continue;
            $out[] = is_object($entry) ? $entry : new $entry();
        }
        return $out;
    }

    /**
     * Build a single provider by name. Returns null for unknown names.
     */
    public static function byName(string $name): ?SwapProvider {
        $entry = self::registry()[strtolower($name)] ?? null;
        if ($entry === null) return null;
        return is_object($entry) ? $entry : new $entry();
    }

    /**
     * All names that can appear in swaps_provider_order. Stable list used by
     * the admin UI dropdowns.
     *
     * @return string[]
     */
    public static function knownProviderNames(): array {
        return array_keys(self::registry());
    }

    /**
     * Reorder the configured providers for a specific swap attempt, applying
     * the auto-select-cheapest rule when enabled. Returns rows of
     * ['provider' => SwapProvider, 'quote' => ?SwapPairInfo] in the order
     * Invoice::trySwapCreate should try them.
     *
     * Algorithm: gather quotes from every reachable provider in parallel,
     * then walk the candidate set repeatedly — picking the cheapest survivor
     * when it beats the highest-priority survivor by more than the threshold,
     * else picking the highest-priority survivor. Providers whose quote
     * could not be fetched are appended at the end so the existing sequential
     * fallback still gets a chance with the full per-call timeout.
     *
     * @return array<int, array{provider: SwapProvider, quote: ?SwapPairInfo}>
     */
    public static function rankedForStore(?string $storeId, string $network, int $targetSats): array {
        SwapQuoteFetcher::resetAudit();
        $priorityList = self::orderedForStore($storeId);
        if (!$priorityList) return [];

        if (!SwapsConfig::autoSelectCheapestForStore($storeId)) {
            SwapQuoteFetcher::annotateAudit([
                'reason'        => 'auto_select_disabled',
                'target_sats'   => $targetSats,
                'ranked_order'  => array_map(fn($p) => $p->getName(), $priorityList),
                'chosen'        => $priorityList[0]->getName(),
                'threshold_pct' => SwapsConfig::autoSelectThresholdPctForStore($storeId),
            ]);
            return array_map(fn($p) => ['provider' => $p, 'quote' => null], $priorityList);
        }

        $threshold = SwapsConfig::autoSelectThresholdPctForStore($storeId);
        $quotes = SwapQuoteFetcher::fetchAll($priorityList, $network, 5);

        // Build candidate set: only reachable providers whose target is in range.
        $candidates = [];
        foreach ($priorityList as $idx => $provider) {
            $name = $provider->getName();
            $info = $quotes[$name] ?? null;
            if ($info === null) continue;
            $inRange = ($targetSats >= $info->minSats && $targetSats <= $info->maxSats);
            $totalCost = SwapQuoteFetcher::totalCostSats($info, $targetSats);
            SwapQuoteFetcher::annotateProvider($name, [
                'total_cost_sats' => $totalCost,
                'in_range'        => $inRange,
                'target_sats'     => $targetSats,
            ]);
            if (!$inRange) continue;
            $candidates[] = [
                'provider'        => $provider,
                'quote'           => $info,
                'priority_index'  => $idx,
                'total_cost_sats' => $totalCost,
            ];
        }

        // No usable quote: hand the whole priority list back so the
        // existing sequential fallback exercises every provider.
        if (empty($candidates)) {
            SwapQuoteFetcher::annotateAudit([
                'reason'        => 'all_quotes_failed',
                'target_sats'   => $targetSats,
                'threshold_pct' => $threshold,
                'ranked_order'  => array_map(fn($p) => $p->getName(), $priorityList),
                'chosen'        => null,
            ]);
            return array_map(fn($p) => ['provider' => $p, 'quote' => null], $priorityList);
        }

        // Recursive cheapest-vs-leader rule.
        $ranked = [];
        $picks = [];
        while (!empty($candidates)) {
            $leaderKey = self::indexOfMin($candidates, fn($c) => $c['priority_index']);
            $cheapKey  = self::indexOfMin(
                $candidates,
                fn($c) => $c['total_cost_sats'] * 1_000_000 + $c['priority_index']
            );
            $leader = $candidates[$leaderKey];
            $cheap  = $candidates[$cheapKey];
            // Strict greater-than threshold: cheapest must undercut leader by
            // MORE than threshold%, i.e. cheap < leader * (100 - threshold)/100.
            $strictlyCheaper = ($cheap['total_cost_sats'] * 100) < ($leader['total_cost_sats'] * (100 - $threshold));
            $pickKey = ($cheapKey !== $leaderKey && $strictlyCheaper) ? $cheapKey : $leaderKey;
            $pick = $candidates[$pickKey];
            $ranked[] = ['provider' => $pick['provider'], 'quote' => $pick['quote']];
            $picks[] = $pick['provider']->getName();
            unset($candidates[$pickKey]);
            $candidates = array_values($candidates);
        }

        // Append unreachable / out-of-range providers so they still get a
        // last-ditch sequential attempt (matches today's no-feature behaviour).
        // Out-of-range providers carry their cached quote so the range check
        // fires immediately without another HTTP round-trip.
        $rankedNames = array_flip($picks);
        foreach ($priorityList as $provider) {
            $name = $provider->getName();
            if (isset($rankedNames[$name])) continue;
            $ranked[] = ['provider' => $provider, 'quote' => $quotes[$name] ?? null];
        }

        $chosen = $picks[0] ?? null;
        $reason = self::reasonForChoice($chosen, $priorityList, $quotes, $threshold);
        SwapQuoteFetcher::annotateAudit([
            'reason'        => $reason,
            'target_sats'   => $targetSats,
            'threshold_pct' => $threshold,
            'ranked_order'  => array_map(fn($row) => $row['provider']->getName(), $ranked),
            'chosen'        => $chosen,
        ]);

        return $ranked;
    }

    private static function indexOfMin(array $arr, callable $keyFn): int {
        $bestKey = array_key_first($arr);
        $bestVal = $keyFn($arr[$bestKey]);
        foreach ($arr as $k => $v) {
            $vk = $keyFn($v);
            if ($vk < $bestVal) { $bestVal = $vk; $bestKey = $k; }
        }
        return $bestKey;
    }

    private static function reasonForChoice(?string $chosen, array $priorityList, array $quotes, int $threshold): string {
        if ($chosen === null) return 'all_quotes_failed';
        $priorityLeader = $priorityList[0]->getName();
        if ($chosen === $priorityLeader) return 'priority_leader';
        return 'cheapest_below_threshold';
    }
}
