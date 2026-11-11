<?php
/**
 * WebhookSender::pruneDeliveries() (cron Task 10) is audit-log hygiene, NOT a
 * queue trim: it must keep the most recent N *terminal* rows and NEVER evict a
 * 'pending' row, which is still scheduled for retry. The old "keep last 1000 by
 * created_at, any status" logic could drop undelivered events during a burst or
 * a sustained merchant outage — the exact loss the outbox exists to prevent.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/webhook_sender.php';

fresh_db();
make_store('s1');

// A webhook row for the FK.
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's1', 'url' => 'https://example.test/hook',
    'secret' => 'x', 'events' => '["InvoiceSettled"]', 'enabled' => 1,
    'created_at' => time(),
]);

$n = 0;
function add_delivery(string $status, int $createdAt): void {
    global $n;
    Database::insert('webhook_deliveries', [
        'id' => 'd' . (++$n),
        'webhook_id' => 'wh1',
        'event_type' => 'InvoiceSettled',
        'payload' => '{}',
        'status' => $status,
        'created_at' => $createdAt,
    ]);
}

$base = time() - 100000;

// 5 OLD pending rows (these must SURVIVE, regardless of how old).
for ($i = 0; $i < 5; $i++) {
    add_delivery('pending', $base + $i);
}
// 1010 terminal rows, older-to-newer, mixed delivered/failed.
for ($i = 0; $i < 1010; $i++) {
    add_delivery($i % 2 ? 'failed' : 'delivered', $base + 100 + $i);
}

$deleted = WebhookSender::pruneDeliveries(1000);

// Only terminal rows beyond 1000 are removed: 1010 - 1000 = 10.
assert_eq(10, $deleted, 'pruned exactly the terminal overflow (1010 - 1000)');

$pending = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status='pending'"
)['c'] ?? 0);
assert_eq(5, $pending, 'all pending rows survived (never evicted)');

$terminal = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM webhook_deliveries WHERE status IN ('delivered','failed')"
)['c'] ?? 0);
assert_eq(1000, $terminal, 'terminal rows trimmed down to the keep limit');

// The deleted terminal rows must be the OLDEST ones (d6..d15 — the first 10
// terminal rows added after the 5 pending d1..d5).
assert_null(Database::fetchOne("SELECT id FROM webhook_deliveries WHERE id='d6'"), 'oldest terminal row evicted');
assert_not_null(Database::fetchOne("SELECT id FROM webhook_deliveries WHERE id='d16'"), '11th-oldest terminal row kept');

// Under the limit: no-op. 1000 terminal rows remain after the prune above; a
// keep target at/above that count must delete nothing.
assert_eq(0, WebhookSender::pruneDeliveries(1000), 'at limit -> nothing pruned');
assert_eq(0, WebhookSender::pruneDeliveries(5000), 'under limit -> nothing pruned');

echo "PASS test_webhook_prune_keeps_pending\n";
exit(0);
