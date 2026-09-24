<?php
/**
 * GET /api-keys/current must satisfy the WooCommerce gateway's key check.
 *
 * On settings save the gateway calls getCurrent() and rejects the key unless every
 * permission names exactly one store ("perm:storeId") and the set equals its required
 * list plus optional ones. It then skips webhook creation, so orders are never marked
 * paid. The two checks below are copied from btcpay-greenfield-for-woocommerce
 * (src/Helper/GreenfieldApiAuthorization.php).
 *
 * Run: php tests/greenfield_key_permissions.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require_once dirname(__DIR__) . '/includes/api/stores.php';

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

const WC_REQUIRED = [
    'btcpay.store.canviewinvoices', 'btcpay.store.cancreateinvoice',
    'btcpay.store.canviewstoresettings', 'btcpay.store.canmodifyinvoices',
];
const WC_OPTIONAL = ['btcpay.store.cancreatenonapprovedpullpayments', 'btcpay.store.webhooks.canmodifywebhooks'];

function wcHasSingleStore(array $permissions): bool {
    $storeId = null;
    foreach ($permissions as $perms) {
        if (2 !== count($exploded = explode(':', $perms))) return false;
        if ($storeId === $exploded[1]) continue;
        if ($storeId === null) { $storeId = $exploded[1]; continue; }
        return false;
    }
    return true;
}

function wcHasRequiredPermissions(array $permissions): bool {
    $names = array_diff(array_map(fn($p) => explode(':', $p)[0], $permissions), WC_OPTIONAL);
    return empty(array_merge(array_diff(WC_REQUIRED, $names), array_diff($names, WC_REQUIRED)));
}

function wcAccepts(array $auth): bool {
    $perms = greenfieldPermissions($auth);
    return wcHasSingleStore($perms) && wcHasRequiredPermissions($perms);
}

echo "Keys the WooCommerce gateway must accept\n";
check(wcAccepts(['store_id' => 'store_x', 'permissions' => ['*']]), 'an admin-created key (*)');
check(!in_array('btcpay.store.cancreatenonapprovedpullpayments:store_x',
    greenfieldPermissions(['store_id' => 'store_x', 'permissions' => ['*']]), true),
    'an admin key does not claim refunds (pull payments), which this server lacks');
check(wcAccepts(['store_id' => 'store_x', 'permissions' => [
    'btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices', 'btcpay.store.canmodifyinvoices',
    'btcpay.store.canviewstoresettings', 'btcpay.store.webhooks.canmodifywebhooks',
]]), 'the one-click WooCommerce key made by setup');
check(wcAccepts(['store_id' => 'store_x', 'permissions' => array_merge(WC_REQUIRED, WC_OPTIONAL)]),
    'a key paired by the gateway itself (required + optional)');
check(wcAccepts(['store_id' => 'store_x', 'permissions' => [
    'btcpay.store.canviewinvoices:store_x', 'btcpay.store.cancreateinvoice:store_x',
    'btcpay.store.canviewstoresettings:store_x', 'btcpay.store.canmodifyinvoices:store_x',
]]), 'a key whose stored permissions already carry the store scope');

echo "Keys it must still reject\n";
check(!wcAccepts(['store_id' => 'store_x', 'permissions' => [
    'btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices',
]]), 'a key missing required permissions is reported honestly');

echo "All Greenfield key-permission checks passed.\n";
