<?php
/**
 * Mock Cashu mint for integration tests. Run with:
 *   MOCK_MINT_STATE=/tmp/state.json php -S 127.0.0.1:8787 tests/mock_mint_router.php
 *
 * Serves a single V2 ("01"-prefix) keyset it can actually sign for, returns
 * NUT-12 DLEQ proofs with every signature, and uses the post-deprecation
 * NUT-04/NUT-23 mint quote shape (amount_paid/amount_issued, no `state`).
 *
 * Control endpoint (not part of the Cashu API):
 *   POST /__control/pay {"quote": "..."} — mark a mint quote as paid.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';

use Cashu\BigInt;
use Cashu\Crypto;
use Cashu\Keyset;
use Cashu\Secp256k1;

const MOCK_UNIT = 'sat';
const MOCK_FEE_PPK = 100;
const MOCK_FINAL_EXPIRY = 2059210353;
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

function load_state(): array
{
    $path = state_path();
    $state = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (!is_array($state)) {
        $state = ['quotes' => [], 'spent' => [], 'keyset' => null];
    }
    if ($state['keyset'] === null) {
        $privkeys = [];
        $pubkeys = [];
        $G = Secp256k1::getGenerator();
        $n = Secp256k1::getOrder();
        foreach (MOCK_AMOUNTS as $amount) {
            $k = BigInt::fromHex(hash('sha256', "mock-mint-key-$amount"))->mod($n);
            $privkeys[(string)$amount] = str_pad($k->toHex(), 64, '0', STR_PAD_LEFT);
            $pubkeys[(string)$amount] = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $G)));
        }
        $state['keyset'] = [
            'id' => Keyset::deriveKeysetIdV2(
                array_combine(array_map('intval', array_keys($pubkeys)), array_values($pubkeys)),
                MOCK_UNIT,
                MOCK_FEE_PPK,
                MOCK_FINAL_EXPIRY
            ),
            'privkeys' => $privkeys,
            'pubkeys' => $pubkeys,
        ];
        save_state($state);
    }
    return $state;
}

function save_state(array $state): void
{
    file_put_contents(state_path(), json_encode($state), LOCK_EX);
}

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    exit(json_encode($data));
}

/** Sign a blinded message and attach a NUT-12 DLEQ proof. */
function sign_output(array $output, array $keyset): array
{
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
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$state = load_state();
$keyset = $state['keyset'];

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

// --- Cashu API ----------------------------------------------------------
if ($uri === '/v1/info') {
    respond([
        'name' => 'Mock Mint (keyset v2)',
        'version' => 'mock/1.0',
        'nuts' => [
            '4' => ['methods' => [['method' => 'bolt11', 'unit' => MOCK_UNIT]], 'disabled' => false],
            '5' => ['methods' => [['method' => 'bolt11', 'unit' => MOCK_UNIT]], 'disabled' => false],
            '7' => ['supported' => true],
            '12' => ['supported' => true],
        ],
    ]);
}

if ($uri === '/v1/keysets') {
    respond(['keysets' => [[
        'id' => $keyset['id'],
        'unit' => MOCK_UNIT,
        'active' => true,
        'input_fee_ppk' => MOCK_FEE_PPK,
        'final_expiry' => MOCK_FINAL_EXPIRY,
    ]]]);
}

if ($uri === '/v1/keys' || $uri === '/v1/keys/' . $keyset['id']) {
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
    $quoteId = $body['quote'] ?? '';
    $quote = $state['quotes'][$quoteId] ?? null;
    if (!$quote) {
        respond(['detail' => 'unknown quote', 'code' => 20005], 404);
    }
    if ($quote['amount_paid'] <= $quote['amount_issued']) {
        respond(['detail' => 'quote not paid', 'code' => 20001], 400);
    }
    $signatures = array_map(fn($o) => sign_output($o, $keyset), $body['outputs'] ?? []);
    $state['quotes'][$quoteId]['amount_issued'] = $quote['amount_paid'];
    save_state($state);
    respond(['signatures' => $signatures]);
}

if ($uri === '/v1/swap' && $method === 'POST') {
    $inputSum = 0;
    $Ys = [];
    foreach ($body['inputs'] ?? [] as $input) {
        $Y = Crypto::computeY($input['secret']);
        if (isset($state['spent'][$Y])) {
            respond(['detail' => 'Token already spent', 'code' => 11001], 400);
        }
        $Ys[] = $Y;
        $inputSum += (int)$input['amount'];
    }
    $outputSum = array_sum(array_map(fn($o) => (int)$o['amount'], $body['outputs'] ?? []));
    $fee = (int)ceil(count($body['inputs'] ?? []) * MOCK_FEE_PPK / 1000);
    if ($inputSum - $fee !== $outputSum) {
        respond(['detail' => "inputs $inputSum - fee $fee != outputs $outputSum", 'code' => 11002], 400);
    }
    foreach ($Ys as $Y) {
        $state['spent'][$Y] = true;
    }
    $signatures = array_map(fn($o) => sign_output($o, $keyset), $body['outputs'] ?? []);
    save_state($state);
    respond(['signatures' => $signatures]);
}

if ($uri === '/v1/checkstate' && $method === 'POST') {
    $states = [];
    foreach ($body['Ys'] ?? [] as $Y) {
        $states[] = ['Y' => $Y, 'state' => isset($state['spent'][$Y]) ? 'SPENT' : 'UNSPENT'];
    }
    respond(['states' => $states]);
}

respond(['detail' => "mock mint: no route for $method $uri"], 404);
