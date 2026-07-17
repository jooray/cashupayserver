<?php
/**
 * Integration test: CashuPayServer against a keyset-v2 mint.
 *
 * Boots the mock mint (tests/mock_mint_router.php) on a local port, then runs
 * the real CashuPayServer invoice flow against it:
 *   create invoice -> pay quote -> poll -> mint -> proofs stored
 * and verifies keyset-v2 specifics end to end:
 *   - HMAC (NUT-13 v2) deterministic secrets, recomputable from the seed
 *   - post-deprecation mint quote accounting (amount_paid/amount_issued, no state)
 *   - DLEQ verification on every signature (mock always includes proofs)
 *   - V4 token serialization for v2-keyset proofs
 *   - NUT-00 short keyset ID resolution on receive
 *   - input_fee_ppk accounting on swap
 *
 * Run: php tests/integration_keyset_v2.php
 */

declare(strict_types=1);

$dataDir = sys_get_temp_dir() . '/cashupay-v2-integration-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/invoice.php';

use Cashu\TokenSerializer;
use Cashu\Wallet;

function check(bool $cond, string $what): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $what\n");
        exit(1);
    }
    echo "  ok: $what\n";
}

// --- Start the mock mint -------------------------------------------------
$port = random_int(18100, 18999);
$mintUrl = "http://127.0.0.1:$port";
$statePath = $dataDir . '/mock-mint-state.json';
$server = proc_open(
    ['php', '-S', "127.0.0.1:$port", __DIR__ . '/mock_mint_router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    dirname(__DIR__),
    ['MOCK_MINT_STATE' => $statePath] + getenv()
);
check(is_resource($server), 'mock mint process started');
register_shutdown_function(function () use ($server, $dataDir) {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($dataDir));
});

// Wait for the server to accept connections
$up = false;
for ($i = 0; $i < 50; $i++) {
    $info = @json_decode((string)@file_get_contents("$mintUrl/v1/info"), true);
    if (!empty($info['name'])) {
        $up = true;
        break;
    }
    usleep(100_000);
}
check($up, 'mock mint responds on /v1/info');

// --- Bootstrap CashuPayServer ---------------------------------------------
Database::initialize();
Config::set('setup_complete', '1');

$mnemonic = 'half depart obvious quality work element tank gorilla view sugar picture humble';
$storeId = 'store_v2test';
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'V2 Test Store',
    'wallet_account_id' => Database::generateWalletAccountId(),
    'mint_url' => $mintUrl,
    'mint_unit' => 'sat',
    'seed_phrase' => $mnemonic,
    'exchange_fee_percent' => 0,
    'price_provider_primary' => 'coingecko',
    'price_provider_secondary' => 'binance',
    'created_at' => Database::timestamp(),
]);

// The mock also serves an ACTIVE legacy v1 keyset ("00deadbeefcafe12") whose
// ID does not re-derive from its keys — like many production mints. Loading
// must not throw, and the v2 keyset must stay the one used for outputs.
$wallet = Invoice::initializeWalletForStore($storeId, false);
check(true, 'wallet loads despite non-derivable legacy v1 keyset id');
$keysetId = $wallet->getActiveKeysetId();
check(strlen($keysetId) === 66 && str_starts_with($keysetId, '01'), "mint presents a v2 keyset id ($keysetId)");
check($wallet->getInputFeePpk($keysetId) === 100, 'input_fee_ppk read from v2 keyset metadata');
check($wallet->getInputFeePpk('00deadbeefcafe12') === 100, 'legacy v1 keyset fee metadata loaded');

// --- Invoice flow ----------------------------------------------------------
echo "invoice flow:\n";
$invoice = Invoice::create($storeId, ['amount' => 21, 'currency' => 'sat']);
check(!empty($invoice['id']) && !empty($invoice['quote_id']), 'invoice created with mint quote');
check(str_starts_with((string)$invoice['bolt11'], 'lnbcmock'), 'lightning invoice returned');

// New-style quote (no `state` field) must read as unpaid before payment.
Invoice::pollSingleQuote($invoice['id']);
$pending = Invoice::getById($invoice['id']);
check($pending['status'] === 'New', 'unpaid quote without state field stays New');

// Pay at the mint, then poll again — this exercises amount_paid/amount_issued.
$pay = json_decode((string)file_get_contents("$mintUrl/__control/pay", false, stream_context_create([
    'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['quote' => $invoice['quote_id']])],
])), true);
check(($pay['ok'] ?? false) === true, 'mock quote marked paid');

Invoice::pollSingleQuote($invoice['id']);
$settled = Invoice::getById($invoice['id']);
check($settled['status'] === 'Settled', "invoice settled after payment (status: {$settled['status']})");
check(Invoice::getBalance($storeId) === 21, 'store balance equals invoice amount');

// --- Proof storage: v2 keyset + NUT-13 HMAC secrets ------------------------
echo "proof storage:\n";
$proofs = Invoice::getUnspentProofs($storeId);
check(count($proofs) > 0, 'proofs stored after mint');
foreach ($proofs as $proof) {
    check($proof->id === $keysetId, 'stored proof uses the full v2 keyset id');
}

// The secrets must be reproducible from the seed via the official HMAC KDF —
// this is exactly what makes seed-phrase restore work on other wallets.
$reference = new Wallet($mintUrl, 'sat');
$reference->initFromMnemonic($mnemonic);
$expectedSecrets = [];
foreach (range(0, count($proofs) + 2) as $counter) {
    $expectedSecrets[] = $reference->generateDeterministicSecret($keysetId, $counter)['secret'];
}
foreach ($proofs as $proof) {
    check(in_array($proof->secret, $expectedSecrets, true), 'proof secret matches official NUT-13 v2 derivation');
}

// --- Token export: V4 for v2 keysets ---------------------------------------
echo "token export:\n";
$token = $wallet->serializeToken($proofs);
check(str_starts_with($token, 'cashuB'), 'v2-keyset proofs serialize as V4 (cashuB)');
$parsed = TokenSerializer::deserialize($token);
check(count($parsed->proofs) === count($proofs), 'V4 token round-trips');

// --- Short keyset ID resolution on receive ----------------------------------
echo "short keyset id receive:\n";
// Build a fresh externally-minted token (own quote, mint, then shorten the id).
$external = new Wallet($mintUrl, 'sat', $dataDir . '/external-wallet.db', 'ext1');
$external->loadMint();
$external->initializeNewFromMnemonic(\Cashu\Mnemonic::generate());
$quote = $external->requestMintQuote(8);
check($quote->isPaid() === false, 'external quote starts unpaid (amount accounting)');
file_get_contents("$mintUrl/__control/pay", false, stream_context_create([
    'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['quote' => $quote->quote])],
]));
$externalProofs = $external->mint($quote->quote, 8);
check(count($externalProofs) > 0, 'external wallet minted (DLEQ verified on every signature)');

foreach ($externalProofs as $proof) {
    $proof->id = substr($proof->id, 0, 16); // NUT-00 short keyset ID
}
$shortToken = $external->serializeToken($externalProofs);
$received = $wallet->receive($shortToken);
check(count($received['keep'] ?? $received) > 0 || !empty($received), 'token with short keyset ids received via swap');
$balanceAfter = Invoice::getBalance($storeId);
// 8 in, minus ceil(1 * 100/1000) = 1 sat fee -> 7
check($balanceAfter === 21 + 7, "swap accounted for input_fee_ppk (balance $balanceAfter = 28)");

// --- NUT-20 quote locking ----------------------------------------------------
echo "nut-20 quote locking:\n";
$lockedInvoice = Invoice::create($storeId, ['amount' => 5, 'currency' => 'sat']);
$quoteKey = $wallet->getStorage()->getMintQuoteKey($lockedInvoice['quote_id']);
check($quoteKey !== null, 'new mint quote is locked with a stored deterministic key');
$mockQuote = json_decode((string)file_get_contents("$mintUrl/v1/mint/quote/bolt11/{$lockedInvoice['quote_id']}"), true);
check(($mockQuote['pubkey'] ?? null) === $quoteKey['pubkey'], 'quote locked to our pubkey at the mint');

$payQuote = function (string $quoteId) use ($mintUrl): void {
    file_get_contents("$mintUrl/__control/pay", false, stream_context_create([
        'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['quote' => $quoteId])],
    ]));
};
$payQuote($lockedInvoice['quote_id']);
Invoice::pollSingleQuote($lockedInvoice['id']);
$lockedSettled = Invoice::getById($lockedInvoice['id']);
// The mock REQUIRES a valid BIP340 signature for this quote, so settling
// proves the wallet signed the mint request correctly.
check($lockedSettled['status'] === 'Settled', "locked invoice settled with valid signature (status: {$lockedSettled['status']})");
check($wallet->getStorage()->getMintQuoteKey($lockedInvoice['quote_id']) === null, 'quote key record cleaned up after mint');
check(Invoice::getBalance($storeId) === 33, 'balance includes NUT-20 locked mint');

// Seed-restore path: the local key record is gone, but the deterministic key
// is recovered by scanning counters against the quote pubkey.
echo "nut-20 restore scan:\n";
$scanQuote = $external->requestMintQuote(4);
$payQuote($scanQuote->quote);
$external->getStorage()->deleteMintQuoteKey($scanQuote->quote);
$scanProofs = $external->mint($scanQuote->quote, 4);
check(count($scanProofs) > 0, 'locked quote minted after deterministic key recovery scan');

// Older mints (nutshell <= 0.20.x) verify the pre-hardening legacy signature
// message; the wallet must fall back to it after a 20008 rejection.
echo "nut-20 legacy mint fallback:\n";
$setLegacy = function (bool $enabled) use ($mintUrl): void {
    file_get_contents("$mintUrl/__control/nut20_legacy", false, stream_context_create([
        'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['enabled' => $enabled])],
    ]));
};
$setLegacy(true);
$legacyInvoice = Invoice::create($storeId, ['amount' => 3, 'currency' => 'sat']);
check($wallet->getStorage()->getMintQuoteKey($legacyInvoice['quote_id']) !== null, 'legacy-mint quote is still NUT-20 locked');
$payQuote($legacyInvoice['quote_id']);
Invoice::pollSingleQuote($legacyInvoice['id']);
$legacySettled = Invoice::getById($legacyInvoice['id']);
check($legacySettled['status'] === 'Settled', "invoice settled via legacy signature fallback (status: {$legacySettled['status']})");
check(Invoice::getBalance($storeId) === 36, 'balance includes legacy-fallback mint');
$setLegacy(false);

// --- Ambiguous melt reconciliation -------------------------------------------
echo "ambiguous melt reconciliation:\n";
$balanceBeforeMelt = Invoice::getBalance($storeId);
file_get_contents("$mintUrl/__control/next_melt_ambiguous", false, stream_context_create([
    'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => '{}'],
]));
$meltQuote = $wallet->requestMeltQuote('lnbcmockmelt5');
$meltProofs = Wallet::selectProofs(Invoice::getUnspentProofs($storeId), $meltQuote->amount + $meltQuote->feeReserve);
$meltResult = $wallet->melt($meltQuote->quote, $meltProofs);
check($meltResult['paid'] === true, 'melt reports paid after POST failed but quote reconciled as PAID');
check($meltResult['preimage'] !== null, 'reconciled melt returns payment preimage');
check($wallet->getStorage()->getPendingOperationById('melt:' . $meltQuote->quote) === null, 'reconciled melt clears pending journal');
$balanceAfterMelt = Invoice::getBalance($storeId);
check($balanceAfterMelt === $balanceBeforeMelt - 6, "reconciled melt preserves change (balance $balanceAfterMelt = $balanceBeforeMelt - 6)");

// --- Keyset rotation ---------------------------------------------------------
echo "keyset rotation:\n";
file_get_contents("$mintUrl/__control/rotate", false, stream_context_create([
    'http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => '{}'],
]));
$storeRow = Database::fetchOne('SELECT wallet_account_id FROM stores WHERE id = ?', [$storeId]);
$freshWallet = new Wallet($mintUrl, 'sat', Database::getDbPath(), $storeRow['wallet_account_id']);
$freshWallet->loadMint();
$freshWallet->initFromMnemonic($mnemonic);
$newKeysetId = $freshWallet->getActiveKeysetId();
check($newKeysetId !== $keysetId && str_starts_with($newKeysetId, '01'), 'mint rotated to a new v2 keyset');

$balanceBeforeRotation = Invoice::getBalance($storeId);
$rotation = $freshWallet->rotateProofs();
check($rotation['rotated'] > 0 && empty($rotation['errors']), "proofs rotated off the old keyset ({$rotation['rotated']} proofs)");
foreach (Invoice::getUnspentProofs($storeId) as $proof) {
    check($proof->id === $newKeysetId, 'unspent proof now lives on the new keyset');
}
$balanceAfterRotation = Invoice::getBalance($storeId);
$rotationFee = (int)ceil($rotation['rotated'] * 100 / 1000);
check(
    $balanceAfterRotation === $balanceBeforeRotation - $rotationFee,
    "balance preserved minus swap fee ($balanceAfterRotation = $balanceBeforeRotation - $rotationFee)"
);
// A second run must be a no-op (nothing left on old keysets).
$rotationAgain = $freshWallet->rotateProofs();
check($rotationAgain['rotated'] === 0 && $rotationAgain['checked'] === 0, 'second rotation run is a no-op');

// --- Stranded-fund recovery after a mint change ------------------------------
// Changing a store's mint_url points it at a fresh wallet namespace, stranding
// the balance held at the old mint. Recovery must find and export it.
echo "stranded-fund recovery:\n";
$storeAccount = Database::fetchOne('SELECT wallet_account_id FROM stores WHERE id = ?', [$storeId])['wallet_account_id'];
$oldMint = rtrim($mintUrl, '/');
$balBefore = Invoice::getBalance($storeId);
check($balBefore > 0, "store has a balance before the mint change ($balBefore sat)");

// Feature 1: a mint change on an initialized store is blocked without consent.
$blocked = false;
try {
    Config::updateStore($storeId, ['mint_url' => 'https://some-other-mint.example']);
} catch (Throwable $e) {
    $blocked = true;
}
check($blocked, 'mint change is refused without explicit consent (immutable guard)');

// With explicit consent it is allowed (this is what strands the funds).
Config::updateStore($storeId, ['mint_url' => 'https://some-other-mint.example'], true);
check(Invoice::getBalance($storeId) === 0, 'balance reads 0 after the confirmed mint change (funds stranded)');

$stranded = Invoice::scanStrandedNamespaces();
$match = null;
foreach ($stranded as $s) {
    if ($s['mint_url'] === $oldMint && $s['account'] === $storeAccount) { $match = $s; }
}
check($match !== null, 'scan identifies the stranded namespace at the old mint');
check($match && $match['amount'] === $balBefore, "stranded amount matches the pre-change balance ($balBefore)");

$exp = Invoice::exportNamespaceAsToken($oldMint, 'sat', $storeAccount);
check($exp['token'] !== null && $exp['amount'] === $balBefore, 'stranded funds export as a token');
check($exp['token'] !== null && str_starts_with($exp['token'], 'cashu'), 'exported stranded token is a cashu token');

$resolved = Invoice::resolveNamespaceForMint($oldMint, 'sat');
check($resolved['found'] && $resolved['amount'] === $balBefore && $resolved['account'] === $storeAccount,
    'manual mint resolution finds the stranded namespace and its account');

// Export is read-only: the funds are still there until explicitly marked recovered.
$exp2 = Invoice::exportNamespaceAsToken($oldMint, 'sat', $storeAccount);
check($exp2['amount'] === $balBefore, 're-export before marking recovered still yields the funds (read-only)');

$marked = Invoice::markNamespaceRecovered($oldMint, 'sat', $storeAccount);
check($marked === $exp['count'], "mark recovered marks all {$exp['count']} exported proofs");
$strandedAfter = Invoice::scanStrandedNamespaces();
$stillThere = false;
foreach ($strandedAfter as $s) {
    if ($s['mint_url'] === $oldMint && $s['account'] === $storeAccount) { $stillThere = true; }
}
check(!$stillThere, 'namespace is no longer stranded after recovery');

// Restore the store's real mint so nothing downstream is surprised.
Config::updateStore($storeId, ['mint_url' => $oldMint]);

echo "integration_keyset_v2: OK\n";
