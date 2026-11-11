<?php
/**
 * CashuPayServer Front Controller Router
 *
 * Supports two modes:
 *
 * 1. FRONT CONTROLLER MODE (shared hosting, no server config needed):
 *    URL: http://example.com/router.php/api/v1/stores/xxx/invoices
 *    Set BTCPay Server URL to: http://example.com/router.php
 *
 * 2. REWRITE MODE (with mod_rewrite or as PHP built-in server router):
 *    URL: http://example.com/api/v1/stores/xxx/invoices
 *    Usage: php -S localhost:8000 router.php
 *
 * Both modes work simultaneously.
 */

require_once __DIR__ . '/includes/http_status.php';

// =============================================================================
// SECURITY: Block access to sensitive paths
// =============================================================================

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

// Block any attempt to access sensitive files/directories
$blockedPatterns = [
    '#/data/#i',
    '#/data$#i',
    '#\.sqlite#i',
    '#\.db$#i',
    '#/\.#',              // Hidden files (.htaccess, .git, etc.)
    '#/config\.local\.php#i',
    '#/user_config\.php#i',   // Secrets (SMTP password, keys, update constants)
    '#/includes/.*\.php$#i',  // Direct access to include files via router
    '#/cashu-wallet-php/#i',  // Library internals
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $requestUri) || preg_match($pattern, $pathInfo)) {
        cashupay_status(403);
        die('Forbidden');
    }
}

// =============================================================================
// ROUTING
// =============================================================================

// Get path from PATH_INFO (front controller mode) or REQUEST_URI (rewrite mode)
// PATH_INFO is set when accessing /router.php/some/path
// For PHP built-in server (cli-server), always use REQUEST_URI as it's in rewrite mode
if (php_sapi_name() === 'cli-server') {
    // PHP built-in server: use REQUEST_URI
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Front-controller form under the built-in server too: the admin's
    // Request modal (and any client configured for 'router' URL mode) calls
    // /router.php/api/v1/... — php -S has no PATH_INFO for that, so the
    // prefix stayed in $uri, matched no route, and the request 404ed.
    if (preg_match('#^/router\.php(/.*)?$#', (string)$uri, $m)) {
        $uri = $m[1] ?? '/';
    }
} else {
    // Apache/nginx: check for front-controller mode (PATH_INFO) first
    $uri = !empty($_SERVER['PATH_INFO'])
        ? $_SERVER['PATH_INFO']
        : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}

// Remove leading/trailing slashes for consistent matching
$uri = '/' . trim($uri, '/');

// -----------------------------------------------------------------------------
// API Routes: /api/v1/*
// -----------------------------------------------------------------------------
if (preg_match('#^/api/v1/#', $uri)) {
    // Pass the URI path for api.php to parse
    $_SERVER['PATH_INFO'] = $uri;
    require __DIR__ . '/api.php';
    exit;
}

// -----------------------------------------------------------------------------
// API Key Authorization: /api-keys/authorize, and its real-file .php form.
// The WordPress plugin always targets authorize.php (rewrite-hostile hosts
// 404 the extension-less spelling), and the root-level $allowedFiles pass
// below matches basename() only — without this, a router-mode deployment
// (PHP built-in server, nginx front controller) would 404 the .php form.
// -----------------------------------------------------------------------------
if (preg_match('#^/api-keys/authorize(?:\.php)?$#', $uri)) {
    require __DIR__ . '/api-keys/authorize.php';
    exit;
}

// -----------------------------------------------------------------------------
// Payment page: /payment or /payment/{id}
// -----------------------------------------------------------------------------
if (preg_match('#^/payment(?:/(.+))?$#', $uri, $matches)) {
    if (!empty($matches[1])) {
        $_GET['id'] = $matches[1];
    }
    require __DIR__ . '/payment.php';
    exit;
}

// -----------------------------------------------------------------------------
// BTCPay-compatible invoice URL: /i/{invoiceId}. BTCPay API clients (the
// WooCommerce Greenfield gateway among them) build this link themselves off
// the configured server URL when re-opening an existing invoice; BareBits'
// checkout page is payment.php, so serve it there. The .htaccess catch-all
// and the nginx @router front controller both forward /i/* here; the query
// transport (api.php?cashupay_path=/i/{id}) answers with a redirect instead.
// -----------------------------------------------------------------------------
if (preg_match('#^/i/([^/]+)$#', $uri, $matches)) {
    $_GET['id'] = rawurldecode($matches[1]);
    require __DIR__ . '/payment.php';
    exit;
}

// -----------------------------------------------------------------------------
// Self-serve invoice page: /pay/{storeId} — public, unauthenticated page where
// a customer creates and pays their own invoice (gated per store; see pay.php).
// -----------------------------------------------------------------------------
if (preg_match('#^/pay/([^/]+)$#', $uri, $matches)) {
    $_GET['store'] = $matches[1];
    require __DIR__ . '/pay.php';
    exit;
}

// -----------------------------------------------------------------------------
// Admin: /admin, /admin.php, or /admin[.php]/<view> (e.g. /admin/invoices)
// The sub-path is the SPA view slug — forwarded via PATH_INFO so admin.php
// can pick it up uniformly. The optional .php form keeps router-mode links
// like /router.php/admin.php/<view> working after canonicalization.
// -----------------------------------------------------------------------------
if (preg_match('#^/admin(?:\.php)?(?:/(.+))?$#', $uri, $matches)) {
    $_SERVER['PATH_INFO'] = '/' . ($matches[1] ?? '');
    require __DIR__ . '/admin.php';
    exit;
}

// -----------------------------------------------------------------------------
// Setup: /setup
// -----------------------------------------------------------------------------
if (preg_match('#^/setup$#', $uri)) {
    require __DIR__ . '/setup.php';
    exit;
}

// -----------------------------------------------------------------------------
// Cron: /cron
// -----------------------------------------------------------------------------
if (preg_match('#^/cron$#', $uri)) {
    require __DIR__ . '/cron.php';
    exit;
}

// -----------------------------------------------------------------------------
// Recover: /recover — token-gated rollback after a broken auto-update.
// Kept routable in front-controller mode so a deployed install with no
// URL rewrites can still hit it.
// -----------------------------------------------------------------------------
if (preg_match('#^/recover$#', $uri)) {
    require __DIR__ . '/recover.php';
    exit;
}

// -----------------------------------------------------------------------------
// Update: /update — isolated, crash-resilient auto-update endpoint. Kept
// routable in front-controller mode so the dedicated cron line works without
// URL rewrites. See update.php for the full flow.
// -----------------------------------------------------------------------------
if (preg_match('#^/update$#', $uri)) {
    require __DIR__ . '/update.php';
    exit;
}

// -----------------------------------------------------------------------------
// Health: /health — bootstrap probe used by the updater to verify an applied
// update before keeping it.
// -----------------------------------------------------------------------------
if (preg_match('#^/health$#', $uri)) {
    require __DIR__ . '/health.php';
    exit;
}

// -----------------------------------------------------------------------------
// Provision: /provision — one-time credential handshake for orchestrated
// installs (see provision.php). Routable so it works in every URL mode.
// -----------------------------------------------------------------------------
if (preg_match('#^/provision$#', $uri)) {
    require __DIR__ . '/provision.php';
    exit;
}

// -----------------------------------------------------------------------------
// SSO: /sso — login-token handoff for managed installs (see sso.php).
// -----------------------------------------------------------------------------
if (preg_match('#^/sso$#', $uri)) {
    require __DIR__ . '/sso.php';
    exit;
}

// -----------------------------------------------------------------------------
// Product image: /product-image — serves uploaded product images (public).
// -----------------------------------------------------------------------------
if (preg_match('#^/product-image$#', $uri)) {
    require __DIR__ . '/product-image.php';
    exit;
}

// -----------------------------------------------------------------------------
// Static assets: /assets/* and /images/*
//
// /images/* covers the payment-method wallet logos referenced from the clean
// /payment/{id} page. Apache serves these real files directly (the front
// controller rewrite is gated on !-f), but keep them handled here too so the
// front controller stays self-sufficient under any SAPI (e.g. the PHP
// built-in server) and router-mode deep paths.
// -----------------------------------------------------------------------------
if (preg_match('#^/(?:assets|images)/#', $uri)) {
    $file = __DIR__ . $uri;
    if (file_exists($file) && is_file($file)) {
        // Determine content type
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $contentTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];
        if (isset($contentTypes[$ext])) {
            header('Content-Type: ' . $contentTypes[$ext]);
        }
        readfile($file);
        exit;
    }
}

// -----------------------------------------------------------------------------
// Root: / -> redirect to admin or setup
// -----------------------------------------------------------------------------
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    exit;
}

// -----------------------------------------------------------------------------
// Direct .php file access (for backwards compatibility)
// Only allow specific public files
// -----------------------------------------------------------------------------
$allowedFiles = ['index.php', 'admin.php', 'setup.php', 'payment.php', 'pay.php', 'api.php', 'cron.php', 'receive.php', 'recover.php', 'update.php', 'health.php', 'product-image.php', 'provision.php', 'sso.php'];
$requestedFile = basename($uri);

if (in_array($requestedFile, $allowedFiles)) {
    $file = __DIR__ . '/' . $requestedFile;
    if (file_exists($file)) {
        require $file;
        exit;
    }
}

// -----------------------------------------------------------------------------
// For PHP built-in server: return false to let it handle static files
// -----------------------------------------------------------------------------
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . $uri;
    if (file_exists($file) && is_file($file) && !preg_match('#\.php$#', $uri)) {
        return false; // Let PHP's built-in server handle it
    }
}

// -----------------------------------------------------------------------------
// 404 Not Found
// -----------------------------------------------------------------------------
cashupay_status(404);
echo "Not found";
