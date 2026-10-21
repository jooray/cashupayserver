<?php
/**
 * Mint Helper Functions
 *
 * Utilities for testing and interacting with Cashu mints.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config.php';

class MintHelpers {
    /**
     * Check an operator-supplied mint URL before the server talks to it (SPB-SSRF-001).
     *
     * The server fetches whatever mint URL it is given, from setup and the dashboard.
     * Pointed at 127.0.0.1, a LAN router or a cloud metadata address, that turns the
     * "test this mint" button into a way to probe the server's private network and read
     * back error text from it. Mints live on the public internet, so a host that resolves
     * to a private or reserved address is refused unless the operator opted in with the
     * `allow_private_mints` setting (or CASHUPAY_ALLOW_PRIVATE_MINTS in
     * config.local.php), e.g. for a test mint on their own machine.
     *
     * @return string The canonical mint URL
     * @throws \InvalidArgumentException with a message fit to show the operator
     */
    public static function assertSafeMintUrl(string $mintUrl): string {
        require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

        try {
            $canonical = \Cashu\MintClient::canonicalizeMintUrl($mintUrl);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                'That does not look like a mint address. It should start with https:// (for example https://mint.example.com).'
            );
        }

        $target = Security::resolveTarget($canonical);
        if ($target === null) {
            throw new \InvalidArgumentException(
                'The mint\'s address could not be found. Check it for typos and try again.'
            );
        }

        if (!self::privateMintsAllowed()) {
            foreach ($target['ips'] as $ip) {
                if (!Security::isPublicIp($ip)) {
                    throw new \InvalidArgumentException(
                        'That address points to a private or local network, not a public mint. '
                        . 'Use a mint on the internet (its address usually starts with https://).'
                    );
                }
            }
        }

        return $canonical;
    }

    /**
     * Address policy for the wallet library's own connections (Cashu\ConnectGuard).
     *
     * Every mint request is resolved and checked again at connect time and pinned to
     * the checked addresses: assertSafeMintUrl() vets a URL once, but mint URLs are
     * stored and used for months, and a mint's DNS can later answer 127.0.0.1 (OPUS N2).
     * The LNURL callback a Lightning-address server names is held to the public-address
     * rule with no opt-out (OPUS N1). .onion hosts are not special-cased: this server has
     * no Tor proxy, so exempting them only created an unvetted path (MINIMAX 4.26).
     *
     * @return string[] CURLOPT_RESOLVE entries
     * @throws \RuntimeException when the address must not be reached
     */
    public static function connectGuard(string $url, string $purpose): array {
        if ($purpose === 'lnurl') {
            $target = Security::resolvePublicTarget($url);
            if ($target === null) {
                throw new \RuntimeException('the payment server\'s address is private, local or could not be found');
            }
            return $target['resolve'];
        }
        $target = self::privateMintsAllowed()
            ? Security::resolveTarget($url)
            : Security::resolvePublicTarget($url);
        if ($target === null) {
            throw new \RuntimeException('the mint\'s address is private, local or could not be found');
        }
        return $target['resolve'];
    }

    private static function privateMintsAllowed(): bool {
        if (defined('CASHUPAY_ALLOW_PRIVATE_MINTS') && CASHUPAY_ALLOW_PRIVATE_MINTS) {
            return true;
        }
        try {
            $flag = Config::get('allow_private_mints');
        } catch (\Throwable $e) {
            return false;
        }
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    /**
     * Test mint invoice expiry by creating a small test quote.
     *
     * Returns expiry in seconds, or error details.
     *
     * @param string $mintUrl The mint URL to test
     * @param string $unit The unit to use (e.g., 'sat', 'eur')
     * @return array Test results with expiry_seconds, warning flag, etc.
     */
    public static function testExpiry(string $mintUrl, string $unit): array {
        require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

        try {
            $mintUrl = self::assertSafeMintUrl($mintUrl);
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'warning' => false,
                'expiry_seconds' => null
            ];
        }

        try {
            // Get minimum amount from mint info (NUT-4)
            $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
            $info = $client->get('info');

            $minAmount = 1;
            foreach ($info['nuts']['4']['methods'] ?? [] as $method) {
                if (($method['unit'] ?? '') === $unit && ($method['method'] ?? '') === 'bolt11') {
                    $minAmount = max(1, (int)($method['min_amount'] ?? 1));
                    break;
                }
            }

            // Create test quote
            $wallet = new \Cashu\Wallet($mintUrl, $unit);
            $wallet->loadMint();
            $quote = $wallet->requestMintQuote($minAmount);

            // Calculate expiry in seconds from now
            $expirySeconds = ($quote->expiry ?? (time() + 900)) - time();

            // Consider expiry < 5 minutes as potentially problematic
            $isWarning = $expirySeconds < 300;

            return [
                'success' => true,
                'expiry_seconds' => $expirySeconds,
                'warning' => $isWarning,
                'min_amount' => $minAmount,
                'message' => $isWarning
                    ? "Invoice expiry is only " . round($expirySeconds / 60, 1) . " minutes. This may cause payment issues for customers."
                    : null
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'warning' => false,
                'expiry_seconds' => null
            ];
        }
    }

    /**
     * Get human-readable expiry description.
     *
     * @param int $seconds Expiry in seconds
     * @return string Human-readable description
     */
    public static function formatExpiry(int $seconds): string {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        } elseif ($seconds < 3600) {
            $mins = round($seconds / 60, 1);
            return "{$mins} minutes";
        } else {
            $hours = round($seconds / 3600, 1);
            return "{$hours} hours";
        }
    }
}
