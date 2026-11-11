<?php
/**
 * CashuPayServer - address policy for the wallet library's outbound HTTP.
 *
 * Every outbound HTTP call the server itself makes goes through SafeHttp
 * (scheme check, private-range refusal, DNS pin via CURLOPT_RESOLVE, no
 * redirect following). The wallet library (cashu-wallet-php) makes its own
 * curl requests — to mints (MintClient) and to LNURL-pay hosts
 * (LightningAddress) — and used to bypass all of that. The library exposes a
 * single hook, \Cashu\ConnectGuard::set(callable), consulted before every
 * curl_init: the callable receives the URL and a purpose ('mint' | 'lnurl')
 * and returns CURLOPT_RESOLVE pins, or throws to refuse the connection.
 *
 * This file wires SafeHttp into that hook, once per process:
 *
 *   mint  — private/loopback targets allowed only by the operator opt-in
 *           (Config allow_private_endpoints / CASHUPAY_ALLOW_PRIVATE_ENDPOINTS),
 *           the same switch a self-hosted mint already needs.
 *   lnurl — public targets only (a Lightning address host and the callback it
 *           names are chosen by third parties), except when the
 *           CASHU_LNURL_URL_TEMPLATE test seam is set or the operator opt-in
 *           is on: the e2e fixtures run LNURL mocks on 127.0.0.1.
 *
 * A refusal is a plain \Cashu\CashuException (not CashuNetworkException), so
 * it never counts as "mint unreachable" and never triggers offline
 * acceptance. The library may lag the server (it is a separate repository):
 * when \Cashu\ConnectGuard does not exist yet, install() is a no-op and is
 * retried on the next bootstrap file that loads.
 */

require_once __DIR__ . '/safe_http.php';

final class CashuGuard {
    private static bool $installed = false;

    /**
     * Register the policy with the library. Idempotent; silently does nothing
     * until the library class exists.
     */
    public static function install(): void {
        if (self::$installed) {
            return;
        }
        if (!class_exists('\Cashu\ConnectGuard', false)) {
            return;
        }
        \Cashu\ConnectGuard::set([self::class, 'resolve']);
        self::$installed = true;
    }

    /** True once the hook has been registered in this process. */
    public static function isInstalled(): bool {
        return self::$installed;
    }

    /**
     * The guard callable: CURLOPT_RESOLVE entries for $url, or a
     * \Cashu\CashuException when the policy refuses it.
     *
     * @return string[] e.g. ['mint.example:443:203.0.113.5']
     */
    public static function resolve(string $url, string $purpose): array {
        $allowPrivate = $purpose === 'mint'
            ? SafeHttp::privateEndpointsAllowed()
            : self::lnurlPrivateAllowed();
        try {
            return SafeHttp::validateUrl($url, $allowPrivate)['resolve'];
        } catch (SafeHttpResolveException $e) {
            // Host unknown / resolver down: that is "unreachable", not a policy
            // refusal. Surface it as the library's network exception so the
            // server keeps treating a DNS outage like any other mint outage
            // (offline acceptance, retries) instead of as a rejected token.
            // Still a throw — never an empty pin list, which would let curl do
            // its own unchecked lookup (rebinding: NXDOMAIN for us, a private
            // address for curl).
            if (class_exists('\Cashu\CashuNetworkException', false)) {
                throw new \Cashu\CashuNetworkException('Mint unreachable: ' . $e->getMessage());
            }
            throw new \RuntimeException('Mint unreachable: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
            if (class_exists('\Cashu\CashuException', false)) {
                throw new \Cashu\CashuException('Connection refused by address policy: ' . $detail);
            }
            throw new \RuntimeException('Connection refused by address policy: ' . $detail);
        }
    }

    /**
     * LNURL hosts are public-only. The two exceptions are the e2e/test seam
     * (CASHU_LNURL_URL_TEMPLATE routes every address to a local mock) and the
     * operator's explicit private-endpoints opt-in.
     */
    public static function lnurlPrivateAllowed(): bool {
        $template = getenv('CASHU_LNURL_URL_TEMPLATE');
        if ($template !== false && $template !== '') {
            return true;
        }
        return SafeHttp::privateEndpointsAllowed();
    }
}
