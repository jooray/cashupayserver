<?php
/**
 * CashuPayServer - Centralized URL Helper
 *
 * All URL generation logic in one place, driven by the configured base URL
 * and URL routing mode.
 */

require_once __DIR__ . '/desktop.php';

class Urls {
    /**
     * Human-readable label for a URL routing mode.
     *
     * Centralised so the admin settings card and the setup summary render the
     * same wording. Falls back to the current configured mode when none given.
     */
    public static function urlModeLabel(?string $mode = null): string {
        $mode = $mode ?? Config::getUrlMode();
        switch ($mode) {
            case 'clean':  return 'Clean URLs';
            case 'direct': return 'Direct URLs';
            default:       return 'Router.php URLs';
        }
    }

    /**
     * Get the server URL for e-commerce integration (BTCPay API base).
     * This is the URL e-commerce plugins should use as "BTCPay Server URL".
     */
    public static function server(): string {
        // Only router mode needs the /router.php front-controller prefix. In
        // both clean (rewrite-to-router.php) and direct (.htaccess rewrites
        // /api/v1 straight to api.php) modes the API is reachable at the bare
        // base URL.
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();

        return $mode === 'router' ? $base . '/router.php' : $base;
    }

    /**
     * Get the admin dashboard URL
     */
    public static function admin(): string {
        // Clean mode serves the SPA at the extension-less /admin route (the
        // front-controller rewrite forwards it to router.php -> admin.php).
        // Absolute so it is a valid redirect target from index.php. Direct and
        // router modes keep the relative 'admin.php', which resolves to the
        // real file from the directory index under both.
        if (Config::getUrlMode() === 'clean') {
            return rtrim(Config::getBaseUrl(), '/') . '/admin';
        }
        return 'admin.php';
    }

    /**
     * Get the setup wizard URL.
     *
     * Returns an absolute URL so it resolves correctly when called from
     * admin views that live under a deeper path (e.g. /admin/stores after
     * path-based view routing). Mirrors the absolute-URL convention used
     * by server(), payment(), cron(), and receive().
     */
    public static function setup(): string {
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();
        if ($mode === 'clean')  return $base . '/setup';
        if ($mode === 'router') return $base . '/router.php/setup.php';
        return $base . '/setup.php'; // direct
    }

    /**
     * Get the URL for static assets (JS, CSS, etc.)
     *
     * @param string $subpath Path within assets/ directory (e.g., 'js/', 'css/')
     * @return string Base URL for assets
     */
    public static function assets(string $subpath = ''): string {
        // Absolute (base-rooted) URL rather than a page-relative 'assets/...'.
        // With path-based admin routing the SPA is served at sub-paths like
        // /admin/dashboard and /admin/stats, where a relative 'assets/...'
        // resolves to /admin/assets/... and 404s — which silently broke
        // chart.min.js (blank stats charts), mint-discovery, animated-qr and
        // flag images. A base-rooted URL loads correctly from any sub-path.
        return Config::getBaseUrl() . '/assets/' . $subpath;
    }

    /**
     * Get the URL for static images.
     *
     * @param string $subpath Path within images/ directory (e.g., 'payment-methods/strike.png')
     * @return string Full URL to the image
     */
    public static function images(string $subpath = ''): string {
        // Base-rooted ABSOLUTE URL, not a page-relative 'images/...'. In clean
        // mode the payment page is served at the sub-path /payment/{id}, where a
        // relative 'images/payment-methods/foo.svg' resolves to
        // /payment/images/... and 404s — which silently blanked the "Pay with …"
        // wallet logos. Mirrors assets(): a base-rooted URL loads from any path.
        return Config::getBaseUrl() . '/images/' . $subpath;
    }

    /**
     * Get the API base URL (same as server URL for API calls)
     */
    public static function api(): string {
        return rtrim(self::server(), '/');
    }

    /**
     * Get the payment page URL
     *
     * @param string $invoiceId Invoice ID (optional)
     * @return string Payment page URL
     */
    public static function payment(string $invoiceId = ''): string {
        $base = rtrim(Config::getBaseUrl(), '/');
        // Clean mode uses the pretty /payment/{id} route (router.php maps the
        // path tail into $_GET['id']); other modes hit payment.php directly.
        if (Config::getUrlMode() === 'clean') {
            return $invoiceId ? $base . '/payment/' . rawurlencode($invoiceId) : $base . '/payment';
        }
        $file = $base . '/payment.php';
        return $invoiceId ? $file . '?id=' . urlencode($invoiceId) : $file;
    }

    /**
     * Should the payment page offer a "Share" button for the invoice URL?
     *
     * A share link only makes sense when the copied URL could open on someone
     * else's device. Two cases where it can't:
     *   - the Windows desktop package: its built-in server listens on
     *     127.0.0.1 only, so there is no shareable link at all;
     *   - a base URL whose host is loopback (localhost / 127.0.0.0/8 / ::1):
     *     any local install, regardless of OS.
     *
     * The parameters are test seams; production callers pass nothing and get
     * the live Desktop detection and configured base URL.
     */
    public static function paymentLinkShareable(
        ?bool $isWindowsDesktop = null, ?string $baseUrl = null
    ): bool {
        $isWindowsDesktop = $isWindowsDesktop ?? Desktop::isWindowsDesktop();
        if ($isWindowsDesktop) {
            return false;
        }
        $baseUrl = $baseUrl ?? Config::getBaseUrl();
        $host = (string)(parse_url($baseUrl, PHP_URL_HOST) ?? '');
        if ($host === '') {
            // No host at all (relative or malformed base URL) — nothing a
            // third party could resolve, so nothing worth sharing.
            return false;
        }
        return !self::isLoopbackHost($host);
    }

    /**
     * Is a URL host (as returned by parse_url()) a loopback / unroutable
     * address? Covers the localhost name (incl. RFC 6761 *.localhost), the
     * whole 127.0.0.0/8 IPv4 block, IPv6 ::1 (parse_url keeps the brackets),
     * and 0.0.0.0, which is listen-shorthand rather than a reachable host.
     */
    public static function isLoopbackHost(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if ($host === '::1' || $host === '0.0.0.0') {
            return true;
        }
        return (bool)preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host);
    }

    /**
     * Get the public self-serve invoice page URL for a store (/pay/{storeId}).
     *
     * Mirrors setup(): in router mode the pretty path is served through
     * router.php; in direct mode we link straight to the file with a query
     * param so it works without any URL rewrites.
     */
    public static function selfServe(string $storeId): string {
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();
        if ($mode === 'clean')  return $base . '/pay/' . rawurlencode($storeId);
        if ($mode === 'router') return $base . '/router.php/pay/' . rawurlencode($storeId);
        return $base . '/pay.php?store=' . urlencode($storeId); // direct
    }

    /**
     * Get the API key authorization (pairing) URL
     *
     * @param array $params Query parameters for the authorization request
     * @return string Full pairing URL with parameters
     */
    public static function pairing(array $params = []): string {
        $serverUrl = rtrim(self::server(), '/');
        $url = $serverUrl . '/api-keys/authorize';

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Build the standard BTCPay pairing URL with test permissions
     */
    public static function pairingTest(): string {
        return self::pairing([
            'applicationName' => 'Test Connection',
            'permissions' => [
                'btcpay.store.canviewinvoices',
                'btcpay.store.cancreateinvoice',
                'btcpay.store.webhooks.canmodifywebhooks',
            ],
            'strict' => 'true',
        ]);
    }

    /**
     * Get the cron/background task URL
     */
    public static function cron(): string {
        return rtrim(Config::getBaseUrl(), '/') . '/cron.php';
    }

    /**
     * Get the receive endpoint URL (NUT-18 payment requests)
     */
    public static function receive(): string {
        return rtrim(Config::getBaseUrl(), '/') . '/receive.php';
    }

    /**
     * Get all URLs as JSON for JavaScript consumption
     */
    public static function toJson(): string {
        return json_encode([
            'server' => self::server(),
            'admin' => self::admin(),
            'setup' => self::setup(),
            'assets' => self::assets(),
            'assetsJs' => self::assets('js/'),
            'api' => self::api(),
            'pairing' => self::pairing(),
            'pairingTest' => self::pairingTest(),
            'cron' => self::cron(),
            'receive' => self::receive(),
        ]);
    }

    /**
     * Whether the admin SPA may emit path-style view URLs
     * (/admin/<view>, /admin.php/<view>) for this request, as opposed to the
     * query form (admin.php?view=<view>) that works on every host.
     *
     * Path URLs are only used where the host provably routes them: clean
     * mode's front controller was verified by the setup wizard's probe, and a
     * request that itself ARRIVED carrying PATH_INFO proves the serving host
     * forwards path tails to admin.php. A bare admin.php request in any other
     * mode must get query URLs: PATH_INFO-hostile hosts (a stock nginx
     * WordPress config — Local WP, most managed nginx — or php -S) execute
     * only real *.php URLs, and a path-style URL there falls through the web
     * server into the surrounding site's 404 page with no way back into the
     * admin.
     *
     * Pure (no Config/global reads) so tests/php can pin the matrix; the live
     * caller is admin.php's view-routing block.
     */
    public static function adminUsesPathUrls(?string $pathInfo, string $urlMode): bool {
        return $urlMode === 'clean' || ($pathInfo !== null && $pathInfo !== '');
    }

    /**
     * Get site base URL (for security tests and redirects)
     */
    public static function siteBase(): string {
        return rtrim(Config::getBaseUrl(), '/');
    }
}
