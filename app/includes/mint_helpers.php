<?php
/**
 * Mint Helper Functions
 *
 * Utilities for testing and interacting with Cashu mints.
 */

class MintHelpers {
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
        } catch (Exception $e) {
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
