<?php
/**
 * Greenfield input handling for webhooks and store creation.
 *
 * - Event lists must be lists of known event names (DS41-05); a scalar stored as-is used
 *   to stop every payment of the store from settling.
 * - A client-supplied webhook secret is used, as BTCPay does (OPUS-022).
 * - Wrong-typed values are a 400, not a TypeError (DS41-30).
 * - Only an admin key creates stores; a key for one store cannot (SPB-SEC-012).
 * - canmanageinvoicestatus (BTCPay 2.4.5) is accepted for invoice status (OPUS-014).
 *
 * Run: php tests/api_webhooks_and_stores.php
 */

declare(strict_types=1);

require_once __DIR__ . '/api_test_harness.php';
require_once dirname(__DIR__) . '/includes/invoice.php';
require_once dirname(__DIR__) . '/includes/api/stores.php';
require_once dirname(__DIR__) . '/includes/api/webhooks.php';
require_once dirname(__DIR__) . '/includes/api/invoices.php';

Database::initialize();
Database::insert('stores', [
    'id' => 'store_a', 'name' => 'A', 'wallet_account_id' => 'wa_a', 'created_at' => Database::timestamp(),
]);
Security::setResolverForTesting(fn(string $host) => $host === 'shop.example.com' ? ['93.184.216.34'] : []);

$shopKey = ['store_id' => 'store_a', 'permissions' => [
    'btcpay.store.webhooks.canmodifywebhooks', 'btcpay.store.canmodifystoresettings',
]];
$p = ['storeId' => 'store_a'];
$url = 'https://shop.example.com/?wc-api=btcpaygf_default';

echo "Webhook event lists\n";
foreach ([
    'a scalar specificEvents' => ['authorizedEvents' => ['specificEvents' => 'InvoiceSettled']],
    'a scalar events' => ['events' => 'InvoiceSettled'],
    'an unknown event name' => ['authorizedEvents' => ['specificEvents' => ['InvoiceHacked']]],
    'a non-string entry' => ['authorizedEvents' => ['specificEvents' => [['InvoiceSettled']]]],
    'an object instead of a list' => ['authorizedEvents' => ['specificEvents' => ['a' => 'InvoiceSettled']]],
    'nothing selected with everything=false' => ['authorizedEvents' => ['everything' => false, 'specificEvents' => []]],
    'a non-object authorizedEvents' => ['authorizedEvents' => 'all'],
] as $what => $body) {
    [$status] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => $url] + $body);
    check($status === 400, "refused: $what");
}
check((int)Database::fetchOne('SELECT COUNT(*) AS c FROM webhooks')['c'] === 0, 'nothing was stored');

[$status, $wh] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => $url, 'authorizedEvents' => [
    'everything' => false, 'specificEvents' => ['InvoiceSettled', 'InvoiceExpired', 'InvoicePaymentSettled'],
]]);
check($status === 200 && $wh['authorizedEvents']['specificEvents'] === ['InvoiceSettled', 'InvoiceExpired', 'InvoicePaymentSettled'],
    "the WooCommerce gateway's subscription is accepted as sent");
check($wh['authorizedEvents']['everything'] === false, 'and is not "everything"');
check(strlen($wh['secret']) === 64, 'without a secret in the request, one is generated and returned');

[$status, $all] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => $url, 'authorizedEvents' => ['everything' => true]]);
check($status === 200 && $all['authorizedEvents']['everything'] === true, 'everything=true subscribes to every event');

echo "Client-supplied secrets (OPUS-022)\n";
[$status, $own] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => $url, 'secret' => 'shop-chosen-secret']);
check($status === 200 && $own['secret'] === 'shop-chosen-secret', 'the secret the shop sent is the one used');
check(Database::fetchOne('SELECT secret FROM webhooks WHERE id = ?', [$own['id']])['secret'] === 'shop-chosen-secret',
    'and stored for signing');
[$status] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => $url, 'secret' => ['x']]);
check($status === 400, 'a non-string secret is refused');
[$status] = callApi('handleUpdateWebhook', $shopKey, $p + ['webhookId' => $own['id']], ['secret' => 'rotated']);
check($status === 200 && Database::fetchOne('SELECT secret FROM webhooks WHERE id = ?', [$own['id']])['secret'] === 'rotated',
    'PUT rotates the secret');

echo "Wrong-typed input is a 400, not a crash (DS41-30)\n";
[$status] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => ['https://shop.example.com/']]);
check($status === 400, 'an array URL on create');
[$status] = callApi('handleUpdateWebhook', $shopKey, $p + ['webhookId' => $wh['id']], ['url' => ['x']]);
check($status === 400, 'an array URL on update');
[$status] = callApi('handleUpdateWebhook', $shopKey, $p + ['webhookId' => $wh['id']], ['events' => 'InvoiceSettled']);
check($status === 400, 'a scalar event list on update');
[$status] = callApi('handleUpdateWebhook', $shopKey, $p + ['webhookId' => $wh['id']], ['enabled' => 'no']);
check($status === 400, 'a string for enabled');
[$status] = callApi('handleCreateWebhook', $shopKey, $p, ['url' => 'https://shop.example.com:6379/']);
check($status === 400, 'a non-web port on create');

echo "Store creation (SPB-SEC-012)\n";
[$status] = callApi('handleCreateStore', $shopKey, [], ['name' => 'Sneaky']);
check($status === 403, 'a store-scoped key with store-settings permission cannot create stores');
[$status] = callApi('handleCreateStore', ['store_id' => 'store_a', 'permissions' => ['btcpay.store.canmodifystoresettings:store_a']], [], ['name' => 'Sneaky']);
check($status === 403, 'nor with the permission scoped to its store');
[$status, $created] = callApi('handleCreateStore', ['store_id' => 'store_a', 'permissions' => ['*']], [], ['name' => '  Second shop ']);
check($status === 200 && $created['name'] === 'Second shop', 'an admin key can');
[$status] = callApi('handleCreateStore', ['store_id' => 'store_a', 'permissions' => ['*']], [], ['name' => ['x']]);
check($status === 400, 'a non-string name is a 400');

echo "Invoice status permission (OPUS-014)\n";
$statusOnly = ['store_id' => 'store_a', 'permissions' => ['btcpay.store.canmanageinvoicestatus:store_a']];
[$status, $body] = callApi('handleUpdateInvoiceStatus', $statusOnly, $p + ['invoiceId' => 'inv_missing'], ['status' => 'Invalid']);
check($status === 404, 'a key with only canmanageinvoicestatus passes the permission check');
[$status] = callApi('handleUpdateInvoiceStatus', ['store_id' => 'store_a', 'permissions' => ['btcpay.store.canmodifyinvoices']],
    $p + ['invoiceId' => 'inv_missing'], ['status' => 'Invalid']);
check($status === 404, 'canmodifyinvoices still implies it');
[$status] = callApi('handleUpdateInvoiceStatus', ['store_id' => 'store_a', 'permissions' => ['btcpay.store.canviewinvoices']],
    $p + ['invoiceId' => 'inv_missing'], ['status' => 'Invalid']);
check($status === 403, 'a view-only key does not');

echo "All webhook and store API checks passed.\n";
