<?php
/**
 * NWC environment gates on hosts without GMP — the NIP-47 sibling of
 * test_clink_gmp_less_host.
 *
 * Since the NostrCrypto/BigNum port, NWC runs on ext-gmp OR ext-bcmath.
 * This suite pins the contract:
 *
 *   1. GMP disabled, BCMath present → environmentError() is null and
 *      environmentNotice() carries the "enable php-gmp for speed" advisory.
 *   2. GMP disabled, BCMath present → a full NIP-47 make_invoice round trip
 *      against the mock wallet (running in THIS process's PHP, GMP + real
 *      nostr-php crypto) succeeds from the gmp-less child: signing, NIP-04
 *      encryption, and reply signature verification all on BCMath.
 *   3. GMP AND BCMath disabled → the gate fires naming php-gmp and
 *      php-bcmath, and makeInvoice throws a catchable NwcException before
 *      touching the network.
 *   4. GMP present (parent) → gate open, no advisory.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/nwc/client.php';

use swentel\nostr\Key\Key;

const GMP_FUNCS = 'gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,'
    . 'gmp_strval,gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,'
    . 'gmp_and,gmp_or,gmp_setbit,gmp_testbit,gmp_neg';
const BC_FUNCS = 'bcadd,bcsub,bcmul,bcdiv,bcmod,bccomp,bcpow,bcpowmod,bcsqrt,bcscale';

// Structurally valid connection string; never dialled (dead loopback port).
const TEST_NWC_URI = 'nostr+walletconnect://'
    . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    . '?relay=ws://127.0.0.1:1/&secret='
    . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/** Run $code in a child PHP with $disabled functions off; return [exitCode, output]. */
function run_with_disabled(string $disabled, string $code): array {
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=' . escapeshellarg($disabled)
        . ' -d error_reporting=E_ALL -d display_errors=1'
        . ' -r ' . escapeshellarg($code) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

$root = dirname(__DIR__, 2) . '/app';
$prelude = 'require_once ' . var_export($root . '/includes/nwc/client.php', true) . ';';

// 1. GMP disabled + BCMath present: gate OPEN, advisory fires.
[$rc, $out] = run_with_disabled(GMP_FUNCS,
    $prelude . '
    var_export(NwcClient::environmentError() === null);
    echo "|";
    echo (string)NwcClient::environmentNotice();'
);
assert_eq(0, $rc, "bcmath-fallback probe exited cleanly: $out");
[$gateOpen, $notice] = explode('|', $out, 2);
assert_eq('true', $gateOpen, "gate open on a GMP-less host with BCMath: $out");
assert_true(stripos($notice, 'BCMath') !== false, "advisory says BCMath fallback is active: $notice");
assert_true(stripos($notice, 'php-gmp') !== false, "advisory tells the operator what to enable: $notice");

// 2. Full NIP-47 round trip from a gmp-less child against a GMP mock wallet.
$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();

/** Start the mock wallet; returns [proc, port]. */
function start_wallet(array $env): array {
    static $seq = 0;
    $base = 28600 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_NWC_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/php/mock_nwc_wallet.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail('mock NWC wallet failed to start on any port');
}

foreach (['' /* nip04 baseline */, 'nip44_v2 nip04'] as $encryption) {
    $env = ['MOCK_NWC_WALLET_SK' => $walletSk, 'MOCK_NWC_STATE' => 'pending'];
    if ($encryption !== '') {
        $env['MOCK_NWC_ENCRYPTION'] = $encryption;
    }
    [$proc, $port] = start_wallet($env);
    try {
        $uri = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$port}&secret={$clientSk}";
        [$rc, $out] = run_with_disabled(GMP_FUNCS,
            $prelude . '
            try {
                $res = NwcClient::makeInvoice(' . var_export($uri, true) . ', 21, "gmp-less test", 600, 15);
                echo "OK:" . $res["bolt11"];
            } catch (Throwable $e) {
                echo get_class($e) . ":" . $e->getMessage();
            }'
        );
        $label = $encryption === '' ? 'nip04' : 'nip44';
        assert_eq(0, $rc, "gmp-less makeInvoice ({$label}) exited cleanly: $out");
        assert_true(str_starts_with($out, 'OK:lnbc210n1'), "gmp-less child completed a {$label} make_invoice round trip: $out");
    } finally {
        if (is_resource($proc)) { proc_terminate($proc); }
    }
}

// 3. Neither GMP nor BCMath: gate fires, makeInvoice throws it pre-network.
[$rc, $out] = run_with_disabled(GMP_FUNCS . ',' . BC_FUNCS,
    $prelude . 'echo (string)NwcClient::environmentError();'
);
assert_eq(0, $rc, "no-bignum probe exited cleanly: $out");
assert_true(stripos($out, 'GMP') !== false, "gate message names GMP: $out");
assert_true(stripos($out, 'php-gmp') !== false, "gate message tells the operator what to ask for: $out");
assert_true(stripos($out, 'BCMath') !== false, "gate message names the BCMath alternative: $out");

[$rc, $out] = run_with_disabled(GMP_FUNCS . ',' . BC_FUNCS,
    $prelude . '
    try {
        NwcClient::makeInvoice(' . var_export(TEST_NWC_URI, true) . ', 1, null, null, 1);
        echo "NO_THROW";
    } catch (NwcException $e) {
        echo "NWC:" . $e->getMessage();
    } catch (Throwable $e) {
        echo get_class($e) . ":" . $e->getMessage();
    }'
);
assert_eq(0, $rc, "no-bignum makeInvoice path exited cleanly: $out");
assert_true(
    str_starts_with($out, 'NWC:') && stripos($out, 'GMP') !== false,
    "makeInvoice threw actionable NwcException: $out"
);

// 4. With GMP present (this parent process): gate open, no advisory.
assert_null(NwcClient::environmentError(), 'parent process has GMP: gate open');
assert_null(NwcClient::environmentNotice(), 'no BCMath advisory when GMP is active');

echo "test_nwc_gmp_less_host: OK\n";
