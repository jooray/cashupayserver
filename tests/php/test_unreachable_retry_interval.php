<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$mint = 'https://retry.example.com';
$store = 'store_retry';
make_store($store, $mint);

// 1. MINT_UNREACHABLE at quote time gates the mint.
MintReliability::recordQuoteFailure($mint, $store,
    MintReliability::KIND_MINT_UNREACHABLE, 'connection refused');
assert_eq(false, MintReliability::isAvailableForNewInvoices($mint),
    'unreachable mint is gated immediately after failure');

// 2. Backdate last_failure_at past the retry interval — the gate should lift
//    even though disabled_pending_success is still set in the DB.
$pastTs = Database::timestamp() - MintReliability::MINT_UNREACHABLE_RETRY_INTERVAL_SEC - 60;
Database::query(
    "UPDATE mint_reliability SET last_failure_at = ? WHERE mint_url = ?",
    [$pastTs, $mint]
);
$row = Database::fetchOne("SELECT disabled_pending_success FROM mint_reliability WHERE mint_url = ?", [$mint]);
assert_eq(1, (int)$row['disabled_pending_success'], 'flag stays set in DB');
assert_eq(true, MintReliability::isAvailableForNewInvoices($mint),
    'past the retry interval the mint is re-admitted as a candidate');

// 3. A successful quote in the retry window clears the flag for real.
MintReliability::recordQuoteSuccess($mint, $store);
$row = Database::fetchOne(
    "SELECT disabled_pending_success FROM mint_reliability WHERE mint_url = ?",
    [$mint]
);
assert_eq(0, (int)$row['disabled_pending_success'],
    'successful quote during retry window clears the gate');
assert_eq(true, MintReliability::isAvailableForNewInvoices($mint),
    'mint fully available after success');

// 4. If the retry fails again, the gate re-stamps and we wait another interval.
MintReliability::recordQuoteFailure($mint, $store,
    MintReliability::KIND_MINT_UNREACHABLE, 'still down');
assert_eq(false, MintReliability::isAvailableForNewInvoices($mint),
    'fresh failure re-gates');
$row = Database::fetchOne(
    "SELECT last_failure_at, total_failures FROM mint_reliability WHERE mint_url = ?",
    [$mint]
);
assert_true(((int)$row['last_failure_at']) >= (Database::timestamp() - 5),
    'last_failure_at refreshed to roughly now');
assert_eq(2, (int)$row['total_failures'],
    'each unreachable retry counts toward lifetime cap');

// 5. permanently_disabled is NOT affected by the retry window.
MintReliability::adminConfirmedBad($mint, 'tester'); // tick #3
MintReliability::adminConfirmedBad($mint, 'tester'); // tick #4
MintReliability::adminConfirmedBad($mint, 'tester'); // tick #5
MintReliability::adminConfirmedBad($mint, 'tester'); // tick #6 → permanent
$row = Database::fetchOne(
    "SELECT permanently_disabled FROM mint_reliability WHERE mint_url = ?",
    [$mint]
);
assert_eq(1, (int)$row['permanently_disabled'], 'hit permanent disable');
// Backdate last_failure even past the interval — permanent disable still wins.
Database::query(
    "UPDATE mint_reliability SET last_failure_at = ? WHERE mint_url = ?",
    [Database::timestamp() - 99999, $mint]
);
assert_eq(false, MintReliability::isAvailableForNewInvoices($mint),
    'permanent disable overrides the retry window');

// 6. LIGHTNING_WALLET_ERROR gate is NOT subject to interval retry — the gate
//    must wait for the agreed resolution paths so we don't add funds back to
//    a mint with stranded balance behind a broken LNURL.
$wmint = 'https://wallet-err.example.com';
$wstore = 'store_w';
make_store($wstore, $wmint);
MintReliability::recordWithdrawFailure($wmint, 'addr@x.com', $wstore,
    MintReliability::KIND_LIGHTNING_WALLET_ERROR, 'Lightning payment failed');
assert_eq(false, MintReliability::isAvailableForNewInvoices($wmint),
    'wallet-error gate set');

// Even backdated way past the interval, it's still gated.
Database::query(
    "UPDATE mint_reliability SET last_failure_at = ? WHERE mint_url = ?",
    [Database::timestamp() - 99999, $wmint]
);
assert_eq(false, MintReliability::isAvailableForNewInvoices($wmint),
    'wallet-error gate does NOT auto-clear from retry interval');

// 7. trusted_list_disabled also overrides the retry window.
$tmint = 'https://trusted-banned.example.com';
MintReliability::ensureRecord($tmint);
MintReliability::setTrustedListDisabled($tmint, 'compromised');
// Simulate a stale MINT_UNREACHABLE history alongside the trusted-list block.
Database::query(
    "UPDATE mint_reliability
     SET disabled_pending_success = 1,
         last_failure_kind = ?,
         last_failure_at = ?
     WHERE mint_url = ?",
    [MintReliability::KIND_MINT_UNREACHABLE,
     Database::timestamp() - 99999, $tmint]
);
assert_eq(false, MintReliability::isAvailableForNewInvoices($tmint),
    'trusted_list_disabled overrides the retry window');

echo "unreachable_retry_interval: ok\n";
