<?php
/**
 * DX-3: the "token was cashed in" decision starts from "not spent" and needs
 * a complete, in-order NUT-07 reply. An empty or truncated states array used
 * to confirm redemption of proofs the mint never answered for.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$Ys = ['02aa', '02bb', '02cc'];
$spent = fn(string $y) => ['Y' => $y, 'state' => 'SPENT', 'witness' => null];

function expect_throws(callable $fn, string $label): void {
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    fail("expected an exception: {$label}");
}

expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, ['states' => []]), 'empty states');
expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, ['states' => [$spent('02aa'), $spent('02bb')]]), 'truncated list');
expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, ['states' => [$spent('02aa'), $spent('02bb'), $spent('02cc'), $spent('02dd')]]), 'too long');
expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, ['states' => [$spent('02bb'), $spent('02aa'), $spent('02cc')]]), 'swapped Y order');
expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, ['detail' => 'nope']), 'no states key');
expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, null), 'null response');
expect_throws(fn() => Invoice::proofStatesAllSpent($Ys, ['states' => ['SPENT', 'SPENT', 'SPENT']]), 'malformed entries');

assert_true(Invoice::proofStatesAllSpent($Ys, ['states' => [$spent('02aa'), $spent('02bb'), $spent('02cc')]]), 'all SPENT, in order → true');
assert_true(Invoice::proofStatesAllSpent($Ys, ['states' => [
    ['state' => 'spent'], ['state' => 'spent'], ['state' => 'spent'],
]]), 'lowercase spent without Y → true');
assert_false(Invoice::proofStatesAllSpent($Ys, ['states' => [$spent('02aa'), ['Y' => '02bb', 'state' => 'UNSPENT'], $spent('02cc')]]), 'one UNSPENT → false');
assert_false(Invoice::proofStatesAllSpent($Ys, ['states' => [$spent('02aa'), ['Y' => '02bb', 'state' => 'PENDING'], $spent('02cc')]]), 'one PENDING → false');
assert_false(Invoice::proofStatesAllSpent([], ['states' => []]), 'nothing asked → nothing spent');

// Uppercase Y in the reply matches a lowercase request (hex is case-insensitive).
assert_true(Invoice::proofStatesAllSpent(['02aa'], ['states' => [['Y' => '02AA', 'state' => 'SPENT']]]), 'Y case-insensitive');

fwrite(STDERR, "test_proof_states_fail_closed: all assertions passed\n");
