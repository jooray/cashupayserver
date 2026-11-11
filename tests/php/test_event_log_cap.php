<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$mint = 'https://capped.example.com';
$store = 's_cap';
make_store($store, $mint);

// Each MINT_PROTOCOL_ERROR logs both WITHDRAW_FAILURE and (first time)
// DISABLED_PENDING_SUCCESS. After the first one, subsequent log entries are
// just the failure rows. We deliberately add MANY more than the cap.
for ($i = 0; $i < 1100; $i++) {
    MintReliability::recordWithdrawFailure(
        $mint, null, $store,
        MintReliability::KIND_MINT_PROTOCOL_ERROR,
        'protocol fail #' . $i
    );
}

$count = (int)Database::fetchOne(
    "SELECT COUNT(*) AS c FROM mint_event_log WHERE mint_url = ?",
    [$mint]
)['c'];

assert_eq(MintReliability::EVENT_LOG_CAP_PER_MINT, $count,
    'event log strictly capped at ' . MintReliability::EVENT_LOG_CAP_PER_MINT);

// The cap should retain the most recent rows, not the oldest.
$newest = Database::fetchOne(
    "SELECT details FROM mint_event_log
     WHERE mint_url = ? AND event_type = ?
     ORDER BY timestamp DESC, id DESC LIMIT 1",
    [$mint, MintReliability::EVENT_WITHDRAW_FAILURE]
);
assert_eq('protocol fail #1099', $newest['details'], 'newest event retained');

echo "event_log_cap: ok\n";
