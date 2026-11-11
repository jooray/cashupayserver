<?php
/**
 * URL routing modes for standalone deployments.
 *
 * Covers the three url_mode values and the shapes each Urls:: helper emits:
 *
 *   - 'clean'  : extension-less pretty URLs (/admin, /setup, /pay/x, /payment/x)
 *                served through the router.php front controller.
 *   - 'direct' : hit the .php files directly; the API lives at the bare base.
 *   - 'router' : /router.php/... front-controller prefix, no rewrites needed.
 *
 * This is the regression guard for the "Clean URL mode" feature — in
 * particular that Urls::admin() finally honours url_mode (it previously always
 * returned the literal 'admin.php') and that clean mode never leaks a '.php'
 * segment into a user-facing page URL.
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

$BASE = 'https://pay.example.com';
Config::set('base_url', $BASE);

// -----------------------------------------------------------------------------
// clean mode — pretty, extension-less, absolute URLs
// -----------------------------------------------------------------------------
Config::set('url_mode', 'clean');

assert_eq('clean', Config::getUrlMode(), 'mode is clean');
assert_eq($BASE,                 Urls::server(),            'clean: server = bare base');
assert_eq($BASE,                 Urls::api(),               'clean: api = bare base');
assert_eq($BASE . '/admin',      Urls::admin(),             'clean: admin');
assert_eq($BASE . '/setup',      Urls::setup(),             'clean: setup');
assert_eq($BASE . '/pay/store1', Urls::selfServe('store1'), 'clean: self-serve');
assert_eq($BASE . '/payment',    Urls::payment(),           'clean: payment (no id)');
assert_eq($BASE . '/payment/inv_9', Urls::payment('inv_9'), 'clean: payment (with id)');
assert_eq('Clean URLs',          Urls::urlModeLabel(),      'clean: label');

// Static image URLs must be base-rooted absolute, or the payment-method wallet
// logos 404 when the payment page is served at the /payment/{id} sub-path.
assert_eq($BASE . '/images/payment-methods/strike.png',
    Urls::images('payment-methods/strike.png'), 'clean: image is base-rooted');

// The whole point of clean mode: no '.php' and no '/router.php/' in the
// user-facing page URLs.
foreach ([Urls::admin(), Urls::setup(), Urls::selfServe('s'), Urls::payment('i')] as $u) {
    assert_true(strpos($u, '.php') === false, "clean url must not contain .php: {$u}");
    assert_true(strpos($u, '/router.php') === false, "clean url must not contain router.php: {$u}");
}

// -----------------------------------------------------------------------------
// direct mode — .php files, API at the bare base
// -----------------------------------------------------------------------------
Config::set('url_mode', 'direct');

assert_eq($BASE,                        Urls::server(),            'direct: server = bare base');
assert_eq('admin.php',                  Urls::admin(),             'direct: admin (relative file)');
assert_eq($BASE . '/setup.php',         Urls::setup(),             'direct: setup');
assert_eq($BASE . '/pay.php?store=store1', Urls::selfServe('store1'), 'direct: self-serve');
assert_eq($BASE . '/payment.php?id=inv_9', Urls::payment('inv_9'), 'direct: payment');
assert_eq('Direct URLs',                Urls::urlModeLabel(),      'direct: label');

// -----------------------------------------------------------------------------
// router mode — front-controller prefix, universal fallback
// -----------------------------------------------------------------------------
Config::set('url_mode', 'router');

assert_eq($BASE . '/router.php',            Urls::server(),            'router: server has front-controller prefix');
assert_eq('admin.php',                      Urls::admin(),             'router: admin (relative file)');
assert_eq($BASE . '/router.php/setup.php',  Urls::setup(),             'router: setup');
assert_eq($BASE . '/router.php/pay/store1', Urls::selfServe('store1'), 'router: self-serve');
assert_eq('Router.php URLs',                Urls::urlModeLabel(),      'router: label');

// -----------------------------------------------------------------------------
// Explicit-arg label lookups (used by the setup summary, independent of the
// currently-stored mode).
// -----------------------------------------------------------------------------
assert_eq('Clean URLs',      Urls::urlModeLabel('clean'),  'label(clean)');
assert_eq('Direct URLs',     Urls::urlModeLabel('direct'), 'label(direct)');
assert_eq('Router.php URLs', Urls::urlModeLabel('router'), 'label(router)');
assert_eq('Router.php URLs', Urls::urlModeLabel('bogus'),  'label(unknown) falls back to router');

echo "ok\n";
