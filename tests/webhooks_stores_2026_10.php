<?php
/**
 * Webhook outbox and store read permissions, 2026-10 audits.
 *
 * - Deleting a webhook, or changing its URL, secret or enabled flag, cancels its queued
 *   deliveries; redelivery is refused for a deleted webhook and uses the current URL and
 *   secret otherwise (DS41 L-9).
 * - Events for one invoice reach one webhook in the order they happened, even when an
 *   older delivery is in backoff (OPUS N7).
 * - Store reads need btcpay.store.canviewstoresettings, as in BTCPay (DS41 L-2), and the
 *   keys this app issues for WooCommerce still have it.
 *
 * Run: php tests/webhooks_stores_2026_10.php
 */

declare(strict_types=1);

// Child mode: DELETE answers with exit(), so it runs in its own process.
if (($argv[1] ?? '') === 'delete') {
    define('CASHUPAY_DATA_DIR', $argv[2]);
    require_once __DIR__ . '/api_test_harness.php';
    require_once dirname(__DIR__) . '/includes/api/webhooks.php';
    handleDeleteWebhook(['store_id' => 'store_a', 'permissions' => ['btcpay.store.webhooks.canmodifywebhooks']],
        ['storeId' => 'store_a', 'webhookId' => $argv[3]], []);
    exit(3); // not reached
}

require_once __DIR__ . '/api_test_harness.php';
require_once dirname(__DIR__) . '/includes/invoice.php';
require_once dirname(__DIR__) . '/includes/api/stores.php';
require_once dirname(__DIR__) . '/includes/api/webhooks.php';

Database::initialize();
Database::insert('stores', [
    'id' => 'store_a', 'name' => 'A', 'wallet_account_id' => 'wa_a', 'created_at' => Database::timestamp(),
]);
Security::setResolverForTesting(fn(string $host) => in_array($host, ['shop.example.com', 'new.example.com'], true)
    ? ['93.184.216.34'] : []);

$key = ['store_id' => 'store_a', 'permissions' => ['btcpay.store.webhooks.canmodifywebhooks']];
$p = ['storeId' => 'store_a'];

function makeWebhook(array $key, array $p): string {
    [$status, $wh] = callApi('handleCreateWebhook', $key, $p, ['url' => 'https://shop.example.com/hook', 'secret' => 'old-secret']);
    check($status === 200, 'webhook created');
    return $wh['id'];
}

function fire(string $type, string $invoiceId = 'inv_1'): void {
    WebhookSender::fireEvent('store_a', $type, [
        'id' => $invoiceId, 'store_id' => 'store_a', 'status' => 'New', 'amount' => '1', 'currency' => 'SAT',
        'created_at' => time(), 'expiration_time' => time() + 600,
    ]);
}

function pending(string $webhookId): array {
    return Database::fetchAll(
        'SELECT * FROM webhook_deliveries WHERE webhook_id = ? AND delivered_at IS NULL AND attempts < 80
           AND signing_secret IS NOT NULL ORDER BY rowid',
        [$webhookId]
    );
}

$claim = new ReflectionMethod(WebhookSender::class, 'claimNextDelivery');

echo "Changing a webhook cancels its queue (DS41 L-9)\n";
$wh = makeWebhook($key, $p);
fire('InvoiceCreated');
check(count(pending($wh)) === 1, 'one delivery queued');
callApi('handleUpdateWebhook', $key, $p + ['webhookId' => $wh], ['authorizedEvents' => ['everything' => true]]);
check(count(pending($wh)) === 1, 'changing only the event list keeps it');
callApi('handleUpdateWebhook', $key, $p + ['webhookId' => $wh], ['url' => 'https://shop.example.com/hook']);
check(count(pending($wh)) === 1, 'a PUT that repeats the same URL keeps it');
[$status] = callApi('handleUpdateWebhook', $key, $p + ['webhookId' => $wh], ['url' => 'https://new.example.com/hook']);
check($status === 200 && pending($wh) === [], 'a new URL cancels it');
$row = Database::fetchOne('SELECT * FROM webhook_deliveries WHERE webhook_id = ?', [$wh]);
check($row['signing_secret'] === null && str_contains((string)$row['response'], 'cancelled'),
    'the cancelled row keeps no secret and says why');
check($claim->invoke(null) === null, 'the outbox does not pick it up');

fire('InvoiceExpired');
callApi('handleUpdateWebhook', $key, $p + ['webhookId' => $wh], ['secret' => 'new-secret']);
check(pending($wh) === [], 'a new secret cancels the queue');
fire('InvoiceSettled');
callApi('handleUpdateWebhook', $key, $p + ['webhookId' => $wh], ['enabled' => false]);
check(pending($wh) === [], 'disabling cancels the queue');

echo "Redelivery uses the current URL and secret\n";
$old = Database::fetchOne('SELECT id FROM webhook_deliveries WHERE webhook_id = ? ORDER BY rowid LIMIT 1', [$wh])['id'];
[$status] = callApi('handleRedeliverWebhook', $key, $p + ['webhookId' => $wh, 'deliveryId' => $old]);
$row = Database::fetchOne('SELECT * FROM webhook_deliveries WHERE id = ?', [$old]);
check($status === 200 && $row['target_url'] === 'https://new.example.com/hook' && $row['signing_secret'] === 'new-secret',
    'a redelivered row goes to the webhook as it is now');

echo "Deleting a webhook cancels its queue and its redeliveries (DS41 L-9)\n";
$wh2 = makeWebhook($key, $p);
fire('InvoiceCreated', 'inv_2');
check(count(pending($wh2)) === 1, 'one delivery queued');
$out = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' delete ' . escapeshellarg(CASHUPAY_DATA_DIR)
    . ' ' . escapeshellarg($wh2) . ' 2>&1', $out, $exit);
check($exit === 0, 'DELETE answered (' . implode(' ', $out) . ')');
check(pending($wh2) === [], 'the queued delivery is cancelled');
$del = Database::fetchOne('SELECT id FROM webhook_deliveries WHERE webhook_id = ?', [$wh2])['id'];
[$status] = callApi('handleRedeliverWebhook', $key, $p + ['webhookId' => $wh2, 'deliveryId' => $del]);
check($status === 404, 'redelivery for a deleted webhook is refused');

echo "Per-invoice order (OPUS N7)\n";
Database::query('UPDATE webhook_deliveries SET delivered_at = 1');
$wh3 = makeWebhook($key, $p);
fire('InvoiceExpired', 'inv_3');
// The expiry notice failed once and is in backoff.
Database::query("UPDATE webhook_deliveries SET attempts = 1, next_attempt_at = ? WHERE webhook_id = ? AND event_type = 'InvoiceExpired'",
    [time() + 3600, $wh3]);
fire('InvoiceReceivedPayment', 'inv_3');
fire('InvoiceSettled', 'inv_3');
fire('InvoiceCreated', 'inv_4');
$first = $claim->invoke(null);
check($first !== null && $first['invoice_id'] === 'inv_4', 'another invoice is not held up');
Database::query('UPDATE webhook_deliveries SET delivered_at = ?, leased_until = NULL WHERE id = ?', [time(), $first['id']]);
check($claim->invoke(null) === null, 'newer events wait while the older InvoiceExpired is in backoff');
Database::query("UPDATE webhook_deliveries SET next_attempt_at = 0 WHERE webhook_id = ? AND event_type = 'InvoiceExpired'", [$wh3]);
$order = [];
while (($d = $claim->invoke(null)) !== null) {
    $order[] = $d['event_type'];
    Database::query('UPDATE webhook_deliveries SET delivered_at = ?, leased_until = NULL WHERE id = ?', [time(), $d['id']]);
}
check($order === ['InvoiceExpired', 'InvoiceReceivedPayment', 'InvoiceSettled'], 'then they go out in order: ' . implode(', ', $order));

fire('InvoiceExpired', 'inv_5');
Database::query("UPDATE webhook_deliveries SET attempts = 80 WHERE invoice_id = 'inv_5'");
fire('InvoiceSettled', 'inv_5');
$d = $claim->invoke(null);
check($d !== null && $d['event_type'] === 'InvoiceSettled', 'a row that was given up on holds nothing back');

echo "Store reads need canviewstoresettings (DS41 L-2)\n";
$narrow = ['store_id' => 'store_a', 'permissions' => ['btcpay.store.cancreateinvoice']];
[$status, $body] = callApi('handleGetStores', $narrow);
check($status === 200 && $body === [], 'GET /stores is empty for a key without it');
[$status] = callApi('handleGetStore', $narrow, $p);
check($status === 403, 'GET /stores/{id} is refused');
[$status] = callApi('handleStorePaymentMethods', $narrow, $p);
check($status === 403, 'GET /stores/{id}/payment-methods is refused');
foreach ([
    'an admin key (*)' => ['*'],
    'the auto-configured WooCommerce key' => [
        'btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices', 'btcpay.store.canmodifyinvoices',
        'btcpay.store.canviewstoresettings', 'btcpay.store.webhooks.canmodifywebhooks',
    ],
    'a key paired by the gateway (scoped)' => ['btcpay.store.canviewstoresettings:store_a', 'btcpay.store.cancreateinvoice:store_a'],
    'a store-settings manager' => ['btcpay.store.canmodifystoresettings'],
] as $what => $perms) {
    $k = ['store_id' => 'store_a', 'permissions' => $perms];
    [$s1, $list] = callApi('handleGetStores', $k);
    [$s2] = callApi('handleGetStore', $k, $p);
    [$s3] = callApi('handleStorePaymentMethods', $k, $p);
    check($s1 === 200 && count($list) === 1 && $s2 === 200 && $s3 === 200, "$what still reads the store");
}

echo "All webhook and store checks passed.\n";
