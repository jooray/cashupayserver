<?php
/**
 * Urls::lightningUri — the lightning: URI every open-in-wallet link and QR
 * on the payment page carries.
 *
 * The QR codes have always encoded the bolt11 upper-case; the buttons used
 * to send it lower-case, and Cash App declined the lower-case link while
 * paying the identical invoice from the QR. The helper upper-cases, keeps
 * the scheme lower-case (as the QR did), and tolerates an input that
 * already carries the scheme so no caller can double-prefix it.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

$bolt11 = 'lnbc210u1pexamplebolt11stringqpzry9x8gf2tvdw0s3jn54khce6mua7l';
$upper = 'LNBC210U1PEXAMPLEBOLT11STRINGQPZRY9X8GF2TVDW0S3JN54KHCE6MUA7L';

assert_eq('lightning:' . $upper, Urls::lightningUri($bolt11), 'upper-cases the invoice, keeps the scheme lower-case');
assert_eq('lightning:' . $upper, Urls::lightningUri($upper), 'an already upper-case invoice is unchanged');
assert_eq('lightning:' . $upper, Urls::lightningUri('lightning:' . $bolt11), 'a lightning:-prefixed input is not double-prefixed');
assert_eq('lightning:' . $upper, Urls::lightningUri('LIGHTNING:' . $bolt11), 'an upper-case scheme prefix is recognised too');
assert_eq('lightning:' . $upper, Urls::lightningUri("  $bolt11\n"), 'surrounding whitespace is dropped');
assert_eq('lightning:LNBCRT10N1PTESTREGTEST', Urls::lightningUri('lnbcrt10n1ptestregtest'), 'regtest/testnet prefixes get the same treatment');
assert_eq('lightning:', Urls::lightningUri(''), 'empty input yields a bare scheme (callers gate on the bolt11 first)');

// Bech32 is case-insensitive only when the case is uniform: the result must
// never mix cases, whatever the caller handed in.
$mixed = Urls::lightningUri('LnBc210U1pExAmPlE');
assert_eq('lightning:LNBC210U1PEXAMPLE', $mixed, 'mixed-case input is normalised to one case');

echo "test_lightning_uri: all assertions passed\n";
