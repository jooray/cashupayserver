<?php
/**
 * Regression test for the two GMP-less-host bugs reported from a shared
 * WordPress install (PHP without ext-gmp):
 *
 *   1. "Check my xpub" in the setup wizard died with an uncaught
 *      Error (gmp_init undefined) inside OnchainWallet::validateXpub, turning
 *      the JSON endpoint into an HTML fatal — which the wizard JS could only
 *      report as "could not reach the server".
 *   2. A valid static address was rejected with "we could not read that as a
 *      Bitcoin address": validateAddress swallowed the same Error and
 *      reported the address as unparseable on every network.
 *
 * A GMP-less host is simulated by running assertions in a child PHP with the
 * gmp_* functions disabled — calling a disabled function raises the same
 * Error an absent extension does. Asserts that on such a host:
 *   - environmentError() reports the problem,
 *   - validateXpub RETURNS an actionable error (never throws),
 *   - validateAddress still fully validates every supported static-address
 *     encoding (pure-PHP fallback), and still rejects damaged input,
 *   - deriveAddress throws a RuntimeException carrying the actionable
 *     message rather than a bare "undefined function".
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

const GMP_FUNCS = 'gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,'
    . 'gmp_strval,gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,'
    . 'gmp_and,gmp_or,gmp_setbit,gmp_testbit,gmp_neg';

/** Run $code in a child PHP with gmp_* disabled; return [exitCode, output]. */
function run_without_gmp(string $code): array {
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=' . escapeshellarg(GMP_FUNCS)
        . ' -d error_reporting=E_ALL -d display_errors=1'
        . ' -r ' . escapeshellarg($code) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

$root = dirname(__DIR__, 2) . '/app';
$prelude = 'require_once ' . var_export($root . '/includes/onchain/wallet.php', true) . ';';

// Sanity: the child really is GMP-less.
[$rc, $out] = run_without_gmp(
    $prelude . 'var_export(OnchainWallet::environmentError() !== null);'
);
assert_eq(0, $rc, "environmentError probe exited cleanly: $out");
assert_eq('true', $out, 'environmentError() is non-null without GMP');

// 1. validateXpub must RETURN an actionable error, not throw. An exception
//    here is exactly the original bug (fatal -> HTML error page -> the wizard
//    shows "could not reach the server").
$xpub = 'xpub6AHA9hZDN11k2ijHMeS5QqHx2KP9aMBRhTDqANMnwVtdyw2TDYRmF8Pjpvw'
    . 'UFcL1Et8Hj59S3gTSMcUQ5gAqTz3Wd8EsMTmF3DChhqPQBnU';
[$rc, $out] = run_without_gmp(
    $prelude
    . '$r = OnchainWallet::validateXpub(' . var_export($xpub, true) . ', "mainnet", "P2WPKH");'
    . 'echo json_encode($r);'
);
assert_eq(0, $rc, "validateXpub did not fatal without GMP: $out");
$r = json_decode($out, true);
assert_not_null($r, "validateXpub emitted parseable JSON: $out");
assert_false($r['valid'], 'xpub not accepted when it cannot be derived from');
assert_true(
    stripos((string)$r['error'], 'GMP') !== false,
    'error names the missing GMP extension: ' . (string)$r['error']
);

// 2. validateAddress must accept valid static addresses of every supported
//    encoding via the pure-PHP fallback — including the exact address from
//    the bug report — and still reject a damaged one.
[$rc, $out] = run_without_gmp(
    $prelude . '
    $results = [];
    foreach ([
        ["bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq", "mainnet"],
        ["17VZNX1SN5NtKa8UQFxwQbFeFc3iqRYhem", "mainnet"],
        ["3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy", "mainnet"],
        ["bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0", "mainnet"],
        ["tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx", "testnet"],
        ["bcrt1qw508d6qejxtdg4y5r3zarvary0c5xw7kygt080", "regtest"],
    ] as [$a, $n]) {
        $results[] = OnchainWallet::validateAddress($a, $n)["valid"];
    }
    $results[] = OnchainWallet::validateAddress("bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdp", "mainnet")["valid"];
    $results[] = OnchainWallet::validateAddress("bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq", "testnet")["valid"];
    echo json_encode($results);'
);
assert_eq(0, $rc, "validateAddress did not fatal without GMP: $out");
assert_eq(
    [true, true, true, true, true, true, false, false],
    json_decode($out, true),
    'all valid encodings accepted, damaged + wrong-network rejected, without GMP'
);

// 3. deriveAddress: actionable RuntimeException, not "undefined function".
[$rc, $out] = run_without_gmp(
    $prelude . '
    try {
        OnchainWallet::deriveAddress(' . var_export($xpub, true) . ', "P2WPKH", "mainnet", 0);
        echo "NO_THROW";
    } catch (RuntimeException $e) {
        echo "RUNTIME:" . $e->getMessage();
    }'
);
assert_eq(0, $rc, "deriveAddress path exited cleanly: $out");
assert_true(
    str_starts_with($out, 'RUNTIME:') && stripos($out, 'GMP') !== false,
    "deriveAddress threw actionable RuntimeException: $out"
);

// 4. With GMP present (this parent process), behaviour is unchanged: the same
//    xpub validates and the same static address passes the bitwasp path.
require_once $root . '/includes/onchain/wallet.php';
assert_null(OnchainWallet::environmentError(), 'parent process has GMP');
assert_true(
    OnchainWallet::validateXpub($xpub, 'mainnet', 'P2WPKH')['valid'],
    'xpub validates normally with GMP present'
);
assert_true(
    OnchainWallet::validateAddress('bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 'mainnet')['valid'],
    'reported address validates with GMP present'
);

echo "test_onchain_gmp_less_host: OK\n";
