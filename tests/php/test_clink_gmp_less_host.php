<?php
/**
 * CLINK noffer environment gates on hosts without GMP.
 *
 * Since the NostrCrypto/BigNum port, the noffer payer path runs on ext-gmp OR
 * ext-bcmath — GMP-less shared hosts (the common WordPress case) fall back to
 * BCMath instead of losing the feature. This suite pins the whole contract:
 *
 *   1. GMP disabled, BCMath present → environmentError() is null (feature
 *      works) and environmentNotice() carries the "enable php-gmp for speed"
 *      advisory.
 *   2. GMP disabled, BCMath present → the real crypto path works end to end:
 *      a receipt signed in the parent (GMP) verifies via
 *      ClinkClient::verifyReceiptEvent in the gmp-less child, and a tampered
 *      copy is rejected.
 *   3. GMP disabled, BCMath present → requestInvoice gets past the
 *      environment gate and fails on TRANSPORT (dead relay), not on math.
 *   4. GMP AND BCMath disabled → the gate fires with an actionable message
 *      naming php-gmp and php-bcmath, and requestInvoice throws a catchable
 *      ClinkException before touching the network.
 *   5. GMP present (parent) → gate open, no advisory notice.
 *
 * A GMP-less host is simulated by running assertions in a child PHP with the
 * gmp_* functions disabled — calling a disabled function raises the same
 * Error an absent extension does, and function_exists() reports false.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

const GMP_FUNCS = 'gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,'
    . 'gmp_strval,gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,'
    . 'gmp_and,gmp_or,gmp_setbit,gmp_testbit,gmp_neg';
const BC_FUNCS = 'bcadd,bcsub,bcmul,bcdiv,bcmod,bccomp,bcpow,bcpowmod,bcsqrt,bcscale';

// Reference noffer from @shocknet/clink-sdk — structurally valid, never
// dialled here (relay ws://127.0.0.1:1 is a dead loopback port).
const REFERENCE_NOFFER = 'noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsen'
    . 'rxumnyvesxfnrswfkvycrwdp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue'
    . '69uhhgetnwskhyetvv9ujumrfva58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008f'
    . 'd8ygtl4qy06gstpye3h5unc47xmee6z';

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
$prelude = 'require_once ' . var_export($root . '/includes/clink/client.php', true) . ';';

// 1. GMP disabled + BCMath present: the gate is OPEN and the advisory fires.
[$rc, $out] = run_with_disabled(GMP_FUNCS,
    $prelude . '
    var_export(ClinkClient::environmentError() === null);
    echo "|";
    echo (string)ClinkClient::environmentNotice();'
);
assert_eq(0, $rc, "bcmath-fallback probe exited cleanly: $out");
[$gateOpen, $notice] = explode('|', $out, 2);
assert_eq('true', $gateOpen, "gate open on a GMP-less host with BCMath: $out");
assert_true(stripos($notice, 'BCMath') !== false, "advisory says BCMath fallback is active: $notice");
assert_true(stripos($notice, 'php-gmp') !== false, "advisory tells the operator what to enable: $notice");

// 2. The real crypto path works in the gmp-less child: build a genuine paid
//    receipt here (parent, GMP), verify it there (child, BCMath).
require_once $root . '/includes/clink/client.php';
$merchantSk = NostrCrypto::generatePrivateKeyHex();
$merchantPk = NostrCrypto::derivePublicKeyHex($merchantSk);
$ephemeralSk = NostrCrypto::generatePrivateKeyHex();
$requestId = str_repeat('ab', 32);
$convKey = NostrCrypto::nip44ConversationKey($merchantSk, NostrCrypto::derivePublicKeyHex($ephemeralSk));

$receipt = new \swentel\nostr\Event\Event();
$receipt->setKind(21001);
$receipt->setCreatedAt(time());
$receipt->setTags([['p', NostrCrypto::derivePublicKeyHex($ephemeralSk)], ['e', $requestId]]);
$receipt->setContent(\swentel\nostr\Encryption\Nip44::encrypt(json_encode(['res' => 'ok']), $convKey));
NostrCrypto::signEvent($receipt, $merchantSk);
$receiptArr = $receipt->toArray();

$ctx = [
    'relay' => 'ws://127.0.0.1:1/',
    'receiver_pubkey' => $merchantPk,
    'ephemeral_sk' => $ephemeralSk,
    'ephemeral_pubkey' => NostrCrypto::derivePublicKeyHex($ephemeralSk),
    'request_event_id' => $requestId,
    'created_at' => $receiptArr['created_at'],
];
$tampered = $receiptArr;
$tampered['tags'][1][1] = str_repeat('cd', 32); // point the receipt at another request

[$rc, $out] = run_with_disabled(GMP_FUNCS,
    $prelude . '
    $genuine = ClinkClient::verifyReceiptEvent(' . var_export($receiptArr, true) . ', ' . var_export($ctx, true) . ');
    $forged = ClinkClient::verifyReceiptEvent(' . var_export($tampered, true) . ', ' . var_export($ctx, true) . ');
    echo ($genuine["paid"] ? "GENUINE_PAID" : "GENUINE_REJECTED") . "|"
        . ($forged["paid"] ? "FORGED_PAID" : "FORGED_REJECTED");'
);
assert_eq(0, $rc, "receipt verification probe exited cleanly: $out");
assert_eq('GENUINE_PAID|FORGED_REJECTED', trim($out), "gmp-less child verifies genuine receipts and rejects tampered ones: $out");

// 3. requestInvoice on the gmp-less host reaches the network layer (dead
//    relay → transport error), proving the environment gate didn't fire.
[$rc, $out] = run_with_disabled(GMP_FUNCS,
    $prelude . '
    try {
        ClinkClient::requestInvoice(' . var_export(REFERENCE_NOFFER, true) . ', 1000, null, 1);
        echo "NO_THROW";
    } catch (ClinkException $e) {
        echo "CLINK:" . $e->getMessage();
    } catch (Throwable $e) {
        echo get_class($e) . ":" . $e->getMessage();
    }'
);
assert_eq(0, $rc, "requestInvoice path exited cleanly: $out");
assert_true(str_starts_with($out, 'CLINK:'), "requestInvoice threw a catchable ClinkException: $out");
assert_true(
    stripos($out, 'relay') !== false || stripos($out, 'response') !== false,
    "failure is transport (relay error / no response), not math: $out"
);
assert_true(stripos($out, 'GMP') === false, "no GMP complaint on a BCMath host: $out");

// 4. Neither GMP nor BCMath: the gate fires with an actionable message and
//    requestInvoice throws it as a catchable ClinkException pre-network.
[$rc, $out] = run_with_disabled(GMP_FUNCS . ',' . BC_FUNCS,
    $prelude . 'echo (string)ClinkClient::environmentError();'
);
assert_eq(0, $rc, "no-bignum probe exited cleanly: $out");
assert_true(stripos($out, 'GMP') !== false, "gate message names GMP: $out");
assert_true(stripos($out, 'php-gmp') !== false, "gate message tells the operator what to ask for: $out");
assert_true(stripos($out, 'BCMath') !== false, "gate message names the BCMath alternative: $out");

[$rc, $out] = run_with_disabled(GMP_FUNCS . ',' . BC_FUNCS,
    $prelude . '
    try {
        ClinkClient::requestInvoice(' . var_export(REFERENCE_NOFFER, true) . ', 1000, null, 1);
        echo "NO_THROW";
    } catch (ClinkException $e) {
        echo "CLINK:" . $e->getMessage();
    } catch (Throwable $e) {
        echo get_class($e) . ":" . $e->getMessage();
    }'
);
assert_eq(0, $rc, "no-bignum requestInvoice path exited cleanly: $out");
assert_true(
    str_starts_with($out, 'CLINK:') && stripos($out, 'GMP') !== false,
    "requestInvoice threw the actionable gate message: $out"
);

// 5. With GMP present (this parent process): gate open, no advisory.
assert_null(ClinkClient::environmentError(), 'parent process has GMP: gate open');
assert_null(ClinkClient::environmentNotice(), 'no BCMath advisory when GMP is active');

echo "test_clink_gmp_less_host: OK\n";
