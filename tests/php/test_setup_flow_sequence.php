<?php
/**
 * Onboarding wizard screen sequencing (SetupFlow).
 *
 * The wizard's shape is conditional in three ways and each one has bitten
 * before: add_store mode has neither the pre- nor the post-store screens, the
 * zero-conf screen only exists once an on-chain destination has been saved,
 * and an externally-driven cron (a provisioned install whose orchestrator
 * pings cron.php — see SetupFlow::externalCronConfigured) drops the crontab
 * screen. Getting any of them wrong either strands the operator on a screen
 * with no exit or advertises a step count that never materialises.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/setup_flow.php';

// --- Standalone first run -------------------------------------------------
//
// Default shape: no installation type chosen yet (renders like "something
// else"), no seed minted yet.

$full = ['terms', 'analytics', 'install_type', 'security', 'password', 'email', 'store', 'onchain', 'lightning', 'zeroconf', 'mints', 'updates', 'cron', 'done'];

$noOnchain = SetupFlow::stepSequence('', false);
assert_eq(
    array_values(array_diff($full, ['zeroconf'])),
    $noOnchain,
    'standalone without an on-chain rail drops zeroconf'
);

$withOnchain = SetupFlow::stepSequence('', true);
assert_eq($full, $withOnchain, 'standalone with an on-chain rail includes zeroconf');

// The terms-of-service gate opens every first run and is never skipped; the
// usage-statistics opt-in is screen two and the installation type screen
// three, both BEFORE the safety check and the admin password.
assert_eq('terms', $withOnchain[0], 'a first run opens on the terms-of-service gate');
assert_eq('analytics', $withOnchain[1], 'the usage-statistics opt-in is the second screen');
assert_eq('install_type', $withOnchain[2], 'the installation type is chosen on the third screen');
assert_false(in_array('swaps', $withOnchain, true), 'submarine swaps no longer have a screen (they default on when possible)');

// --- Installation types reshape the rail screens --------------------------

assert_eq(['strike', 'own_wallet', 'cashu', 'other'], SetupFlow::INSTALL_TYPES, 'the four installation types');
foreach (SetupFlow::INSTALL_TYPES as $t) {
    assert_true(SetupFlow::isInstallType($t), "$t is an installation type");
}
assert_false(SetupFlow::isInstallType(''), 'blank is not an installation type');
assert_false(SetupFlow::isInstallType('Strike'), 'types are matched case-sensitively');

// Strike: one Strike screen replaces onchain + lightning; mints (and hence
// the seed screen) are gone. zeroconf stays — Strike-minted addresses are
// timed by onchain_min_confs too.
$strike = SetupFlow::stepSequence('', true, true, false, false, false, 'strike');
assert_eq(
    ['terms', 'analytics', 'install_type', 'security', 'password', 'email', 'store', 'strike', 'zeroconf', 'updates', 'cron', 'done'],
    $strike,
    'the Strike type swaps onchain+lightning for the strike screen and drops mints'
);
assert_eq(
    ['terms', 'analytics', 'install_type', 'security', 'password', 'email', 'store', 'strike', 'updates', 'cron', 'done'],
    SetupFlow::stepSequence('', false, true, false, false, false, 'strike'),
    'a Strike store without on-chain receiving has no zeroconf screen either'
);
assert_eq(
    $strike,
    SetupFlow::stepSequence('', true, true, false, false, false, 'strike', true),
    'the seed screen never appears on the Strike path even if asked for'
);

// Every other type keeps the default shape: the Strike API option stays a
// section of the Lightning screen.
foreach (['own_wallet', 'cashu', 'other'] as $t) {
    assert_eq($full, SetupFlow::stepSequence('', true, true, false, false, false, $t), "$t keeps the full default shape");
}
assert_eq($full, SetupFlow::stepSequence('', true, true, false, false, false, null), 'no choice yet renders the default shape');
assert_false(in_array('strike', $full, true), 'the strike screen is absent from the default shape');

// --- The seed screen appears once a wallet seed exists --------------------

$withSeed = SetupFlow::stepSequence('', true, true, false, false, false, 'cashu', true);
assert_eq(
    ['terms', 'analytics', 'install_type', 'security', 'password', 'email', 'store', 'onchain', 'lightning', 'zeroconf', 'mints', 'seed', 'updates', 'cron', 'done'],
    $withSeed,
    'a minted seed adds the recovery-phrase screen right after mints'
);
assert_eq('seed', SetupFlow::nextStep('mints', $withSeed), 'mints hands over to the seed screen');
assert_eq('updates', SetupFlow::nextStep('seed', $withSeed), 'the auto-update question follows the seed screen');
assert_eq('mints', SetupFlow::backStep('seed', $withSeed), 'Back from the seed screen returns to mints');
assert_false(in_array('seed', SetupFlow::stepSequence('add_store', true, true, false, false, false, null, true), true), 'add_store has its own hand-off panel, never the seed screen');

// --- Leaving the rail group is the finalisation trigger -------------------

assert_eq(['onchain', 'lightning', 'strike', 'zeroconf', 'mints'], SetupFlow::RAIL_STEPS, 'the rail screens');
assert_true(SetupFlow::leavesRailGroup('mints', 'updates'), 'mints -> updates leaves the rail group');
assert_true(SetupFlow::leavesRailGroup('mints', 'seed'), 'mints -> seed leaves the rail group');
assert_true(SetupFlow::leavesRailGroup('zeroconf', 'updates'), 'zeroconf -> updates (Strike path) leaves the rail group');
assert_true(SetupFlow::leavesRailGroup('strike', 'updates'), 'strike -> updates (no on-chain) leaves the rail group');
assert_true(SetupFlow::leavesRailGroup('mints', null), 'the last screen of a sequence counts as leaving');
assert_false(SetupFlow::leavesRailGroup('onchain', 'lightning'), 'onchain -> lightning stays inside');
assert_false(SetupFlow::leavesRailGroup('lightning', 'zeroconf'), 'lightning -> zeroconf stays inside');
assert_false(SetupFlow::leavesRailGroup('strike', 'zeroconf'), 'strike -> zeroconf stays inside');
assert_false(SetupFlow::leavesRailGroup('store', 'onchain'), 'entering the group is not leaving it');
assert_false(SetupFlow::leavesRailGroup('seed', 'updates'), 'a post-rail screen never triggers finalisation');

// --- Security screen skip (data dir outside the web root) ------------------
//
// The screen proves the database can't be fetched over HTTP; with the data
// directory outside the web root that exposure is impossible, so the screen
// is dropped ($includeSecurity = false) and the counter stays honest. The
// caller keeps it in the flow whenever a PHP requirement is missing, because
// the screen is also where that blocking error renders.

$noSecurity = SetupFlow::stepSequence('', false, false);
assert_eq(
    array_values(array_diff($full, ['zeroconf', 'security'])),
    $noSecurity,
    'standalone with the data dir outside the web root drops the security screen'
);
assert_eq('password', SetupFlow::nextStep('install_type', $noSecurity), 'the installation type then goes straight to the password screen');

// Omitting the flag keeps the screen — every historical call site behaves
// as before.
assert_true(
    in_array('security', SetupFlow::stepSequence('', false), true),
    'the security screen stays by default'
);

// add_store never had the security screen; the flag must not disturb it.
assert_eq(
    SetupFlow::stepSequence('add_store', true),
    SetupFlow::stepSequence('add_store', true, false),
    'add_store is unaffected by the security flag'
);

// The bundled test PHP satisfies every requirement, so the skip decision is
// purely the webroot test on this rig.
assert_eq([], SetupFlow::missingRequirements(), 'the bundled test PHP passes all requirement checks');

// --- External cron drops exactly the cron screen ---------------------------

$externalCron = SetupFlow::stepSequence('', true, true, false, true);
assert_eq(
    array_values(array_diff($full, ['cron'])),
    $externalCron,
    'external cron drops the cron screen and nothing else'
);
assert_eq('updates', SetupFlow::nextStep('mints', $externalCron), 'with external cron, updates still follows mints');
assert_eq('done', SetupFlow::nextStep('updates', $externalCron), 'with external cron, done follows updates directly');

// add_store never had the cron screen, so the flag must change nothing there.
assert_eq(
    SetupFlow::stepSequence('add_store', true),
    SetupFlow::stepSequence('add_store', true, true, false, true),
    'add_store is unaffected by the external-cron flag'
);

// --- externalCronConfigured(): the deploy-time declaration -----------------
assert_false(SetupFlow::externalCronConfigured(), 'no declaration means no external cron');
putenv('CASHUPAY_EXTERNAL_CRON=1');
assert_true(SetupFlow::externalCronConfigured(), 'CASHUPAY_EXTERNAL_CRON=1 declares an external cron');
putenv('CASHUPAY_EXTERNAL_CRON=0');
assert_false(SetupFlow::externalCronConfigured(), 'CASHUPAY_EXTERNAL_CRON=0 reads as off');
putenv('CASHUPAY_EXTERNAL_CRON=');
assert_false(SetupFlow::externalCronConfigured(), 'an empty CASHUPAY_EXTERNAL_CRON reads as off');
putenv('CASHUPAY_EXTERNAL_CRON');
assert_false(SetupFlow::externalCronConfigured(), 'cleanup: unset reads as off again');

// --- Pre-seeded password drops exactly the password screen -----------------

$preseeded = SetupFlow::stepSequence('', true, true, false, false, true);
assert_eq(
    array_values(array_diff($full, ['password'])),
    $preseeded,
    'a pre-seeded password drops the password screen and nothing else'
);
assert_eq('email', SetupFlow::nextStep('security', $preseeded), 'with the password pre-seeded, the e-mail screen follows the safety check');
assert_eq(
    $withOnchain,
    SetupFlow::stepSequence('', true, true, false, false, false),
    'passwordPreseeded=false keeps the password screen'
);
assert_eq(
    SetupFlow::stepSequence('add_store', true),
    SetupFlow::stepSequence('add_store', true, true, false, false, true),
    'add_store is unaffected by the password-preseeded flag'
);

$managed = SetupFlow::stepSequence('', true, true, false, true, true);
assert_eq(
    array_values(array_diff($full, ['cron', 'password'])),
    $managed,
    'external cron + pre-seeded password drop both cron and password'
);

// --- add_store mode runs only the store-scoped screens --------------------

$addStore = SetupFlow::stepSequence('add_store', true);
assert_eq(
    ['store', 'onchain', 'lightning', 'zeroconf', 'mints'],
    $addStore,
    'add_store skips the pre-store screens up front and the tail screens at the end'
);
assert_eq(
    ['store', 'onchain', 'lightning', 'mints'],
    SetupFlow::stepSequence('add_store', false),
    'add_store drops zeroconf with no on-chain rail too'
);
assert_eq(
    $addStore,
    SetupFlow::stepSequence('add_store', true, true, false, false, false, 'strike'),
    'add_store never asks the installation type, so the Strike shape does not apply'
);
assert_eq('store', SetupFlow::firstStep('add_store'), 'add_store opens on the store screen');
assert_eq('terms', SetupFlow::firstStep(''), 'a first run opens on the terms gate');
assert_false(in_array('terms', $addStore, true), 'add_store never re-shows the terms gate');

// --- next / prev ----------------------------------------------------------

assert_eq('analytics', SetupFlow::nextStep('terms', $withOnchain), 'the usage-statistics question follows the terms gate');
assert_eq('install_type', SetupFlow::nextStep('analytics', $withOnchain), 'the installation type follows the usage-statistics question');
assert_eq('security', SetupFlow::nextStep('install_type', $withOnchain), 'the safety check follows the installation type');
assert_eq('email', SetupFlow::nextStep('password', $withOnchain), 'the e-mail screen follows the password');
assert_eq('store', SetupFlow::nextStep('email', $withOnchain), 'store follows the e-mail screen');
assert_eq('lightning', SetupFlow::nextStep('onchain', $withOnchain), 'lightning follows onchain');
assert_eq('zeroconf', SetupFlow::nextStep('lightning', $withOnchain), 'zeroconf follows lightning');
assert_eq('mints', SetupFlow::nextStep('lightning', $noOnchain), 'without zeroconf, mints follow lightning');
assert_eq('zeroconf', SetupFlow::nextStep('strike', $strike), 'zeroconf follows the strike screen');
assert_eq('updates', SetupFlow::nextStep('zeroconf', $strike), 'on the Strike path the auto-update question follows zeroconf');
assert_eq('updates', SetupFlow::nextStep('mints', $withOnchain), 'the auto-update question follows mints');
assert_eq('cron', SetupFlow::nextStep('updates', $withOnchain), 'cron follows the auto-update question');
assert_null(SetupFlow::nextStep('done', $withOnchain), 'done is terminal');
assert_null(SetupFlow::nextStep('mints', $addStore), 'mints is terminal in add_store mode');
assert_null(SetupFlow::nextStep('cron', $addStore), 'a screen outside the sequence has no next');

assert_eq('store', SetupFlow::prevStep('onchain', $withOnchain), 'store precedes onchain');
assert_eq('install_type', SetupFlow::prevStep('security', $withOnchain), 'the installation type precedes the safety check');
assert_null(SetupFlow::prevStep('terms', $withOnchain), 'the first screen has no previous');
assert_null(SetupFlow::prevStep('zeroconf', $noOnchain), 'a screen outside the sequence has no previous');

// --- Back targets ---------------------------------------------------------
//
// Every screen except the opening terms gate, the completion screen and the
// add_store hand-off panel has a Back. The password screen must never be a
// TARGET (re-submitting it trips the "an admin already exists" guard) and
// neither must the safety check; Back steps over them to the nearest
// earlier screen.
assert_eq(['security', 'password'], SetupFlow::NO_BACK_TARGET, 'the two screens Back must step over');
assert_null(SetupFlow::backStep('terms', $withOnchain), 'the terms gate is first and has no Back');
assert_eq('terms', SetupFlow::backStep('analytics', $withOnchain), 'Back from the usage-statistics question returns to terms');
assert_eq('analytics', SetupFlow::backStep('install_type', $withOnchain), 'Back from the installation type returns to the usage-statistics question');
assert_eq('install_type', SetupFlow::backStep('security', $withOnchain), 'Back from the safety check returns to the installation type');
assert_eq('install_type', SetupFlow::backStep('password', $withOnchain), 'Back from the password screen steps over the safety check');
assert_eq('install_type', SetupFlow::backStep('email', $withOnchain), 'Back from the e-mail screen steps over password and safety check');
assert_eq('email', SetupFlow::backStep('store', $withOnchain), 'Back from store returns to the e-mail screen');
assert_eq('install_type', SetupFlow::backStep('email', $preseeded), 'with no password screen, Back from the e-mail screen still steps over the safety check');
assert_eq('store', SetupFlow::backStep('onchain', $withOnchain), 'Back from onchain returns to store');
assert_eq('store', SetupFlow::backStep('strike', $strike), 'Back from the strike screen returns to store');
assert_eq('lightning', SetupFlow::backStep('zeroconf', $withOnchain), 'Back from zeroconf returns to lightning');
assert_eq('strike', SetupFlow::backStep('zeroconf', $strike), 'Back from zeroconf on the Strike path returns to the strike screen');
assert_eq('lightning', SetupFlow::backStep('mints', $noOnchain), 'Back skips the absent zeroconf screen');
// The post-completion screens have Back too (the completing session is let
// through setup.php's guard for them); only the completion screen itself
// is a dead end.
assert_eq(['seed', 'updates', 'cron', 'done'], SetupFlow::POST_COMPLETION, 'seed, updates, cron and done are the post-completion screens');
assert_eq('mints', SetupFlow::backStep('updates', $withOnchain), 'Back from the auto-update screen returns to mints');
assert_eq('seed', SetupFlow::backStep('updates', $withSeed), 'Back from the auto-update screen returns to the seed screen when there is one');
assert_eq('zeroconf', SetupFlow::backStep('updates', $strike), 'Back from the auto-update screen on the Strike path returns to zeroconf');
assert_eq('updates', SetupFlow::backStep('cron', $withOnchain), 'the cron screen has a Back');
assert_null(SetupFlow::backStep('done', $withOnchain), 'done is terminal and has no Back');
// In add_store mode the store screen is genuinely first (it has a Cancel
// link instead), so still no Back.
assert_null(SetupFlow::backStep('store', $addStore), 'store is the first add_store screen');
assert_eq('store', SetupFlow::backStep('onchain', $addStore), 'add_store Back from onchain returns to store');

// --- Step validation ------------------------------------------------------

assert_true(SetupFlow::isKnownStep('terms'), 'terms is a real screen');
assert_true(SetupFlow::isKnownStep('mints'), 'mints is a real screen');
assert_false(SetupFlow::isKnownStep(''), 'an empty step is not a screen');
assert_false(SetupFlow::isKnownStep('4'), 'the pre-slug numeric steps are gone');
assert_false(SetupFlow::isKnownStep('MINTS'), 'slugs are matched case-sensitively');
// The Bitcoin-discount screen moved into the GPL WordPress plugin's own
// onboarding; the wizard no longer knows it at all.
assert_false(SetupFlow::isKnownStep('discount'), 'the discount screen left with the WordPress embed');

// --- The add_store hand-off panel ----------------------------------------
//
// It exists to show a silently generated wallet seed exactly once before
// returning to admin. It is renderable but deliberately outside the counted
// sequence, so it must never be routed to as a next/back target.
assert_true(
    SetupFlow::isKnownStep(SetupFlow::ADD_STORE_COMPLETE),
    'the hand-off panel is renderable'
);
assert_false(
    in_array(SetupFlow::ADD_STORE_COMPLETE, SetupFlow::ADD_STORE_STEPS, true),
    'it must not be counted as one of the add_store questions'
);
assert_false(
    in_array(SetupFlow::ADD_STORE_COMPLETE, $addStore, true),
    'and it must not appear in a generated sequence'
);
assert_null(
    SetupFlow::backStep(SetupFlow::ADD_STORE_COMPLETE, $addStore),
    'the hand-off panel has no Back — the store is already created'
);
assert_null(
    SetupFlow::nextStep(SetupFlow::ADD_STORE_COMPLETE, $addStore),
    'and nothing follows it in the sequence'
);

// --- On-chain state detection ---------------------------------------------

assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState(null),
    'no store id means nothing is configured'
);
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_missing'),
    'an unknown store id means nothing is configured'
);

make_store('store_bare');
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_bare'),
    'a fresh store has no on-chain destination'
);

make_store('store_xpub');
Database::update('stores', [
    'onchain_address_mode' => 'xpub',
    'onchain_xpub' => 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic',
], 'id = ?', ['store_xpub']);
assert_eq(
    ['configured' => true, 'hasXpub' => true],
    SetupFlow::onchainState('store_xpub'),
    'an xpub store is configured and swap-capable'
);

// A static address is a valid receive destination but cannot back submarine
// swaps — those derive a fresh address per swap.
make_store('store_static');
Database::update('stores', [
    'onchain_address_mode' => 'static',
    'onchain_static_address' => 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
], 'id = ?', ['store_static']);
assert_eq(
    ['configured' => true, 'hasXpub' => false],
    SetupFlow::onchainState('store_static'),
    'a static address is configured but not swap-capable'
);

// Switching a store that once had an xpub over to static mode must report the
// active mode, not the leftover column.
Database::update('stores', [
    'onchain_xpub' => 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic',
], 'id = ?', ['store_static']);
assert_eq(
    ['configured' => true, 'hasXpub' => false],
    SetupFlow::onchainState('store_static'),
    'a stale xpub column must not resurrect swap capability in static mode'
);

// ...and the mirror image: static mode selected but no address saved yet.
make_store('store_static_empty');
Database::update('stores', [
    'onchain_address_mode' => 'static',
    'onchain_xpub' => 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic',
], 'id = ?', ['store_static_empty']);
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_static_empty'),
    'static mode with no address saved is not configured'
);

// --- zeroConfApplicable: any on-chain receive source pulls zeroconf in ------
//
// Strike-minted addresses settle through the same chain watcher against the
// same onchain_min_confs, so a Strike-only store (no xpub, no static address)
// must still be asked the zero-conf question.

assert_false(SetupFlow::zeroConfApplicable(null), 'no store id means no zeroconf screen');
assert_false(SetupFlow::zeroConfApplicable('store_missing'), 'an unknown store id means no zeroconf screen');
assert_false(SetupFlow::zeroConfApplicable('store_bare'), 'a fresh store has nothing for zero-conf to time');
assert_true(SetupFlow::zeroConfApplicable('store_xpub'), 'an xpub destination makes zero-conf applicable');
assert_true(SetupFlow::zeroConfApplicable('store_static'), 'a static destination makes zero-conf applicable');

make_store('store_strike_only');
Database::query(
    "UPDATE stores SET onchain_strike_enabled = 1 WHERE id = ?",
    ['store_strike_only']
);
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_strike_only'),
    'Strike on-chain is not an address destination (swaps still need an xpub)'
);
assert_true(
    SetupFlow::zeroConfApplicable('store_strike_only'),
    'Strike on-chain minting alone makes zero-conf applicable'
);

echo "ok\n";
