<?php
/**
 * Zeus swap-provider (powered by a Boltz backend; see https://docs.zeusln.app/swaps/api).
 */

require_once __DIR__ . '/boltz_like_provider.php';

final class ZeusSwapProvider extends BoltzLikeProvider {
    private const URLS = [
        'mainnet' => 'https://swaps.zeuslsp.com/api',
        'testnet' => 'https://testnet-swaps.zeuslsp.com/api',
        // Zeus does not run a public signet/regtest endpoint.
        'signet'  => null,
        'regtest' => null,
    ];

    public function getName(): string { return 'zeus'; }

    public function baseUrl(string $network): ?string {
        return self::URLS[$network] ?? null;
    }
}
