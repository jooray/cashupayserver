<?php
/**
 * Library — LM-3 (mint side): mint() requires exactly one signature per output
 * (an empty/short list keeps the journal and stores nothing), reconciles a lost
 * response through the quote (ISSUED -> NUT-09 restore of the journaled plan),
 * is idempotent once the quote completed locally, and recoverPendingMints()
 * finishes or retires stale mint journals.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
require __DIR__ . '/lib_mint_stub.php';

use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\ProofState;
use Cashu\Wallet;

$stub = lib_mint_stub_start();
$w = lib_stub_wallet($stub);
$storage = $w->getStorage();

// ---- empty signature list is refused ------------------------------------------------
$quote = $w->requestMintQuote(10);
lib_mint_stub_script($stub, [
    'mint_quotes' => [$quote->quote => ['state' => 'PAID', 'amount' => 10, 'force' => true]],
    'overrides' => ['POST /v1/mint/bolt11' => ['status' => 200, 'body' => ['signatures' => []]]],
]);
$threw = null;
try { $w->mint($quote->quote, 10); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException, 'empty signatures -> CashuProtocolException');
assert_true(str_contains($threw->getMessage(), '0 signatures for 2 outputs'), 'message names the shortfall: ' . $threw->getMessage());
assert_eq([], $storage->getProofsByQuoteId($quote->quote), 'nothing stored');
assert_not_null($storage->getPendingOperationById('mint:' . $quote->quote), 'journal kept for retry');
assert_eq(0, $w->getBalance(), 'balance untouched');

// Short list (1 of 2) is refused the same way.
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/mint/bolt11' => ['status' => 200, 'body' => ['signatures' => [['id' => LIB_STUB_KEYSET, 'amount' => 2, 'C_' => '02' . str_repeat('11', 32)]]]]]], false);
$threw = null;
try { $w->mint($quote->quote, 10); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException, 'short signature list refused');

// Wrong-amount signature is refused before anything is stored.
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/mint/bolt11' => ['status' => 200, 'body' => ['signatures' => [
    ['id' => LIB_STUB_KEYSET, 'amount' => 4, 'C_' => '02' . str_repeat('11', 32)],
    ['id' => LIB_STUB_KEYSET, 'amount' => 8, 'C_' => '02' . str_repeat('11', 32)],
]]]]], false);
$threw = null;
try { $w->mint($quote->quote, 10); } catch (CashuException $e) { $threw = $e; }
assert_true($threw instanceof CashuProtocolException, 'wrong denomination refused');
assert_eq([], $storage->getProofsByQuoteId($quote->quote), 'still nothing stored');

// The retry reuses the journaled counter range (byte-identical outputs).
$journal = $storage->getPendingOperationById('mint:' . $quote->quote);
lib_mint_stub_script($stub, ['overrides' => []], false);
lib_mint_stub_reset_requests($stub);
$proofs = $w->mint($quote->quote, 10);
assert_eq(10, Wallet::sumProofs($proofs), 'retry minted 10');
assert_null($storage->getPendingOperationById('mint:' . $quote->quote), 'journal retired');
assert_eq($journal['data']['counter_start'] + 2, $storage->getCounter(LIB_STUB_KEYSET), 'no extra counters burned by retries');
// Idempotent: a second mint() for the completed quote returns the stored proofs
// without contacting the mint's issue endpoint again.
lib_mint_stub_reset_requests($stub);
$again = $w->mint($quote->quote, 10);
assert_eq(array_map(fn($p) => $p->secret, $proofs), array_map(fn($p) => $p->secret, $again), 'completed quote returns the same proofs');
assert_eq(0, count(lib_mint_stub_requests_for($stub, 'POST /v1/mint/bolt11')), 'no second issue request');
assert_eq(10, $w->getBalance(), 'balance 10');

// ---- lost response: mint signed, 500 returned, quote says ISSUED -> NUT-09 ---------
$q2 = $w->requestMintQuote(6);
lib_mint_stub_script($stub, [
    'mint_quotes' => [$q2->quote => ['amount' => 6]],
    'overrides' => ['POST /v1/mint/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'issue']],
]);
lib_mint_stub_reset_requests($stub);
$proofs = $w->mint($q2->quote, 6);
assert_eq(6, Wallet::sumProofs($proofs), 'lost response: proofs recovered via NUT-09');
assert_eq(1, count(lib_mint_stub_requests_for($stub, 'POST /v1/restore')), 'restore was used');
assert_eq(16, $w->getBalance(), 'balance 16');
assert_null($storage->getPendingOperationById('mint:' . $q2->quote), 'journal retired after recovery');

// ---- recoverPendingMints(): ISSUED journal -> proofs stored, journal gone ----------
$q3 = $w->requestMintQuote(3);
lib_mint_stub_script($stub, [
    'mint_quotes' => [$q3->quote => ['amount' => 3]],
    'overrides' => [
        'POST /v1/mint/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0], 'side_effect' => 'issue'],
        'GET /v1/mint/quote/bolt11/' . $q3->quote => ['status' => 500, 'body' => ['detail' => 'db', 'code' => 0]],
    ],
]);
try { $w->mint($q3->quote, 3); } catch (CashuException $e) { }
assert_not_null($storage->getPendingOperationById('mint:' . $q3->quote), 'journal kept when the quote cannot be read');
lib_mint_stub_script($stub, ['mint_quotes' => [$q3->quote => ['amount' => 3]], 'overrides' => []]);
$r = $w->recoverPendingMints();
assert_eq(1, $r['recovered'], 'ISSUED journal recovered');
assert_eq(3, $r['amount'], 'amount recovered');
assert_null($storage->getPendingOperationById('mint:' . $q3->quote), 'journal retired');
assert_eq(19, $w->getBalance(), 'balance 19');

// ---- recoverPendingMints(): expired UNPAID, nothing signed -> journal retired ------
$q4 = $w->requestMintQuote(2);
lib_mint_stub_script($stub, [
    'mint_quotes' => [$q4->quote => ['amount' => 2, 'state' => 'UNPAID', 'expiry' => time() - 5, 'force' => true]],
    'overrides' => ['POST /v1/mint/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0]]],
]);
try { $w->mint($q4->quote, 2); } catch (CashuException $e) { }
assert_not_null($storage->getPendingOperationById('mint:' . $q4->quote), 'journal kept');
lib_mint_stub_script($stub, ['overrides' => []], false);
$r = $w->recoverPendingMints();
assert_eq(1, $r['retired'], 'expired unpaid journal retired after NUT-09 found nothing');
assert_null($storage->getPendingOperationById('mint:' . $q4->quote), 'journal gone');
assert_eq(19, $w->getBalance(), 'balance unchanged');

// ---- recoverPendingMints(): quote unknown to the mint, nothing signed -> retired ---
$q5 = $w->requestMintQuote(1);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/mint/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0]]]]);
try { $w->mint($q5->quote, 1); } catch (CashuException $e) { }
$state = lib_mint_stub_state($stub);
unset($state['mints'][$q5->quote]);
lib_mint_stub_set_state($stub, $state);
lib_mint_stub_script($stub, ['overrides' => []]);
$r = $w->recoverPendingMints();
assert_eq(1, $r['unknown_quote'], 'forgotten quote counted');
assert_eq(1, $r['retired'], 'forgotten quote journal retired');

// ---- still-payable journal is left alone ------------------------------------------
$q6 = $w->requestMintQuote(1);
lib_mint_stub_script($stub, ['overrides' => ['POST /v1/mint/bolt11' => ['status' => 500, 'body' => ['detail' => 'gateway', 'code' => 0]]]]);
try { $w->mint($q6->quote, 1); } catch (CashuException $e) { }
lib_mint_stub_script($stub, ['overrides' => []]);
$r = $w->recoverPendingMints();
assert_eq(1, $r['still_pending'], 'unpaid unexpired journal kept');
assert_not_null($storage->getPendingOperationById('mint:' . $q6->quote), 'journal still there');

// ---- mint() refuses while the seed requires recovery -------------------------------
$dbPath2 = sys_get_temp_dir() . '/lib_unready_' . bin2hex(random_bytes(4)) . '.sqlite';
$w2 = new Wallet($stub['url'], 'sat', $dbPath2);
$w2->loadMint();
$w2->initializeForRestore(\Cashu\Mnemonic::generate());
assert_true($w2->requiresRecovery(), 'imported seed requires recovery');
$q7 = $w2->requestMintQuote(4);
$threw = null;
try { $w2->mint($q7->quote, 4); } catch (CashuException $e) { $threw = $e; }
assert_not_null($threw, 'mint refused while recovery is required');
assert_true(str_contains($threw->getMessage(), 'recovery'), 'refusal explains recovery: ' . $threw->getMessage());
assert_null($w2->getStorage()->getPendingOperationById('mint:' . $q7->quote), 'no journal written while unready');
@unlink($dbPath2);

echo "PASS test_lib_mint_recovery\n";
exit(0);
