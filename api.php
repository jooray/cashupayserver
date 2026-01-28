<?php
/**
 * CashuPayServer - API Router
 *
 * BTCPay Server Greenfield API compatible endpoints.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

// Set JSON content type
header('Content-Type: application/json');

// Handle CORS for API clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check if setup is complete
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo json_encode([
        'code' => 'service-unavailable',
        'message' => 'CashuPayServer setup not complete'
    ]);
    exit;
}

// M1: API Rate limiting (100 requests per minute per IP)
$clientIp = Security::getClientIp();
if (!Security::checkRateLimit('api', $clientIp, 100)) {
    http_response_code(429);
    echo json_encode([
        'code' => 'rate-limited',
        'message' => 'Too many requests. Please wait.'
    ]);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];

// Use PATH_INFO if set (from router.php), otherwise parse REQUEST_URI
if (!empty($_SERVER['PATH_INFO'])) {
    $path = $_SERVER['PATH_INFO'];
} else {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Remove base path to get API path
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptPath !== '/' && str_starts_with($path, $scriptPath)) {
        $path = substr($path, strlen($scriptPath));
    }

    // Remove /api.php prefix if present
    $path = preg_replace('#^/api\.php#', '', $path);
}

// Ensure path starts with /
if (!str_starts_with($path, '/')) {
    $path = '/' . $path;
}

// API version prefix
if (!str_starts_with($path, '/api/v1/')) {
    // Also support paths without /api prefix for BTCPay compatibility
    if (str_starts_with($path, '/v1/')) {
        $path = '/api' . $path;
    } else {
        http_response_code(404);
        echo json_encode([
            'code' => 'not-found',
            'message' => 'API endpoint not found'
        ]);
        exit;
    }
}

// Remove /api/v1 prefix for routing
$route = substr($path, 7); // Remove "/api/v1"

// Load API handlers
require_once __DIR__ . '/includes/api/stores.php';
require_once __DIR__ . '/includes/api/invoices.php';
require_once __DIR__ . '/includes/api/webhooks.php';

// Route matching
$routes = [
    // Server info (no auth required)
    'GET /server/info' => 'handleServerInfo',

    // Stores
    'GET /stores' => 'handleGetStores',
    'POST /stores' => 'handleCreateStore',
    'GET /stores/{storeId}' => 'handleGetStore',
    'DELETE /stores/{storeId}' => 'handleDeleteStore',

    // Invoices
    'POST /stores/{storeId}/invoices' => 'handleCreateInvoice',
    'GET /stores/{storeId}/invoices' => 'handleGetInvoices',
    'GET /stores/{storeId}/invoices/{invoiceId}' => 'handleGetInvoice',
    'POST /stores/{storeId}/invoices/{invoiceId}/status' => 'handleUpdateInvoiceStatus',

    // Webhooks
    'POST /stores/{storeId}/webhooks' => 'handleCreateWebhook',
    'GET /stores/{storeId}/webhooks' => 'handleGetWebhooks',
    'GET /stores/{storeId}/webhooks/{webhookId}' => 'handleGetWebhook',
    'PUT /stores/{storeId}/webhooks/{webhookId}' => 'handleUpdateWebhook',
    'DELETE /stores/{storeId}/webhooks/{webhookId}' => 'handleDeleteWebhook',
];

/**
 * Match route and extract parameters
 */
function matchRoute(string $method, string $path, array $routes): ?array {
    foreach ($routes as $pattern => $handler) {
        [$routeMethod, $routePath] = explode(' ', $pattern, 2);

        if ($method !== $routeMethod) {
            continue;
        }

        // Convert route pattern to regex
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            // Extract named parameters
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $handler, 'params' => $params];
        }
    }

    return null;
}

/**
 * Get JSON request body
 */
function getRequestBody(): array {
    $body = file_get_contents('php://input');
    if (empty($body)) {
        return [];
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

/**
 * Send JSON response
 */
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send error response
 */
function errorResponse(string $code, string $message, int $status = 400): void {
    jsonResponse(['code' => $code, 'message' => $message], $status);
}

/**
 * Server info endpoint (no auth required)
 */
function handleServerInfo(): void {
    jsonResponse([
        'version' => '1.0.0',
        'serverTime' => time(),
        'supportedPaymentMethods' => ['BTC-LightningNetwork'],
        'isCashuPayServer' => true,
    ]);
}

// Match and execute route
$match = matchRoute($method, $route, $routes);

if ($match === null) {
    errorResponse('not-found', 'API endpoint not found', 404);
}

// Server info doesn't require auth
if ($match['handler'] === 'handleServerInfo') {
    handleServerInfo();
}

// All other endpoints require authentication
$auth = Auth::requireApiAuth();

// Call handler
$handler = $match['handler'];
$params = $match['params'];

if (function_exists($handler)) {
    $handler($auth, $params, getRequestBody());
} else {
    errorResponse('not-implemented', 'Endpoint not implemented', 501);
}
