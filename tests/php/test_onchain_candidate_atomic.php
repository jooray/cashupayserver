<?php
/**
 * The static-mode manual-candidate list is a JSON column written from three
 * racing places (an invoice's own poll, competitors' appends, and manual-
 * attribution scrubs). appendManualCandidate / scrubCandidateGlobally now do
 * their read-modify-write under the write lock (beginImmediate) so concurrent
 * writers can't clobber each other and drop a candidate UTXO.
 *
 * We can't spin real threads here, but we cover the behaviour the fix must
 * preserve — appends ACCUMULATE (never overwrite), dedupe, scrub removes from
 * every invoice — and the transaction hygiene the lock requires: no call may
 * leak an open transaction (which would wedge every later write on the shared
 * PDO).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';

fresh_db();
make_store('s1');

// Three invoices sharing one static address + amount (the ambiguity setup).
foreach (['I', 'J', 'K'] as $id) {
    Database::insert('invoices', [
        'id' => $id, 'store_id' => 's1', 'status' => 'New',
        'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
        'payment_rail' => 'onchain',
        'onchain_address' => 'bc1qstatic', 'onchain_amount_sat' => 5000,
        'created_at' => time(), 'expiration_time' => time() + 3600,
    ]);
}

$append = new ReflectionMethod('OnchainPayments', 'appendManualCandidate');
$append->setAccessible(true);
$scrub = new ReflectionMethod('OnchainPayments', 'scrubCandidateGlobally');
$scrub->setAccessible(true);

function cand(string $txid, int $vout): array {
    return ['txid' => $txid, 'vout' => $vout, 'amount_sat' => 5000,
            'confirmations' => 0, 'block_height' => null, 'first_seen_at' => 1000];
}
function candidates_of(string $id): array {
    $row = Database::fetchOne("SELECT onchain_manual_candidates, onchain_needs_manual_confirmation FROM invoices WHERE id = ?", [$id]);
    $list = $row['onchain_manual_candidates'] ? json_decode($row['onchain_manual_candidates'], true) : [];
    return [$list, (int)$row['onchain_needs_manual_confirmation']];
}

// Two DIFFERENT UTXOs appended to I must BOTH survive (the lost-update bug
// would drop one). This is the accumulate-not-clobber invariant.
$append->invoke(null, 'I', cand('aa', 0));
assert_false(Database::inTransaction(), 'no leaked tx after first append');
$append->invoke(null, 'I', cand('bb', 1));
assert_false(Database::inTransaction(), 'no leaked tx after second append');
[$list, $flag] = candidates_of('I');
assert_eq(2, count($list), 'both distinct UTXOs accumulated on I');
assert_eq(1, $flag, 'manual-confirmation flag set');

// Duplicate append is a no-op (and must not leak a tx via its early return).
$append->invoke(null, 'I', cand('aa', 0));
assert_false(Database::inTransaction(), 'no leaked tx after dedup early-return');
[$list] = candidates_of('I');
assert_eq(2, count($list), 'duplicate append deduped');

// Same UTXO listed on two competitors (the pollInvoice competitor loop).
$append->invoke(null, 'J', cand('aa', 0));
$append->invoke(null, 'K', cand('aa', 0));
[$lj] = candidates_of('J');
[$lk] = candidates_of('K');
assert_eq(1, count($lj), 'J holds the shared candidate');
assert_eq(1, count($lk), 'K holds the shared candidate');

// Scrubbing aa:0 removes it from every invoice that held it, atomically.
$scrub->invoke(null, 'aa', 0);
assert_false(Database::inTransaction(), 'no leaked tx after scrub');
[$li, $fi] = candidates_of('I');
assert_eq(1, count($li), 'I keeps only bb:1 after scrub of aa:0');
assert_eq('bb', $li[0]['txid'], 'remaining candidate is bb');
assert_eq(1, $fi, 'I still flagged (bb:1 remains)');
[$lj, $fj] = candidates_of('J');
assert_eq(0, count($lj), 'J emptied by scrub');
assert_eq(0, $fj, 'J manual flag cleared when list empties');

echo "PASS test_onchain_candidate_atomic\n";
exit(0);
