<?php
/**
 * CashuEnv::environmentError() — the gate that greys out the "Cashu automatic
 * cashout" card. Cashu runs on GMP OR BCMath, so the gate must fire only when
 * BOTH are unusable; GMP alone missing (the common hardened-host case, which
 * kills on-chain/swaps/noffer/NWC) must leave Cashu alive. Subprocess shape
 * mirrors test_nwc_gmp_less_host.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

const GMP_FUNCS = 'gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,'
    . 'gmp_strval,gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,'
    . 'gmp_and,gmp_or,gmp_setbit,gmp_testbit,gmp_neg';
const BCMATH_FUNCS = 'bcadd,bcsub,bcmul,bcdiv,bcmod,bcpow,bcpowmod,bccomp,bcsqrt,bcscale';

/** Run $code in a child PHP with the given functions disabled. */
function run_with_disabled(string $disabled, string $code): array {
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=' . escapeshellarg($disabled)
        . ' -d error_reporting=E_ALL -d display_errors=1'
        . ' -r ' . escapeshellarg($code) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

$root = dirname(__DIR__, 2) . '/app';
$prelude = 'require_once ' . var_export($root . '/includes/cashu_env.php', true) . ';'
    . 'echo CashuEnv::environmentError() ?? "NULL";';

// 1. Both GMP and BCMath gone → the gate fires with an actionable message.
[$rc, $out] = run_with_disabled(GMP_FUNCS . ',' . BCMATH_FUNCS, $prelude);
assert_eq(0, $rc, "both-disabled probe exited cleanly: $out");
assert_true(stripos($out, 'GMP') !== false && stripos($out, 'BCMath') !== false,
    "gate message names both extensions: $out");
assert_true(stripos($out, 'php-gmp') !== false,
    "gate message tells the operator what to ask for: $out");

// 2. Only GMP gone (BCMath alive) → Cashu still works, gate stays open.
[$rc, $out] = run_with_disabled(GMP_FUNCS, $prelude);
assert_eq(0, $rc, "gmp-only-disabled probe exited cleanly: $out");
assert_eq('NULL', trim($out), 'GMP alone missing does not disable Cashu (BCMath suffices)');

// 3. Only BCMath gone (GMP alive) → gate stays open too.
[$rc, $out] = run_with_disabled(BCMATH_FUNCS, $prelude);
assert_eq(0, $rc, "bcmath-only-disabled probe exited cleanly: $out");
assert_eq('NULL', trim($out), 'BCMath alone missing does not disable Cashu (GMP suffices)');

// 4. This parent process has both → open.
require_once $root . '/includes/cashu_env.php';
assert_null(CashuEnv::environmentError(), 'parent process gate open');

echo "test_cashu_env_gate: OK\n";
