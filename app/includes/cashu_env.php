<?php
/**
 * Environment gate for Cashu operations.
 *
 * CashuWallet needs bignum math: ext-gmp OR ext-bcmath (either works — see
 * cashu-wallet-php/CashuWallet.php, which throws only when both are missing).
 * Mirrors the OnchainWallet / ClinkClient / NwcClient ::environmentError()
 * convention: function_exists, not extension_loaded, because hardened hosts
 * disable functions while the extension still reports loaded.
 *
 * Distinct from OnchainWallet::environmentError(): that one requires GMP
 * specifically (xpub parsing via bitwasp/bitcoin has no BCMath fallback), so
 * "GMP missing but BCMath present" leaves Cashu, noffers, and NWC working
 * while on-chain/swaps are dead.
 */
final class CashuEnv {
    /** Null when Cashu can run; otherwise an operator-actionable message. */
    public static function environmentError(): ?string {
        if (PHP_INT_SIZE < 8) {
            return 'This server runs 32-bit PHP; Cashu needs 64-bit integers.';
        }
        if (!function_exists('gmp_init') && !function_exists('bcadd')) {
            return 'Cashu needs PHP\'s GMP or BCMath extension; neither is '
                . 'available (or both are disabled) on this server. Ask your '
                . 'host to enable php-gmp or php-bcmath.';
        }
        return null;
    }
}
