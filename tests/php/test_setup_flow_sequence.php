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

$noOnchain = SetupFlow::stepSequence('', false);
assert_eq(
    ['terms', 'security', 'password', 'store', 'onchain', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $noOnchain,
    'standalone without an on-chain rail drops zeroconf'
);

$withOnchain = SetupFlow::stepSequence('', true);
assert_eq(
    ['terms', 'security', 'password', 'store', 'onchain', 'lightning', 'zeroconf', 'swaps', 'mints', 'cron', 'done'],
    $withOnchain,
    'standalone with an on-chain rail includes zeroconf'
);

// The terms-of-service gate opens every first run and is never skipped.
assert_eq('terms', $withOnchain[0], 'a first run opens on the terms-of-service gate');

// --- Security screen skip (data dir outside the web root) ------------------
//
// The screen proves the database can't be fetched over HTTP; with the data
// directory outside the web root that exposure is impossible, so the screen
// is dropped ($includeSecurity = false) and the counter stays honest. The
// caller keeps it in the flow whenever a PHP requirement is missing, because
// the screen is also where that blocking error renders.

$noSecurity = SetupFlow::stepSequence('', false, false);
assert_eq(
    ['terms', 'password', 'store', 'onchain', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $noSecurity,
    'standalone with the data dir outside the web root drops the security screen'
);
assert_eq('password', SetupFlow::nextStep('terms', $noSecurity), 'terms then goes straight to the password screen');

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
//
// A provisioned install (the GPL WordPress companion plugin's alongside
// install is the canonical case) declared CASHUPAY_EXTERNAL_CRON at deploy
// time: something else already ticks cron.php, so the crontab screen has
// nothing to teach — and only that screen may disappear.

$externalCron = SetupFlow::stepSequence('', true, true, false, true);
assert_eq(
    ['terms', 'security', 'password', 'store', 'onchain', 'lightning', 'zeroconf', 'swaps', 'mints', 'done'],
    $externalCron,
    'external cron drops the cron screen and nothing else'
);
assert_eq(
    array_values(array_diff($withOnchain, ['cron'])),
    $externalCron,
    'the external-cron sequence is exactly the standalone one minus cron'
);
assert_eq('done', SetupFlow::nextStep('mints', $externalCron), 'with external cron, done follows mints directly');

// add_store never had the cron screen, so the flag must change nothing there.
assert_eq(
    SetupFlow::stepSequence('add_store', true),
    SetupFlow::stepSequence('add_store', true, true, false, true),
    'add_store is unaffected by the external-cron flag'
);

// --- externalCronConfigured(): the deploy-time declaration -----------------
//
// Constant wins, env accepted, "0"/empty read as off — the same convention
// as Desktop::isWindowsDesktop(). This rig defines no constant, so the env
// var alone drives the answer here.
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
//
// A managed install provisioned the admin account up front
// (CASHUPAY_ADMIN_PASSWORD_HASH, seeded by ManagedInstall::seedAdminIfProvisioned),
// so the wizard has no credential left to collect — and only that screen may
// disappear.

$preseeded = SetupFlow::stepSequence('', true, true, false, false, true);
assert_eq(
    ['terms', 'security', 'store', 'onchain', 'lightning', 'zeroconf', 'swaps', 'mints', 'cron', 'done'],
    $preseeded,
    'a pre-seeded password drops the password screen and nothing else'
);
assert_eq(
    array_values(array_diff($withOnchain, ['password'])),
    $preseeded,
    'the pre-seeded sequence is exactly the standalone one minus password'
);
assert_eq('store', SetupFlow::nextStep('security', $preseeded), 'with the password pre-seeded, store follows the safety check');

// An explicit false keeps the screen — identical to omitting the flag.
assert_eq(
    $withOnchain,
    SetupFlow::stepSequence('', true, true, false, false, false),
    'passwordPreseeded=false keeps the password screen'
);

// add_store never had the password screen, so the flag must change nothing.
assert_eq(
    SetupFlow::stepSequence('add_store', true),
    SetupFlow::stepSequence('add_store', true, true, false, false, true),
    'add_store is unaffected by the password-preseeded flag'
);

// The canonical managed install sets both: the orchestrator ticks cron AND
// seeded the admin — both screens go, independently.
$managed = SetupFlow::stepSequence('', true, true, false, true, true);
assert_eq(
    ['terms', 'security', 'store', 'onchain', 'lightning', 'zeroconf', 'swaps', 'mints', 'done'],
    $managed,
    'external cron + pre-seeded password drop both cron and password'
);
assert_eq(
    array_values(array_diff($withOnchain, ['cron', 'password'])),
    $managed,
    'the managed sequence is exactly the standalone one minus cron and password'
);

// --- add_store mode runs only the store-scoped screens --------------------

$addStore = SetupFlow::stepSequence('add_store', true);
assert_eq(
    ['store', 'onchain', 'lightning', 'zeroconf', 'swaps', 'mints'],
    $addStore,
    'add_store skips security/password up front and cron/done at the end'
);
assert_eq(
    ['store', 'onchain', 'lightning', 'swaps', 'mints'],
    SetupFlow::stepSequence('add_store', false),
    'add_store drops zeroconf with no on-chain rail too'
);
assert_eq('store', SetupFlow::firstStep('add_store'), 'add_store opens on the store screen');
assert_eq('terms', SetupFlow::firstStep(''), 'a first run opens on the terms gate');
// add_store belongs to an already-configured instance, so it never re-shows terms.
assert_false(in_array('terms', $addStore, true), 'add_store never re-shows the terms gate');

// --- next / prev ----------------------------------------------------------

assert_eq('security', SetupFlow::nextStep('terms', $withOnchain), 'the safety check follows the terms gate');
// zeroconf sits after lightning: the Strike on-chain option — the last
// possible on-chain receive source — is only answered on the lightning
// screen, so the confirmation-policy question has to come after it.
assert_eq('lightning', SetupFlow::nextStep('onchain', $withOnchain), 'lightning follows onchain');
assert_eq('zeroconf', SetupFlow::nextStep('lightning', $withOnchain), 'zeroconf follows lightning');
assert_eq('swaps', SetupFlow::nextStep('lightning', $noOnchain), 'without zeroconf, swaps follow lightning');
assert_eq('cron', SetupFlow::nextStep('mints', $withOnchain), 'standalone goes mints straight to cron');
assert_null(SetupFlow::nextStep('done', $withOnchain), 'done is terminal');
assert_null(SetupFlow::nextStep('mints', $addStore), 'mints is terminal in add_store mode');
assert_null(SetupFlow::nextStep('cron', $addStore), 'a screen outside the sequence has no next');

assert_eq('store', SetupFlow::prevStep('onchain', $withOnchain), 'store precedes onchain');
assert_eq('terms', SetupFlow::prevStep('security', $withOnchain), 'the terms gate precedes the safety check');
assert_null(SetupFlow::prevStep('terms', $withOnchain), 'the first screen has no previous');
assert_null(SetupFlow::prevStep('zeroconf', $noOnchain), 'a screen outside the sequence has no previous');

// --- Back targets ---------------------------------------------------------
//
// The store exists by the time any later screen renders, so Back must never
// land on the password screen — re-submitting it trips the "an admin already
// exists" guard and shows a 403 mid-wizard.
assert_null(SetupFlow::backStep('store', $withOnchain), 'Back from store must not reach the password screen');
assert_null(SetupFlow::backStep('terms', $withOnchain), 'the terms gate is first and has no Back');
assert_null(SetupFlow::backStep('security', $withOnchain), 'the safety check has no Back (it must not return to terms)');
assert_null(SetupFlow::backStep('password', $withOnchain), 'the password screen has no Back');
assert_eq('store', SetupFlow::backStep('onchain', $withOnchain), 'Back from onchain returns to store');
assert_eq('lightning', SetupFlow::backStep('zeroconf', $withOnchain), 'Back from zeroconf returns to lightning');
assert_eq('lightning', SetupFlow::backStep('swaps', $noOnchain), 'Back skips the absent zeroconf screen');
// The post-completion screens are past the point of no return: setup_complete
// is already set and the store is live.
assert_eq(['cron', 'done'], SetupFlow::POST_COMPLETION, 'cron and done are the only post-completion screens');
assert_null(SetupFlow::backStep('cron', $withOnchain), 'cron is past the point of no return');
assert_null(SetupFlow::backStep('done', $withOnchain), 'done is past the point of no return');
// In add_store mode the store screen is genuinely first, so still no Back.
assert_null(SetupFlow::backStep('store', $addStore), 'store is the first add_store screen');

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
