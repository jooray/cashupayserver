<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$mint = 'https://m.example.com';
$store = 'store_a';
make_store($store, $mint);

// 1. MINT_UNREACHABLE counts immediately, sets disabled_pending_success.
MintReliability::recordWithdrawFailure($mint, 'u@a.com', $store,
    MintReliability::KIND_MINT_UNREACHABLE, 'connection refused');
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(1, (int)$r['total_failures'], 'counter incremented');
assert_eq(1, (int)$r['disabled_pending_success'], 'disabled after failure');
assert_eq(0, (int)$r['permanently_disabled'], 'not yet permanent');

// 2. Successful withdraw clears disabled_pending_success, doesn't reset counter.
MintReliability::recordWithdrawSuccess($mint, 'u@a.com', $store);
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(1, (int)$r['total_failures'], 'lifetime survives a single success');
assert_eq(0, (int)$r['consecutive_failures'], 'consecutive reset');
assert_eq(0, (int)$r['disabled_pending_success'], 'inflows re-enabled');
assert_not_null($r['last_success_at'], 'last_success_at set');

// 3. Six MINT_PROTOCOL_ERROR failures should permanently disable.
for ($i = 0; $i < 6; $i++) {
    MintReliability::recordWithdrawFailure($mint, 'u@a.com', $store,
        MintReliability::KIND_MINT_PROTOCOL_ERROR, "fail #$i");
}
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(7, (int)$r['total_failures'], '1 + 6 = 7 lifetime');
assert_eq(1, (int)$r['permanently_disabled'], 'permanent after > 5');

// 4. Admin reset clears everything.
MintReliability::adminReenable($mint, 'tester');
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(0, (int)$r['total_failures']);
assert_eq(0, (int)$r['disabled_pending_success']);
assert_eq(0, (int)$r['permanently_disabled']);

// 5. INSUFFICIENT_BALANCE is a no-op for reliability.
$before = (int)Database::fetchOne("SELECT total_failures FROM mint_reliability WHERE mint_url = ?", [$mint])['total_failures'];
MintReliability::recordWithdrawFailure($mint, 'u@a.com', $store,
    MintReliability::KIND_INSUFFICIENT_BALANCE, 'not enough sats');
$after = (int)Database::fetchOne("SELECT total_failures FROM mint_reliability WHERE mint_url = ?", [$mint])['total_failures'];
assert_eq($before, $after, 'insufficient balance does not penalize the mint');

// 6. adminConfirmedBad increments counter and can tip into permanent disable.
for ($i = 0; $i < 5; $i++) {
    MintReliability::recordWithdrawFailure($mint, 'u@a.com', $store,
        MintReliability::KIND_MINT_PROTOCOL_ERROR, "bump $i");
}
MintReliability::adminConfirmedBad($mint, 'tester');
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(6, (int)$r['total_failures'], '5 + 1 admin = 6');
assert_eq(1, (int)$r['permanently_disabled'], 'admin confirmed bad tips into permanent');

// 7. Quote-side MINT_UNREACHABLE sets disabled_pending_success and increments,
//    quote-side success clears it.
$qmint = 'https://q.example.com';
make_store('store_q', $qmint);
MintReliability::recordQuoteFailure($qmint, 'store_q', MintReliability::KIND_MINT_UNREACHABLE, 'connection refused');
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$qmint]);
assert_eq(1, (int)$r['disabled_pending_success'], 'quote unreachable disables');
assert_eq(1, (int)$r['total_failures'], 'quote unreachable counts');

MintReliability::recordQuoteSuccess($qmint, 'store_q');
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$qmint]);
assert_eq(0, (int)$r['disabled_pending_success'], 'quote success clears disable');
assert_eq(1, (int)$r['total_failures'], 'quote success preserves lifetime counter');

// Quote-side PROTOCOL_ERROR does NOT count and does NOT disable (ambiguous).
$qmint2 = 'https://q2.example.com';
MintReliability::recordQuoteFailure($qmint2, 'store_q', MintReliability::KIND_MINT_PROTOCOL_ERROR, 'bad json');
$r = Database::fetchOne("SELECT * FROM mint_reliability WHERE mint_url = ?", [$qmint2]);
assert_eq(0, (int)$r['disabled_pending_success'], 'protocol error at quote does not disable');
assert_eq(0, (int)$r['total_failures'], 'protocol error at quote does not count');

// 8. isAvailableForNewInvoices reflects each flag.
assert_eq(false, MintReliability::isAvailableForNewInvoices($mint), 'permanent disable → unavailable');
MintReliability::adminReenable($mint, 'tester');
assert_eq(true, MintReliability::isAvailableForNewInvoices($mint), 're-enabled → available');

MintReliability::setTrustedListDisabled($mint, 'shady');
assert_eq(false, MintReliability::isAvailableForNewInvoices($mint), 'trusted_list_disabled → unavailable');
MintReliability::clearTrustedListDisabled($mint);
assert_eq(true, MintReliability::isAvailableForNewInvoices($mint), 'clearing trusted_list flag re-allows');

echo "state_machine: ok\n";
