<?php
/**
 * Shared harness for the Greenfield handler tests (not a test itself).
 *
 * api.php answers by echoing and exiting, which a test cannot observe. These stand-ins
 * for its response helpers throw instead, carrying the status and body; requireStore()
 * and requirePermission() mirror api.php's.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

if (!defined('CASHUPAY_DATA_DIR')) {
    $dataDir = sys_get_temp_dir() . '/cashupay-api-' . bin2hex(random_bytes(8));
    mkdir($dataDir, 0700, true);
    define('CASHUPAY_DATA_DIR', $dataDir);
}

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/security.php';

final class ApiResponse extends Exception {
    public function __construct(public int $status, public array $data) {
        parent::__construct("HTTP $status");
    }
}

function jsonResponse(array $data, int $status = 200): never {
    throw new ApiResponse($status, $data);
}

function errorResponse(string $code, string $message, int $status = 400): never {
    throw new ApiResponse($status, ['code' => $code, 'message' => $message]);
}

function requireStore(array $auth, array $params): string {
    $storeId = $params['storeId'] ?? '';
    $keyStore = (string)($auth['store_id'] ?? '');
    if ($storeId === '' || $keyStore === '' || !hash_equals($keyStore, (string)$storeId)) {
        errorResponse('unauthorized', 'API key is not authorized for this store', 403);
    }
    return (string)$storeId;
}

function requirePermission(array $auth, string $permission): void {
    if (!Auth::hasPermission($auth, $permission)) {
        errorResponse('unauthorized', 'API key lacks required permission: ' . $permission, 403);
    }
}

/** Call a handler; returns [status, body]. A handler that exit()s is a test failure. */
function callApi(string $handler, array $auth, array $params = [], array $body = []): array {
    try {
        $handler($auth, $params, $body);
    } catch (ApiResponse $r) {
        return [$r->status, $r->data];
    }
    fwrite(STDERR, "FAIL: $handler returned without a response\n");
    exit(1);
}

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}
