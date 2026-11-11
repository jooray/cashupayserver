<?php
/**
 * Unit tests for PaymentPathDebug — the admin-only payment-path label helper
 * shown on the customer payment page.
 *
 * Two concerns:
 *   1. Label mapping per rail / on-chain mode / cashu (pure string building).
 *   2. The site-wide enabled() toggle (default OFF, reads Config).
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';
fresh_db();

require_once dirname(__DIR__, 2) . '/app/includes/payment_path_debug.php';

// ---- enabled(): default OFF, flips with the config key --------------------

assert_false(PaymentPathDebug::enabled(), 'default is OFF when key unset');

Config::set(PaymentPathDebug::CONFIG_KEY, true);
assert_true(PaymentPathDebug::enabled(), 'ON after setting bool true');

Config::set(PaymentPathDebug::CONFIG_KEY, false);
assert_false(PaymentPathDebug::enabled(), 'OFF after setting bool false');

// A stray non-bool value must not read as enabled (strict === true).
Config::set(PaymentPathDebug::CONFIG_KEY, '1');
assert_false(PaymentPathDebug::enabled(), 'string "1" is not strict-true');

// ---- Lightning labels: one per rail ---------------------------------------

assert_eq(
    'LNURL / Lightning address → merchant@example.com',
    PaymentPathDebug::lightningLabel([
        'payment_rail' => 'lnaddress',
        'ln_destination' => 'merchant@example.com',
    ]),
    'lnaddress rail'
);

assert_eq(
    'Fee-redirect Lightning (LNURL) → fees@example.com',
    PaymentPathDebug::lightningLabel([
        'payment_rail' => 'lnaddress',
        'ln_destination' => 'fees@example.com',
        'fee_redirect_rails' => 'lightning',
    ]),
    'fee-redirect lightning rides lnaddress but is distinguished'
);

assert_eq(
    'CLINK noffer (NIP-69) → noffer1abc via relay wss://relay.example',
    PaymentPathDebug::lightningLabel([
        'payment_rail' => 'noffer',
        'ln_destination' => 'noffer1abc',
        'noffer_relay' => 'wss://relay.example',
    ]),
    'noffer rail with relay'
);

assert_eq(
    'CLINK noffer (NIP-69) → noffer1abc',
    PaymentPathDebug::lightningLabel([
        'payment_rail' => 'noffer',
        'ln_destination' => 'noffer1abc',
        'noffer_relay' => '',
    ]),
    'noffer rail without relay'
);

assert_eq(
    'Submarine swap via Boltz',
    PaymentPathDebug::lightningLabel(['payment_rail' => 'swap'], 'Boltz'),
    'swap rail with provider'
);

assert_eq(
    'Submarine swap',
    PaymentPathDebug::lightningLabel(['payment_rail' => 'swap'], null),
    'swap rail without provider'
);

assert_eq(
    'Cashu mint quote (Lightning) → https://mint.example',
    PaymentPathDebug::lightningLabel([
        'payment_rail' => 'mint',
        'mint_url' => 'https://mint.example',
    ]),
    'mint rail'
);

assert_eq(
    'Lightning (weird)',
    PaymentPathDebug::lightningLabel(['payment_rail' => 'weird']),
    'unknown rail falls back to a generic label'
);

assert_eq(
    'Lightning',
    PaymentPathDebug::lightningLabel([]),
    'missing rail falls back to plain Lightning'
);

// ---- On-chain labels: xpub vs static vs fee-redirect ----------------------

assert_eq(
    'On-chain (xpub-derived) → bc1qxpub',
    PaymentPathDebug::onchainLabel(['onchain_address' => 'bc1qxpub'], 'xpub'),
    'xpub mode'
);

assert_eq(
    'On-chain (static address) → bc1qstatic',
    PaymentPathDebug::onchainLabel(['onchain_address' => 'bc1qstatic'], 'static'),
    'static mode'
);

assert_eq(
    'On-chain (xpub-derived) → bc1qdefault',
    PaymentPathDebug::onchainLabel(['onchain_address' => 'bc1qdefault'], null),
    'null mode defaults to xpub'
);

assert_eq(
    'Fee-redirect on-chain (xpub-derived) → bc1qfee',
    PaymentPathDebug::onchainLabel([
        'onchain_address' => 'bc1qfee',
        'fee_redirect_rails' => 'lightning,onchain',
    ], 'static'),
    'fee-redirect on-chain overrides store mode'
);

// ---- Cashu label ----------------------------------------------------------

assert_eq(
    'Cashu ecash (NUT-18) → https://mint.example',
    PaymentPathDebug::cashuLabel('https://mint.example'),
    'cashu with mint url'
);

assert_eq(
    'Cashu ecash (NUT-18)',
    PaymentPathDebug::cashuLabel(null),
    'cashu without mint url'
);

fwrite(STDERR, "test_payment_path_debug ok\n");
exit(0);
