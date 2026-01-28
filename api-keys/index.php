<?php
/**
 * Router for /api-keys/ endpoints
 *
 * Handles routing for PHP built-in server and servers without mod_rewrite.
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/urls.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');

// Route /api-keys/authorize to authorize.php
if (preg_match('#/api-keys/authorize$#', $path) || $path === '/api-keys') {
    require __DIR__ . '/authorize.php';
    exit;
}

// If accessing /api-keys/ directly, redirect to authorize
if (preg_match('#/api-keys/?$#', $path)) {
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $pairingUrl = Urls::pairing();
    if ($queryString) {
        $pairingUrl .= (strpos($pairingUrl, '?') !== false ? '&' : '?') . $queryString;
    }
    header('Location: ' . $pairingUrl);
    exit;
}

// 404 for unknown routes
http_response_code(404);
echo 'Not found';
