<?php
/**
 * Library — LP-1 / LP-3: hostile token and mint-response inputs become a
 * CashuException, never a TypeError / warning / memory blow-up:
 *   string amount, missing C, non-base64 payload, trailing CBOR bytes, 17-deep
 *   nesting, 65-entry map, integer map key, string length > remaining, 8-byte
 *   wrapped length, multi-mint V3, 1001 proofs, empty proofs, oversized token;
 *   a valid V3/V4 token still round-trips (with DLEQ);
 *   MintQuote/MeltQuote::fromArray typed reads; MeltQuote without state is UNKNOWN.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';

use Cashu\CashuException;
use Cashu\CBOR;
use Cashu\DLEQWallet;
use Cashu\MeltQuote;
use Cashu\MintQuote;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\TokenSerializer;
use Cashu\Wallet;

$ks = '00aabbccddeeff01';
$C = '02' . str_repeat('ab', 32);
$mint = 'https://mint.example';

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function v3(array $data): string { return 'cashuA' . b64url(json_encode($data)); }
function v4raw(string $cbor): string { return 'cashuB' . b64url($cbor); }
function expect_cashu(string $what, callable $fn): void {
    try { $fn(); } catch (CashuException $e) { return; } catch (\Throwable $e) {
        fail("$what: expected CashuException, got " . get_class($e) . ': ' . $e->getMessage());
    }
    fail("$what: expected CashuException, nothing thrown");
}

// ---- round trips -----------------------------------------------------------------------
$p = new Proof($ks, 8, 'secret-x', $C, new DLEQWallet('aa', 'bb', 'cc'));
$w = new Wallet($mint, 'sat');
foreach (['v3', 'v4'] as $fmt) {
    $tok = $w->serializeToken([$p], $fmt, 'memo', true);
    $t = TokenSerializer::deserialize($tok);
    assert_eq($mint, $t->mint, "$fmt mint");
    assert_eq('sat', $t->unit, "$fmt unit");
    assert_eq('memo', $t->memo, "$fmt memo");
    assert_eq(8, $t->proofs[0]->amount, "$fmt amount");
    assert_eq('secret-x', $t->proofs[0]->secret, "$fmt secret");
    assert_eq($C, $t->proofs[0]->C, "$fmt C");
    assert_eq('cc', $t->proofs[0]->dleq->r, "$fmt dleq r");
    assert_eq($ks, $t->proofs[0]->id, "$fmt keyset id");
}
$t = TokenSerializer::deserialize("  " . $w->serializeToken([$p], 'v4') . "\n");
assert_eq(1, count($t->proofs), 'surrounding whitespace tolerated');

// ---- hostile V3 ------------------------------------------------------------------------
$good = ['id' => $ks, 'amount' => 8, 'secret' => 's', 'C' => $C];
expect_cashu('string amount', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['amount' => '5'] + $good]]]])));
expect_cashu('missing C', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['id' => $ks, 'amount' => 8, 'secret' => 's']]]]])));
expect_cashu('missing id', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['amount' => 8, 'secret' => 's', 'C' => $C]]]]])));
expect_cashu('zero amount', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['amount' => 0] + $good]]]])));
expect_cashu('negative amount', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['amount' => -1] + $good]]]])));
expect_cashu('float amount', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['amount' => 8.5] + $good]]]])));
expect_cashu('malformed C', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['C' => 'zz'] + $good]]]])));
expect_cashu('array secret', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['secret' => ['x']] + $good]]]])));
expect_cashu('array mint', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => ['x'], 'proofs' => [$good]]]])));
expect_cashu('array unit', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [$good]]], 'unit' => ['sat']])));
expect_cashu('malformed keyset id', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['id' => 'nope'] + $good]]]])));
expect_cashu('multi-mint V3', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [$good]], ['mint' => 'https://other.example', 'proofs' => [$good]]]])));
expect_cashu('no proofs', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => []]]])));
expect_cashu('token not array', fn() => TokenSerializer::deserialize(v3(['token' => 'x'])));
expect_cashu('non-base64 payload', fn() => TokenSerializer::deserialize('cashuA***not*base64***'));
expect_cashu('empty payload', fn() => TokenSerializer::deserialize('cashuA'));
expect_cashu('non-JSON payload', fn() => TokenSerializer::deserialize('cashuA' . b64url('{{{')));
expect_cashu('unknown format', fn() => TokenSerializer::deserialize('cashuZ' . b64url('{}')));
$many = array_fill(0, 1001, $good);
foreach ($many as $i => &$pp) { $pp['secret'] = "s$i"; } unset($pp);
expect_cashu('1001 proofs', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => $many]]])));
expect_cashu('oversized token', fn() => TokenSerializer::deserialize('cashuA' . str_repeat('A', TokenSerializer::MAX_TOKEN_LENGTH + 1)));
$proofWitness = ['witness' => ['x']] + $good;
expect_cashu('array witness', fn() => TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [$proofWitness]]]])));
$t = TokenSerializer::deserialize(v3(['token' => [['mint' => $mint, 'proofs' => [['dleq' => ['e' => 'aa', 's' => 7]] + $good]]]]));
assert_null($t->proofs[0]->dleq, 'malformed V3 dleq is dropped, not fatal');

// ---- hostile V4 / CBOR -----------------------------------------------------------------
expect_cashu('trailing CBOR data', fn() => CBOR::decode(CBOR::encode(['m' => $mint]) . "\x00"));
expect_cashu('17-deep nesting', fn() => CBOR::decode(str_repeat("\x81", 17) . "\x01"));
expect_cashu('4MB deep nesting', fn() => CBOR::decode(str_repeat("\x81", 4000000)));
$map65 = "\xb8\x41"; for ($i = 0; $i < 65; $i++) { $map65 .= "\x62k" . chr(48 + $i % 10) . "\x01"; }
expect_cashu('65-entry map', fn() => CBOR::decode($map65));
expect_cashu('integer map key', fn() => CBOR::decode("\xa1\x01\x02"));
expect_cashu('string length > remaining', fn() => CBOR::decode("\x65ab"));
expect_cashu('array length > remaining', fn() => CBOR::decode("\x9a\x7f\xff\xff\xff\x01"));
expect_cashu('8-byte wrapped length', fn() => CBOR::decode("\x5b\xff\xff\xff\xff\xff\xff\xff\xff"));
expect_cashu('truncated length header', fn() => CBOR::decode("\x59\x01"));
expect_cashu('unknown simple value', fn() => CBOR::decode("\xf8\x01"));
assert_eq(['a' => [1, -2, 'x', true, null]], CBOR::decode(CBOR::encode(['a' => [1, -2, 'x', true, null]])), 'well-formed CBOR round-trips');
expect_cashu('V4 missing mint', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['u' => 'sat', 't' => []]))));
expect_cashu('V4 no proofs', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['m' => $mint, 't' => []]))));
expect_cashu('V4 string amount', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['m' => $mint, 't' => [['i' => hex2bin($ks), 'p' => [['a' => '5', 's' => 's', 'c' => hex2bin($C)]]]]]))));
expect_cashu('V4 missing c', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['m' => $mint, 't' => [['i' => hex2bin($ks), 'p' => [['a' => 5, 's' => 's']]]]]))));
expect_cashu('V4 int keyset id', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['m' => $mint, 't' => [['i' => 5, 'p' => [['a' => 5, 's' => 's', 'c' => hex2bin($C)]]]]]))));
expect_cashu('V4 malformed dleq', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['m' => $mint, 't' => [['i' => hex2bin($ks), 'p' => [['a' => 5, 's' => 's', 'c' => hex2bin($C), 'd' => ['e' => 'x']]]]]]))));
expect_cashu('V4 trailing bytes', fn() => TokenSerializer::deserialize(v4raw(CBOR::encode(['m' => $mint, 't' => [['i' => hex2bin($ks), 'p' => [['a' => 5, 's' => 's', 'c' => hex2bin($C)]]]]]) . 'zz')));

// receive()/receiveOffline() never see a TypeError either (duplicate proofs refused first).
$storageWallet = new Wallet($mint, 'sat', sys_get_temp_dir() . '/lib_dec_' . bin2hex(random_bytes(3)) . '.sqlite');
$dup = $w->serializeToken([$p, $p], 'v4');
expect_cashu('duplicate proofs on receiveOffline', fn() => $storageWallet->receiveOffline($dup));
expect_cashu('duplicate proofs on receive', fn() => $storageWallet->receive($dup));
expect_cashu('string amount on receive', fn() => $storageWallet->receive(v3(['token' => [['mint' => $mint, 'proofs' => [['amount' => '5'] + $good]]]])));
expect_cashu('other mint on receiveOffline', fn() => $storageWallet->receiveOffline((new Wallet('https://other.example', 'sat'))->serializeToken([$p])));
expect_cashu('other unit on receiveOffline', fn() => $storageWallet->receiveOffline((new Wallet($mint, 'usd'))->serializeToken([$p])));
$t = TokenSerializer::deserialize((new Wallet('HTTPS://Mint.Example/', 'sat'))->serializeToken([$p]));
assert_eq(1, count($storageWallet->receiveOffline((new Wallet('HTTPS://Mint.Example/', 'sat'))->serializeToken([$p]))), 'same mint in another spelling is accepted offline');

// ---- typed mint-response reads -----------------------------------------------------------
expect_cashu('mint quote missing quote', fn() => MintQuote::fromArray(['request' => 'lnbc1', 'amount' => 5]));
expect_cashu('mint quote string amount', fn() => MintQuote::fromArray(['quote' => 'q', 'request' => 'lnbc1', 'amount' => 'five']));
expect_cashu('mint quote array state', fn() => MintQuote::fromArray(['quote' => 'q', 'request' => 'lnbc1', 'amount' => 5, 'state' => ['PAID']]));
$q = MintQuote::fromArray(['quote' => 'q', 'request' => 'lnbc1', 'amount' => '5', 'state' => 'PAID', 'expiry' => 10]);
assert_eq(5, $q->amount, 'digit-string amount accepted');
assert_true($q->isPaid(), 'state read');
expect_cashu('melt quote missing amount', fn() => MeltQuote::fromArray(['quote' => 'q']));
expect_cashu('melt quote array change', fn() => MeltQuote::fromArray(['quote' => 'q', 'amount' => 1, 'change' => 'x']));
expect_cashu('melt quote negative fee', fn() => MeltQuote::fromArray(['quote' => 'q', 'amount' => 1, 'fee_reserve' => -1]));
$mq = MeltQuote::fromArray(['quote' => 'q', 'amount' => 1]);
assert_eq(ProofState::UNKNOWN, $mq->state, 'missing melt state is UNKNOWN');
assert_false($mq->isUnpaid(), 'UNKNOWN is not UNPAID');
assert_true(MeltQuote::fromArray(['quote' => 'q', 'amount' => 1, 'state' => 'unpaid'])->isUnpaid(), 'explicit unpaid (any case)');

echo "PASS test_lib_token_decoder_hostile\n";
exit(0);
