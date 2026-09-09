<?php
/**
 * Mock Cashu mint for integration tests. Run with:
 *   MOCK_MINT_STATE=/tmp/state.json php -S 127.0.0.1:8787 tests/mock_mint_router.php
 *
 * Behaviour:
 * - Serves V2 ("01"-prefix) keysets it can actually sign for (fee 100 ppk)
 * - Returns NUT-12 DLEQ proofs with every signature
 * - Post-deprecation NUT-04/NUT-23 mint quotes (amount_paid/amount_issued, no `state`)
 * - Enforces NUT-20: quotes created with a pubkey require a valid BIP340
 *   signature on the mint request (error 20008 otherwise)
 * - NUT-19-style response cache for mint/swap (byte-identical replay returns
 *   the original response)
 * - /v1/keys lists only ACTIVE keysets (rotated keysets must be fetched via
 *   /v1/keys/{id}, like real mints)
 *
 * Control endpoints (not part of the Cashu API):
 *   POST /__control/pay    {"quote": "..."}  — mark a mint quote as paid
 *   POST /__control/rotate {}                — deactivate current keyset
 *                                              (final_expiry = now + 1 day)
 *                                              and activate a fresh one
 */

declare(strict_types=1);

// This is a disposable mock mint served by PHP's built-in server during tests. It must
// never run under a real web server, where it would be an unauthenticated endpoint that
// mints tokens on request.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    http_response_code(403);
    exit('This script runs under the test server only.');
}

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';

use Cashu\BigInt;
use Cashu\Crypto;
use Cashu\Keyset;
use Cashu\Secp256k1;
use Cashu\Wallet;

const MOCK_UNIT = 'sat';
const MOCK_FEE_PPK = 100;
const MOCK_AMOUNTS = [1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048];

function state_path(): string
{
    $path = getenv('MOCK_MINT_STATE');
    if (!$path) {
        http_response_code(500);
        exit(json_encode(['detail' => 'MOCK_MINT_STATE not set']));
    }
    return $path;
}

function make_keyset(int $generation): array
{
    $privkeys = [];
    $pubkeys = [];
    $G = Secp256k1::getGenerator();
    $n = Secp256k1::getOrder();
    foreach (MOCK_AMOUNTS as $amount) {
        $k = BigInt::fromHex(hash('sha256', "mock-mint-key-gen$generation-$amount"))->mod($n);
        $privkeys[(string)$amount] = str_pad($k->toHex(), 64, '0', STR_PAD_LEFT);
        $pubkeys[(string)$amount] = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $G)));
    }
    $finalExpiry = 2059210353;
    $id = Keyset::deriveKeysetIdV2(
        array_combine(array_map('intval', array_keys($pubkeys)), array_values($pubkeys)),
        MOCK_UNIT,
        MOCK_FEE_PPK,
        $finalExpiry
    );
    return [
        'id' => $id,
        'generation' => $generation,
        'active' => true,
        'final_expiry' => $finalExpiry,
        'privkeys' => $privkeys,
        'pubkeys' => $pubkeys,
    ];
}

function load_state(): array
{
    $path = state_path();
    $state = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (!is_array($state)) {
        $state = ['quotes' => [], 'spent' => [], 'keysets' => [], 'cache' => []];
    }
    if (empty($state['keysets'])) {
        $keyset = make_keyset(1);
        $state['keysets'][$keyset['id']] = $keyset;

        // Like many production mints: an old V1 keyset whose announced ID was
        // created under a historical derivation rule and does NOT re-derive
        // from its keys. Wallets must treat such IDs as opaque identifiers.
        $legacy = make_keyset(99);
        $legacy['id'] = '00deadbeefcafe12';
        $legacy['final_expiry'] = null;
        $state['keysets'][$legacy['id']] = $legacy;

        save_state($state);
    }
    return $state;
}

function save_state(array $state): void
{
    file_put_contents(state_path(), json_encode($state), LOCK_EX);
}

function active_keyset(array $state): array
{
    foreach ($state['keysets'] as $keyset) {
        if ($keyset['active']) {
            return $keyset;
        }
    }
    http_response_code(500);
    exit(json_encode(['detail' => 'no active keyset']));
}

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    exit(json_encode($data));
}

/** Sign a blinded message with the keyset the output names, with NUT-12 DLEQ. */
function sign_output(array $output, array $state): array
{
    $keyset = $state['keysets'][$output['id']] ?? null;
    if ($keyset === null) {
        respond(['detail' => 'Keyset is not known', 'code' => 12001], 400);
    }
    $amount = (string)$output['amount'];
    if (!isset($keyset['privkeys'][$amount])) {
        respond(['detail' => "no key for amount $amount", 'code' => 10000], 400);
    }
    $k = BigInt::fromHex($keyset['privkeys'][$amount]);
    $n = Secp256k1::getOrder();
    $G = Secp256k1::getGenerator();

    $Bpoint = Secp256k1::decompressPoint(hex2bin($output['B_']));
    $Cpoint = Secp256k1::scalarMult($k, $Bpoint);
    $C_ = bin2hex(Secp256k1::compressPoint($Cpoint));
    $Apoint = Secp256k1::scalarMult($k, $G);

    // DLEQ: R1 = r*G, R2 = r*B', e = hash_e(R1,R2,A,C'), s = r + e*k mod n
    $r = Secp256k1::randomScalar();
    $R1 = Secp256k1::scalarMult($r, $G);
    $R2 = Secp256k1::scalarMult($r, $Bpoint);
    $e = Crypto::hashE($R1, $R2, $Apoint, $Cpoint);
    $s = $r->add(BigInt::fromHex($e)->mul($k))->mod($n);

    return [
        'id' => $output['id'],
        'amount' => (int)$output['amount'],
        'C_' => $C_,
        'dleq' => ['e' => $e, 's' => str_pad($s->toHex(), 64, '0', STR_PAD_LEFT)],
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$rawBody = (string)file_get_contents('php://input');
$body = json_decode($rawBody, true) ?: [];
$state = load_state();

// --- control -----------------------------------------------------------
if ($uri === '/__control/pay' && $method === 'POST') {
    $quoteId = $body['quote'] ?? '';
    if (!isset($state['quotes'][$quoteId])) {
        respond(['detail' => 'unknown quote'], 404);
    }
    $state['quotes'][$quoteId]['amount_paid'] = $state['quotes'][$quoteId]['amount'];
    save_state($state);
    respond(['ok' => true]);
}

if ($uri === '/__control/nut20_legacy' && $method === 'POST') {
    // Emulate mints released before the NUT-20 message hardening
    // (nutshell <= 0.20.x): verify ONLY the legacy signature message.
    $state['nut20_legacy'] = (bool)($body['enabled'] ?? false);
    save_state($state);
    respond(['ok' => true, 'nut20_legacy' => $state['nut20_legacy']]);
}

if ($uri === '/__control/next_melt_ambiguous' && $method === 'POST') {
    // The next melt will be fully processed and persisted as PAID, but the
    // endpoint returns a SQL-style 500 instead of its success response. This
    // reproduces the real-world "payment succeeded, response lost/broken"
    // failure that must be reconciled via GET /melt/quote.
    $state['next_melt_ambiguous'] = true;
    save_state($state);
    respond(['ok' => true]);
}

if ($uri === '/__control/rotate' && $method === 'POST') {
    $maxGen = 0;
    foreach ($state['keysets'] as $id => $keyset) {
        $state['keysets'][$id]['active'] = false;
        $state['keysets'][$id]['final_expiry'] = time() + 86400; // expiring soon
        $maxGen = max($maxGen, $keyset['generation']);
    }
    $fresh = make_keyset($maxGen + 1);
    $state['keysets'][$fresh['id']] = $fresh;
    save_state($state);
    respond(['ok' => true, 'active_keyset' => $fresh['id']]);
}

// --- Cashu API ----------------------------------------------------------
if ($uri === '/v1/info') {
    respond([
        'name' => 'Mock Mint (keyset v2)',
        'version' => 'mock/1.1',
        'nuts' => [
            '4' => ['methods' => [['method' => 'bolt11', 'unit' => MOCK_UNIT]], 'disabled' => false],
            '5' => ['methods' => [['method' => 'bolt11', 'unit' => MOCK_UNIT]], 'disabled' => false],
            '7' => ['supported' => true],
            '12' => ['supported' => true],
            '19' => ['ttl' => 300, 'cached_endpoints' => [
                ['method' => 'POST', 'path' => '/v1/mint/bolt11'],
                ['method' => 'POST', 'path' => '/v1/swap'],
            ]],
            '20' => ['supported' => true],
        ],
    ]);
}

if ($uri === '/v1/keysets') {
    respond(['keysets' => array_values(array_map(fn($ks) => [
        'id' => $ks['id'],
        'unit' => MOCK_UNIT,
        'active' => $ks['active'],
        'input_fee_ppk' => MOCK_FEE_PPK,
        'final_expiry' => $ks['final_expiry'],
    ], $state['keysets']))]);
}

if ($uri === '/v1/keys') {
    // Like real mints: only ACTIVE keysets are listed here.
    respond(['keysets' => array_values(array_map(fn($ks) => [
        'id' => $ks['id'],
        'unit' => MOCK_UNIT,
        'keys' => $ks['pubkeys'],
    ], array_filter($state['keysets'], fn($ks) => $ks['active'])))]);
}

if (preg_match('#^/v1/keys/(.+)$#', $uri, $m)) {
    $keyset = $state['keysets'][urldecode($m[1])] ?? null;
    if ($keyset === null) {
        respond(['detail' => 'Keyset is not known', 'code' => 12001], 404);
    }
    respond(['keysets' => [[
        'id' => $keyset['id'],
        'unit' => MOCK_UNIT,
        'keys' => $keyset['pubkeys'],
    ]]]);
}

if ($uri === '/v1/mint/quote/bolt11' && $method === 'POST') {
    $quoteId = bin2hex(random_bytes(16));
    $quote = [
        'quote' => $quoteId,
        'request' => 'lnbcmock' . $quoteId,
        'amount' => (int)($body['amount'] ?? 0),
        'unit' => MOCK_UNIT,
        'expiry' => time() + 900,
        // Post-deprecation shape: no `state` field at all.
        'amount_paid' => 0,
        'amount_issued' => 0,
        'pubkey' => $body['pubkey'] ?? null, // NUT-20
    ];
    $state['quotes'][$quoteId] = $quote;
    save_state($state);
    respond($quote);
}

if (preg_match('#^/v1/mint/quote/bolt11/([0-9a-f]+)$#', $uri, $m)) {
    $quote = $state['quotes'][$m[1]] ?? null;
    $quote ? respond($quote) : respond(['detail' => 'unknown quote', 'code' => 20005], 404);
}

if ($uri === '/v1/mint/bolt11' && $method === 'POST') {
    // NUT-19: byte-identical replay returns the cached response.
    $cacheKey = 'mint:' . hash('sha256', $rawBody);
    if (isset($state['cache'][$cacheKey])) {
        respond($state['cache'][$cacheKey]);
    }

    $quoteId = $body['quote'] ?? '';
    $quote = $state['quotes'][$quoteId] ?? null;
    if (!$quote) {
        respond(['detail' => 'unknown quote', 'code' => 20005], 404);
    }
    if ($quote['amount_paid'] <= $quote['amount_issued']) {
        respond(['detail' => 'Quote request is not paid', 'code' => 20001], 400);
    }

    // NUT-20: a locked quote requires a valid BIP340 signature. In legacy
    // mode the mint verifies ONLY the pre-hardening message, exactly like
    // nutshell <= 0.20.x (sha256 over quote_id || B_ hex strings).
    if (!empty($quote['pubkey'])) {
        $signature = $body['signature'] ?? '';
        $msg = !empty($state['nut20_legacy'])
            ? Wallet::buildMintQuoteSignatureMessageLegacy($quoteId, $body['outputs'] ?? [])
            : Wallet::buildMintQuoteSignatureMessage($quoteId, $body['outputs'] ?? []);
        if ($signature === '' || !Secp256k1::schnorrVerify($quote['pubkey'], hash('sha256', $msg, true), $signature)) {
            respond(['detail' => 'Signature for mint request invalid', 'code' => 20008], 400);
        }
    }

    $signatures = array_map(fn($o) => sign_output($o, $state), $body['outputs'] ?? []);
    $state['quotes'][$quoteId]['amount_issued'] = $quote['amount_paid'];
    $response = ['signatures' => $signatures];
    $state['cache'][$cacheKey] = $response;
    save_state($state);
    respond($response);
}

if ($uri === '/v1/melt/quote/bolt11' && $method === 'POST') {
    $quoteId = bin2hex(random_bytes(16));
    // Test invoices use lnbcmockmelt<N>; default to 5 sats.
    $amount = preg_match('/lnbcmockmelt(\d+)/', (string)($body['request'] ?? ''), $matches)
        ? (int)$matches[1]
        : 5;
    $quote = [
        'quote' => $quoteId,
        'request' => (string)($body['request'] ?? ''),
        'unit' => MOCK_UNIT,
        'amount' => $amount,
        'fee_reserve' => 1,
        'state' => 'UNPAID',
        'expiry' => time() + 900,
        'payment_preimage' => null,
        'change' => null,
    ];
    $state['melt_quotes'][$quoteId] = $quote;
    save_state($state);
    respond($quote);
}

if (preg_match('#^/v1/melt/quote/bolt11/([0-9a-f]+)$#', $uri, $m)) {
    $quote = $state['melt_quotes'][$m[1]] ?? null;
    $quote ? respond($quote) : respond(['detail' => 'unknown melt quote', 'code' => 20005], 404);
}

/** Routing fee the mock mint actually pays, always below the quoted fee_reserve. */
const MOCK_MELT_FEE_PAID = 0;

if ($uri === '/v1/melt/bolt11' && $method === 'POST') {
    $quoteId = (string)($body['quote'] ?? '');
    $quote = $state['melt_quotes'][$quoteId] ?? null;
    if ($quote === null) {
        respond(['detail' => 'unknown melt quote', 'code' => 20005], 404);
    }

    $Ys = [];
    $inputSum = 0;
    foreach ($body['inputs'] ?? [] as $input) {
        $Y = Crypto::computeY($input['secret']);
        if (isset($state['spent'][$Y])) {
            // Deliberately SQL-flavoured, matching the production failure.
            respond(['detail' => 'IntegrityError: duplicate key value violates unique constraint "proofs_used_new_y_key"'], 500);
        }
        $Ys[] = $Y;
        $inputSum += (int)$input['amount'];
    }

    // NUT-08: blank outputs arrive with amount 0; the mint decomposes the overpayment
    // into powers of two, assigns them to as many supplied outputs as it can, and drops
    // the rest. Mirrors nutshell's _generate_change_promises.
    $feePaid = MOCK_MELT_FEE_PAID;
    $overpaid = $inputSum - (int)$quote['amount'] - $feePaid;
    $returnAmounts = [];
    for ($bit = 1; $bit <= $overpaid; $bit <<= 1) {
        if ($overpaid & $bit) {
            $returnAmounts[] = $bit;
        }
    }
    rsort($returnAmounts);
    $blankOutputs = $body['outputs'] ?? [];
    $change = [];
    foreach (array_slice($returnAmounts, 0, count($blankOutputs)) as $i => $amount) {
        $blankOutputs[$i]['amount'] = $amount;
        $change[] = sign_output($blankOutputs[$i], $state);
    }
    foreach ($Ys as $Y) {
        $state['spent'][$Y] = true;
    }
    $state['melt_quotes'][$quoteId]['state'] = 'PAID';
    $state['melt_quotes'][$quoteId]['payment_preimage'] = hash('sha256', $quoteId);
    $state['melt_quotes'][$quoteId]['change'] = $change;
    save_state($state);

    if (!empty($state['next_melt_ambiguous'])) {
        unset($state['next_melt_ambiguous']);
        save_state($state);
        respond(['detail' => 'IntegrityError: simulated response failure after payment commit'], 500);
    }
    respond($state['melt_quotes'][$quoteId]);
}

if ($uri === '/v1/swap' && $method === 'POST') {
    $cacheKey = 'swap:' . hash('sha256', $rawBody);
    if (isset($state['cache'][$cacheKey])) {
        respond($state['cache'][$cacheKey]);
    }

    $inputSum = 0;
    $feePpkSum = 0;
    $Ys = [];
    foreach ($body['inputs'] ?? [] as $input) {
        $Y = Crypto::computeY($input['secret']);
        if (isset($state['spent'][$Y])) {
            respond(['detail' => 'Token already spent', 'code' => 11001], 400);
        }
        $Ys[] = $Y;
        $inputSum += (int)$input['amount'];
        $feePpkSum += MOCK_FEE_PPK;
    }
    $outputSum = array_sum(array_map(fn($o) => (int)$o['amount'], $body['outputs'] ?? []));
    $fee = (int)ceil($feePpkSum / 1000);
    if ($inputSum - $fee !== $outputSum) {
        respond(['detail' => "Transaction is not balanced (inputs $inputSum - fee $fee != outputs $outputSum)", 'code' => 11005], 400);
    }
    foreach ($Ys as $Y) {
        $state['spent'][$Y] = true;
    }
    $signatures = array_map(fn($o) => sign_output($o, $state), $body['outputs'] ?? []);
    $response = ['signatures' => $signatures];
    $state['cache'][$cacheKey] = $response;
    save_state($state);
    respond($response);
}

if ($uri === '/v1/checkstate' && $method === 'POST') {
    $states = [];
    foreach ($body['Ys'] ?? [] as $Y) {
        $states[] = ['Y' => $Y, 'state' => isset($state['spent'][$Y]) ? 'SPENT' : 'UNSPENT'];
    }
    respond(['states' => $states]);
}

respond(['detail' => "mock mint: no route for $method $uri"], 404);
