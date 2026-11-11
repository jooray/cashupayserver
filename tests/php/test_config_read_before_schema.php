<?php
/**
 * Reading config before the schema exists must NOT throw.
 *
 * On a fresh standalone install, index.php's pre-setup redirect calls
 * Urls::setup(), which in standalone mode reads DB-backed config
 * (base_url / url_mode) via Config::get(). Before setup.php has run
 * Database::initialize(), the `config` table does not exist yet, and the
 * bare "SELECT value FROM config" raised an uncaught PDOException
 * ("no such table: config") — surfacing as an HTTP 500 on every visit to
 * the directory root until setup.php had been opened once. WordPress mode
 * was immune only because its Urls::setup() never touches the DB.
 *
 * Config::get()/getAll() must treat a not-yet-initialized database as
 * "unconfigured" and fall back to the default, while still surfacing real
 * query errors once the schema exists.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Deliberately do NOT use fresh_db(): it initializes the schema. We need the
// pre-initialize() state, so set up an empty data dir by hand.
$dir = sys_get_temp_dir() . '/cashupay_test_' . bin2hex(random_bytes(6));
mkdir($dir, 0750, true);
define('CASHUPAY_DATA_DIR', $dir);
register_shutdown_function(function () use ($dir) {
    @cleanup_db($dir);
});

require_once dirname(__DIR__, 2) . '/app/includes/config.php'; // pulls in database.php
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

// Precondition: schema absent.
assert_false(Database::isInitialized(), 'db is not initialized on a fresh install');

// Config::get must return the default without throwing. This is the exact call
// Urls::setup() makes while building index.php's pre-setup redirect target.
$threw = false;
$mode = null;
try {
    $mode = Config::get('url_mode', 'router');
} catch (Throwable $e) {
    $threw = true;
}
assert_false($threw, 'Config::get does not throw before the schema exists');
assert_eq('router', $mode, 'Config::get returns the default before the schema exists');

// getAll degrades to an empty set, also without throwing.
$threw = false;
$all = null;
try {
    $all = Config::getAll();
} catch (Throwable $e) {
    $threw = true;
}
assert_false($threw, 'Config::getAll does not throw before the schema exists');
assert_eq([], $all, 'Config::getAll returns [] before the schema exists');

// End-to-end: building the setup redirect URL (the thing index.php does) must
// not 500 before setup has run. Provide the minimal $_SERVER getBaseUrl() reads.
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['SCRIPT_NAME'] = '/shop/index.php';
$threw = false;
try {
    Urls::setup();
} catch (Throwable $e) {
    $threw = true;
}
assert_false($threw, 'Urls::setup() does not throw before the schema exists');

// After the schema is created, reads work normally and the not-yet-initialized
// rescue no longer applies (defaults still returned for unset keys; set values
// read back).
Database::initialize();
assert_true(Database::isInitialized(), 'db is initialized after Database::initialize()');
assert_eq('router', Config::get('url_mode', 'router'), 'default returned for an unset key post-init');
Config::set('url_mode', 'direct');
assert_eq('direct', Config::get('url_mode'), 'a set value reads back post-init');

echo "PASS test_config_read_before_schema\n";
exit(0);
