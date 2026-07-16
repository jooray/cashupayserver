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

$wallet = Invoice::initializeWalletForStore($storeId, false);
$keysetId = $wallet->getActiveKeysetId();
check(strlen($keysetId) === 66 && str_starts_with($keysetId, '01'), "mint presents a v2 keyset id ($keysetId)");
check($wallet->getInputFeePpk($keysetId) === 100, 'input_fee_ppk read from v2 keyset metadata');

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

echo "integration_keyset_v2: OK\n";
