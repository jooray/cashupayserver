<?php
/**
 * Boltz swap-provider (https://api.docs.boltz.exchange).
 *
 * Mainnet is public. For regtest, set the swaps_boltz_regtest_url site
 * config key to point at a local Boltz-Backend instance (e.g. via
 * https://github.com/BoltzExchange/regtest). Boltz does not run a public
 * testnet endpoint anymore.
 */

require_once __DIR__ . '/boltz_like_provider.php';
require_once __DIR__ . '/../config.php';

final class BoltzSwapProvider extends BoltzLikeProvider {
    public function getName(): string { return 'boltz'; }

    public function baseUrl(string $network): ?string {
        return match ($network) {
            'mainnet' => 'https://api.boltz.exchange',
            'testnet' => null, // discontinued upstream
            'signet'  => null,
            'regtest' => Config::get('swaps_boltz_regtest_url', null),
            default   => null,
        };
    }
}
