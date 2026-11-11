<?php
/**
 * Scriptable, signing stub mint for the test_lib_*.php library tests.
 *
 * A php -S server on 127.0.0.1 that implements enough of NUT-01..NUT-09 to drive
 * cashu-wallet-php end to end with REAL blind signatures (C_ = k * B_ with a
 * deterministic per-amount key k), so mint()/swap()/melt()/restore unblind and
 * verify exactly as against a real mint. Behaviour is steered per test through a
 * script.json file in the stub directory (read on every request):
 *
 *   overrides   "METHOD /v1/path" => response | [response, response, ...]
 *               response = ['status' => int, 'body' => mixed|null, 'raw' => string|null,
 *                           'side_effect' => 'pay'|'pending'|'issue'|null]
 *               A list is consumed in order (last one repeats).
 *   melt_quotes quote id => ['amount' => int, 'fee_reserve' => int, 'expiry' => ?int,
 *                            'state' => 'UNPAID'|..., 'change_on_get' => bool]
 *   mint_quotes quote id => ['amount' => int, 'state' => ..., 'expiry' => ?int]
 *   checkstate  'normal' | 'short' | 'empty' | 'reordered' | 'wrongY' | 'nostate' | 'error'
 *   restore     'normal' | 'none' | 'error'
 *   melt_fee    int  Fee the "Lightning route" actually used (default 1)
 *   zero_blanks bool Sign unused blank outputs with amount 0 (default false)
 *   keysets     explicit keyset list for GET /v1/keysets (optional)
 *
 * State (signed outputs, spent Ys, quote states, consumed overrides) lives in
 * state.json; the request log in requests.jsonl (one JSON object per line).
 *
 * Not a test itself (the name does not match test_*.php).
 */
declare(strict_types=1);

const LIB_STUB_KEYSET = '00aabbccddeeff01';

function lib_mint_stub_router_source(): string
{
    $lib = dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
    return <<<'PHP'
<?php
declare(strict_types=1);
require __LIB__;
use Cashu\Secp256k1;
use Cashu\BigInt;

const KEYSET = '00aabbccddeeff01';
$dir = __DIR__;
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);

if ($path === '/__stub') { header('Content-Type: application/json'); echo json_encode(['dir' => $dir]); exit; }

$script = json_decode((string)@file_get_contents("$dir/script.json"), true) ?: [];
$state = json_decode((string)@file_get_contents("$dir/state.json"), true) ?: [];
$state += ['signed' => [], 'spent' => [], 'melts' => [], 'mints' => [], 'consumed' => []];

file_put_contents("$dir/requests.jsonl", json_encode(['method' => $method, 'path' => $path, 'body' => $body]) . "\n", FILE_APPEND);

function save(array $state, string $dir): void { file_put_contents("$dir/state.json", json_encode($state)); }
function reply(int $status, $body, ?string $rawBody = null): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo $rawBody ?? json_encode($body);
    exit;
}
function privkey(int $amount): BigInt {
    $k = BigInt::fromHex(hash('sha256', 'stubmint:' . $amount));
    return $k->mod(Secp256k1::getOrder());
}
function pubkey(int $amount): string {
    return bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult(privkey($amount), Secp256k1::getGenerator())));
}
function keymap(): array { $m = []; for ($a = 1; $a <= 65536; $a *= 2) { $m[(string)$a] = pubkey($a); } return $m; }
function sign(string $B_, int $amount): string {
    $pt = Secp256k1::decompressPoint(hex2bin($B_));
    return bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult(privkey($amount), $pt)));
}
function yOf(array $proof): string { return \Cashu\Crypto::computeY($proof['secret']); }
function decompose(int $amount): array { $out = []; $p = 1; while ($amount > 0) { if ($amount & 1) { $out[] = $p; } $amount >>= 1; $p <<= 1; } rsort($out); return $out; }

// ---- scripted overrides -------------------------------------------------------
$key = "$method $path";
$override = $script['overrides'][$key] ?? null;
if ($override !== null) {
    if (array_is_list($override)) {
        $idx = $state['consumed'][$key] ?? 0;
        $resp = $override[min($idx, count($override) - 1)];
        $state['consumed'][$key] = $idx + 1;
    } else {
        $resp = $override;
    }
    $effect = $resp['side_effect'] ?? null;
    if ($effect === 'pay' || $effect === 'pending') {
        // The mint executes the melt, but the response is scripted (lost/500).
        $quote = $body['quote'] ?? '';
        $q = $script['melt_quotes'][$quote] ?? ['amount' => 0, 'fee_reserve' => 0];
        $inputs = $body['inputs'] ?? [];
        $sum = array_sum(array_column($inputs, 'amount'));
        if ($effect === 'pay') {
            foreach ($inputs as $p) { $state['spent'][yOf($p)] = true; }
            $fee = (int)($script['melt_fee'] ?? 1);
            $change = max(0, $sum - (int)$q['amount'] - $fee);
            $sigs = [];
            $outputs = $body['outputs'] ?? [];
            $amounts = decompose($change);
            foreach ($outputs as $i => $o) {
                if (isset($amounts[$i])) {
                    $sigs[] = ['id' => $o['id'], 'amount' => $amounts[$i], 'C_' => sign($o['B_'], $amounts[$i])];
                    $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => $amounts[$i], 'C_' => end($sigs)['C_']];
                } elseif (!empty($script['zero_blanks'])) {
                    $sigs[] = ['id' => $o['id'], 'amount' => 0, 'C_' => sign($o['B_'], 1)];
                    $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => 0, 'C_' => end($sigs)['C_']];
                }
            }
            $state['melts'][$quote] = ['state' => 'PAID', 'payment_preimage' => str_repeat('ab', 32), 'change' => $sigs];
        } else {
            foreach ($inputs as $p) { $state['pending'][yOf($p)] = true; }
            $state['melts'][$quote] = ['state' => 'PENDING'];
        }
    } elseif ($effect === 'issue') {
        // The mint signs the mint outputs, but the response is scripted (lost/500).
        $quote = $body['quote'] ?? '';
        foreach ($body['outputs'] ?? [] as $o) {
            $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => $o['amount'], 'C_' => sign($o['B_'], (int)$o['amount'])];
        }
        $state['mints'][$quote] = ['state' => 'ISSUED'];
    }
    save($state, $dir);
    reply((int)($resp['status'] ?? 200), $resp['body'] ?? null, $resp['raw'] ?? null);
}

// ---- default behaviour --------------------------------------------------------
if ($method === 'GET' && $path === '/v1/info') {
    reply(200, ['name' => 'lib stub mint', 'version' => 'stub/1', 'nuts' => ['7' => ['supported' => true], '9' => ['supported' => true]]]);
}
if ($method === 'GET' && $path === '/v1/keysets') {
    $keysets = $script['keysets'] ?? [['id' => KEYSET, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => (int)($script['input_fee_ppk'] ?? 0)]];
    reply(200, ['keysets' => $keysets]);
}
if ($method === 'GET' && ($path === '/v1/keys' || str_starts_with($path, '/v1/keys/'))) {
    $id = $path === '/v1/keys' ? KEYSET : urldecode(substr($path, 9));
    if ($id !== KEYSET && !in_array($id, $script['extra_keysets'] ?? [], true)) {
        reply(404, ['detail' => 'keyset not found', 'code' => 12001]);
    }
    reply(200, ['keysets' => [['id' => $id, 'unit' => 'sat', 'keys' => keymap()]]]);
}
if ($method === 'POST' && $path === '/v1/mint/quote/bolt11') {
    $id = 'mq_' . bin2hex(random_bytes(4));
    $state['mints'][$id] = ['state' => 'UNPAID', 'amount' => (int)$body['amount']];
    save($state, $dir);
    reply(200, ['quote' => $id, 'request' => 'lnbcrt' . $body['amount'] . 'n1stub', 'amount' => (int)$body['amount'], 'unit' => 'sat', 'state' => 'UNPAID', 'expiry' => time() + 600]);
}
if ($method === 'GET' && str_starts_with($path, '/v1/mint/quote/bolt11/')) {
    $id = urldecode(substr($path, 22));
    $q = ($script['mint_quotes'][$id] ?? []) + ($state['mints'][$id] ?? []);
    if (empty($q)) { reply(404, ['detail' => 'quote not found', 'code' => 20000]); }
    // A quote the stub really issued on reports ISSUED unless the script forces a state.
    if (($state['mints'][$id]['state'] ?? '') === 'ISSUED' && empty($script['mint_quotes'][$id]['force'])) { $q['state'] = 'ISSUED'; }
    reply(200, ['quote' => $id, 'request' => 'lnbcrt1n1stub', 'amount' => (int)($q['amount'] ?? 0), 'unit' => 'sat',
        'state' => $q['state'] ?? 'UNPAID', 'expiry' => array_key_exists('expiry', $q) ? $q['expiry'] : time() + 600]);
}
if ($method === 'POST' && $path === '/v1/mint/bolt11') {
    $quote = $body['quote'] ?? '';
    $sigs = [];
    foreach ($body['outputs'] ?? [] as $o) {
        $C_ = sign($o['B_'], (int)$o['amount']);
        $sigs[] = ['id' => $o['id'], 'amount' => (int)$o['amount'], 'C_' => $C_];
        $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => (int)$o['amount'], 'C_' => $C_];
    }
    $state['mints'][$quote] = ['state' => 'ISSUED'] + ($state['mints'][$quote] ?? []);
    save($state, $dir);
    reply(200, ['signatures' => $sigs]);
}
if ($method === 'POST' && $path === '/v1/swap') {
    foreach ($body['inputs'] ?? [] as $p) {
        if (isset($state['spent'][yOf($p)])) { reply(400, ['detail' => 'Token already spent.', 'code' => 11001]); }
    }
    foreach ($body['inputs'] ?? [] as $p) { $state['spent'][yOf($p)] = true; }
    $sigs = [];
    foreach ($body['outputs'] ?? [] as $o) {
        $C_ = sign($o['B_'], (int)$o['amount']);
        $sigs[] = ['id' => $o['id'], 'amount' => (int)$o['amount'], 'C_' => $C_];
        $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => (int)$o['amount'], 'C_' => $C_];
    }
    save($state, $dir);
    reply(200, ['signatures' => $sigs]);
}
if ($method === 'POST' && $path === '/v1/melt/quote/bolt11') {
    $id = 'mlq_' . bin2hex(random_bytes(4));
    reply(200, ['quote' => $id, 'amount' => 100, 'fee_reserve' => 3, 'unit' => 'sat', 'state' => 'UNPAID', 'expiry' => time() + 600]);
}
if ($method === 'GET' && str_starts_with($path, '/v1/melt/quote/bolt11/')) {
    $id = urldecode(substr($path, 22));
    $q = $script['melt_quotes'][$id] ?? null;
    if ($q === null) { reply(404, ['detail' => 'quote not found', 'code' => 20000]); }
    $st = $state['melts'][$id] ?? [];
    $out = ['quote' => $id, 'amount' => (int)$q['amount'], 'fee_reserve' => (int)($q['fee_reserve'] ?? 0), 'unit' => 'sat',
        'state' => $st['state'] ?? ($q['state'] ?? 'UNPAID'), 'expiry' => array_key_exists('expiry', $q) ? $q['expiry'] : time() + 600];
    if (!empty($q['omit_state'])) { unset($out['state']); }
    if (($st['state'] ?? '') === 'PAID') {
        $out['payment_preimage'] = $st['payment_preimage'];
        if (!empty($q['change_on_get'])) { $out['change'] = $st['change']; }
    }
    reply(200, $out);
}
if ($method === 'POST' && $path === '/v1/melt/bolt11') {
    $quote = $body['quote'] ?? '';
    $q = $script['melt_quotes'][$quote] ?? null;
    if ($q === null) { reply(404, ['detail' => 'quote not found', 'code' => 20000]); }
    $inputs = $body['inputs'] ?? [];
    foreach ($inputs as $p) {
        if (isset($state['spent'][yOf($p)])) { reply(400, ['detail' => 'Token already spent.', 'code' => 11001]); }
    }
    $sum = array_sum(array_column($inputs, 'amount'));
    if ($sum < (int)$q['amount'] + (int)($q['fee_reserve'] ?? 0)) { reply(400, ['detail' => 'not enough inputs', 'code' => 11005]); }
    foreach ($inputs as $p) { $state['spent'][yOf($p)] = true; }
    $fee = (int)($script['melt_fee'] ?? 1);
    $change = max(0, $sum - (int)$q['amount'] - $fee);
    $sigs = [];
    $amounts = decompose($change);
    foreach ($body['outputs'] ?? [] as $i => $o) {
        if (isset($amounts[$i])) {
            $C_ = sign($o['B_'], $amounts[$i]);
            $sigs[] = ['id' => $o['id'], 'amount' => $amounts[$i], 'C_' => $C_];
            $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => $amounts[$i], 'C_' => $C_];
        } elseif (!empty($script['zero_blanks'])) {
            $C_ = sign($o['B_'], 1);
            $sigs[] = ['id' => $o['id'], 'amount' => 0, 'C_' => $C_];
            $state['signed'][strtolower($o['B_'])] = ['id' => $o['id'], 'amount' => 0, 'C_' => $C_];
        }
    }
    $state['melts'][$quote] = ['state' => 'PAID', 'payment_preimage' => str_repeat('cd', 32), 'change' => $sigs];
    save($state, $dir);
    reply(200, ['state' => 'PAID', 'paid' => true, 'payment_preimage' => str_repeat('cd', 32), 'change' => $sigs]);
}
if ($method === 'POST' && $path === '/v1/checkstate') {
    $mode = $script['checkstate'] ?? 'normal';
    if ($mode === 'error') { reply(500, ['detail' => 'boom', 'code' => 0]); }
    $states = [];
    foreach ($body['Ys'] ?? [] as $Y) {
        $st = isset($state['spent'][$Y]) ? 'SPENT' : (isset($state['pending'][$Y]) ? 'PENDING' : 'UNSPENT');
        $states[] = ['Y' => $Y, 'state' => $st, 'witness' => null];
    }
    if ($mode === 'short') { array_pop($states); }
    if ($mode === 'empty') { $states = []; }
    if ($mode === 'reordered') { $states = array_reverse($states); }
    if ($mode === 'wrongY' && $states) { $states[0]['Y'] = '02' . str_repeat('11', 32); }
    if ($mode === 'nostate') { foreach ($states as &$s) { unset($s['state']); } unset($s); }
    reply(200, ['states' => $states]);
}
if ($method === 'POST' && $path === '/v1/restore') {
    $mode = $script['restore'] ?? 'normal';
    if ($mode === 'error') { reply(500, ['detail' => 'boom', 'code' => 0]); }
    $outs = []; $sigs = [];
    if ($mode !== 'none') {
        foreach ($body['outputs'] ?? [] as $o) {
            $k = strtolower($o['B_']);
            if (isset($state['signed'][$k])) {
                $s = $state['signed'][$k];
                $outs[] = ['amount' => $s['amount'], 'id' => $s['id'], 'B_' => $o['B_']];
                $sigs[] = ['id' => $s['id'], 'amount' => $s['amount'], 'C_' => $s['C_']];
            }
        }
    }
    reply(200, ['outputs' => $outs, 'signatures' => $sigs]);
}
reply(404, ['detail' => 'not found', 'code' => 404]);
PHP;
}

/**
 * Start the stub. Returns ['url' => 'http://127.0.0.1:PORT', 'dir' => string, 'pid' => int].
 */
function lib_mint_stub_start(): array
{
    $dir = sys_get_temp_dir() . '/lib_mint_stub_' . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    $lib = dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';
    file_put_contents($dir . '/router.php', str_replace('__LIB__', var_export($lib, true), lib_mint_stub_router_source()));
    file_put_contents($dir . '/script.json', '{}');
    file_put_contents($dir . '/state.json', '{}');
    file_put_contents($dir . '/requests.jsonl', '');

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
        $name = stream_socket_get_name($probe, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $pid = (int) shell_exec(sprintf(
            '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), $port, escapeshellarg($dir), escapeshellarg($dir . '/router.php')
        ));
        register_shutdown_function(static function () use ($pid, $dir) {
            @posix_kill($pid, 9);
            foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($dir);
        });
        for ($i = 0; $i < 120; $i++) {
            $resp = @file_get_contents("http://127.0.0.1:{$port}/__stub");
            if ($resp !== false) {
                $health = json_decode($resp, true);
                if (is_array($health) && ($health['dir'] ?? '') === $dir) {
                    file_put_contents($dir . '/requests.jsonl', '');
                    return ['url' => "http://127.0.0.1:{$port}", 'dir' => $dir, 'pid' => $pid, 'port' => $port];
                }
                break;
            }
            usleep(50000);
        }
        @posix_kill($pid, 9);
    }
    fail('lib mint stub failed to start');
}

/** Replace the stub script (merged with the existing one unless $replace). */
function lib_mint_stub_script(array $stub, array $script, bool $replace = true): void
{
    $current = $replace ? [] : (json_decode((string)file_get_contents($stub['dir'] . '/script.json'), true) ?: []);
    file_put_contents($stub['dir'] . '/script.json', json_encode(array_replace($current, $script)));
}

/** Current stub state (signed outputs, spent Ys, quote states). */
function lib_mint_stub_state(array $stub): array
{
    return json_decode((string)file_get_contents($stub['dir'] . '/state.json'), true) ?: [];
}

/** Overwrite stub state. */
function lib_mint_stub_set_state(array $stub, array $state): void
{
    file_put_contents($stub['dir'] . '/state.json', json_encode($state));
}

/** Requests the stub has served since the last reset, as [{method, path, body}]. */
function lib_mint_stub_requests(array $stub): array
{
    $lines = array_filter(explode("\n", (string)file_get_contents($stub['dir'] . '/requests.jsonl')));
    return array_values(array_map(fn($l) => json_decode($l, true), $lines));
}

function lib_mint_stub_reset_requests(array $stub): void
{
    file_put_contents($stub['dir'] . '/requests.jsonl', '');
}

/** Requests matching "METHOD /v1/path". */
function lib_mint_stub_requests_for(array $stub, string $key): array
{
    return array_values(array_filter(lib_mint_stub_requests($stub), fn($r) => ($r['method'] . ' ' . $r['path']) === $key));
}

/** Mark proofs spent at the stub (e.g. "another wallet redeemed them"). */
function lib_mint_stub_mark_spent(array $stub, array $proofs): void
{
    $state = lib_mint_stub_state($stub);
    foreach ($proofs as $p) {
        $state['spent'][\Cashu\Crypto::computeY($p->secret)] = true;
    }
    lib_mint_stub_set_state($stub, $state);
}

/**
 * A wallet on the stub with a fresh seed (ready), mint loaded.
 * $dbPath defaults to the harness database.
 */
function lib_stub_wallet(array $stub, ?string $dbPath = null, ?string $mnemonic = null, string $unit = 'sat'): \Cashu\Wallet
{
    $dbPath = $dbPath ?? Database::getDbPath();
    $w = new \Cashu\Wallet($stub['url'], $unit, $dbPath);
    $w->loadMint();
    $w->initFromMnemonic($mnemonic ?? \Cashu\Mnemonic::generate());
    return $w;
}

/** Mint $amount sats into the wallet through the stub (a paid quote). */
function lib_stub_fund(array $stub, \Cashu\Wallet $w, int $amount): array
{
    $quote = $w->requestMintQuote($amount);
    lib_mint_stub_script($stub, ['mint_quotes' => [$quote->quote => ['state' => 'PAID', 'amount' => $amount]]], false);
    return $w->mint($quote->quote, $amount);
}

/** Register a melt quote on the stub. */
function lib_stub_melt_quote(array $stub, string $id, int $amount, int $feeReserve = 3, array $extra = []): void
{
    $current = json_decode((string)file_get_contents($stub['dir'] . '/script.json'), true) ?: [];
    $current['melt_quotes'][$id] = ['amount' => $amount, 'fee_reserve' => $feeReserve] + $extra;
    file_put_contents($stub['dir'] . '/script.json', json_encode($current));
}

/** Local proof state rows (secret => state) for a wallet. */
function lib_proof_states(\Cashu\Wallet $w, array $proofs): array
{
    return $w->getStorage()->getProofsStatesBySecrets(array_map(fn($p) => $p->secret, $proofs));
}

/** All distinct local states of the given proofs. */
function lib_states_of(\Cashu\Wallet $w, array $proofs): array
{
    $states = array_values(array_unique(array_values(lib_proof_states($w, $proofs))));
    sort($states);
    return $states;
}
