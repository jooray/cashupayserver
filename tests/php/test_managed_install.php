<?php
/**
 * ManagedInstall — deployment-shaped provisioning values.
 *
 * A managed install declares itself through user_config.php constants; the
 * env vars of the same names are the documented test hook (constants are
 * process-sticky, so this file exercises the env side only — the
 * constant-wins precedence is by construction identical to Desktop's and the
 * sso/provision endpoint tests pin the constant path in subprocesses).
 *
 * Every accessor is a validator, not a passthrough: shopUrl and
 * retryUrlTemplate end up in Location headers / hrefs so anything that is
 * not http(s) must collapse to '', adminPasswordHash must look like a
 * password_hash() string (never a stray plaintext), and ssoKeyHash must be
 * exactly 64 hex. Also covers seedAdminIfProvisioned's idempotence and its
 * never-touch-a-merchant-account guard, and the managed default flip of
 * Config::isPayerEmailCaptureEnabled.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';
require_once dirname(__DIR__, 2) . '/app/includes/managed.php';

// Make sure nothing leaks in from the invoking shell.
foreach (['CASHUPAY_MANAGED_INSTALL', 'CASHUPAY_SHOP_URL',
          'CASHUPAY_ADMIN_PASSWORD_HASH', 'CASHUPAY_SSO_KEY_HASH',
          'CASHUPAY_RETRY_URL_TEMPLATE', 'CASHUPAY_MANAGED_RETURN_URL'] as $var) {
    putenv($var);
}

// --- isManaged(): "0"/empty/unset off, anything else on --------------------

assert_false(ManagedInstall::isManaged(), 'unset means not managed');
putenv('CASHUPAY_MANAGED_INSTALL=1');
assert_true(ManagedInstall::isManaged(), 'CASHUPAY_MANAGED_INSTALL=1 declares managed');
putenv('CASHUPAY_MANAGED_INSTALL=0');
assert_false(ManagedInstall::isManaged(), '"0" reads as off');
putenv('CASHUPAY_MANAGED_INSTALL=');
assert_false(ManagedInstall::isManaged(), 'empty reads as off');
// The convention (shared with Desktop / externalCronConfigured) is that any
// other non-empty string is on — deployment declarations, not booleans.
putenv('CASHUPAY_MANAGED_INSTALL=yes');
assert_true(ManagedInstall::isManaged(), 'any other non-empty value reads as on');
putenv('CASHUPAY_MANAGED_INSTALL');
assert_false(ManagedInstall::isManaged(), 'cleanup: unset is off again');

// --- shopUrl(): http(s) only, trailing slashes trimmed ----------------------

assert_eq('', ManagedInstall::shopUrl(), 'unset shop URL is empty');
putenv('CASHUPAY_SHOP_URL=https://shop.example');
assert_eq('https://shop.example', ManagedInstall::shopUrl(), 'a plain https URL passes through');
putenv('CASHUPAY_SHOP_URL=http://shop.example');
assert_eq('http://shop.example', ManagedInstall::shopUrl(), 'plain http is accepted too');
putenv('CASHUPAY_SHOP_URL=https://shop.example/');
assert_eq('https://shop.example', ManagedInstall::shopUrl(), 'a trailing slash is trimmed');
putenv('CASHUPAY_SHOP_URL=https://shop.example/store///');
assert_eq('https://shop.example/store', ManagedInstall::shopUrl(), 'all trailing slashes are trimmed');
putenv('CASHUPAY_SHOP_URL=  https://shop.example  ');
assert_eq('https://shop.example', ManagedInstall::shopUrl(), 'surrounding whitespace is trimmed');
putenv('CASHUPAY_SHOP_URL=HTTPS://Shop.Example');
assert_eq('HTTPS://Shop.Example', ManagedInstall::shopUrl(), 'the scheme match is case-insensitive');
// The value lands in Location headers and hrefs, so anything that is not
// http(s) — including a javascript: payload — must collapse to ''.
putenv('CASHUPAY_SHOP_URL=not a url');
assert_eq('', ManagedInstall::shopUrl(), 'garbage collapses to empty');
putenv('CASHUPAY_SHOP_URL=javascript:alert(1)');
assert_eq('', ManagedInstall::shopUrl(), 'a javascript: scheme collapses to empty');
putenv('CASHUPAY_SHOP_URL=ftp://shop.example');
assert_eq('', ManagedInstall::shopUrl(), 'non-http(s) schemes collapse to empty');
putenv('CASHUPAY_SHOP_URL');

// --- adminPasswordHash(): must look like a password_hash() string -----------

assert_eq('', ManagedInstall::adminPasswordHash(), 'unset admin hash is empty');
$realHash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=' . $realHash);
assert_eq($realHash, ManagedInstall::adminPasswordHash(), 'a real password_hash() string passes through verbatim');
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=short');
assert_eq('', ManagedInstall::adminPasswordHash(), 'a short value is rejected');
// A plaintext that happens to be long must never be accepted as a hash —
// seeding it would store the password itself in the users table.
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=' . str_repeat('hunter2', 5));
assert_eq('', ManagedInstall::adminPasswordHash(), 'a long plaintext without a $ prefix is rejected');
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=$' . str_repeat('a', 28));
assert_eq('', ManagedInstall::adminPasswordHash(), '29 chars is below the length floor even with a $');
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=$' . str_repeat('a', 29));
assert_neq('', ManagedInstall::adminPasswordHash(), '30 chars starting with $ clears the sanity check');
putenv('CASHUPAY_ADMIN_PASSWORD_HASH');

// --- ssoKeyHash(): exactly 64 hex, normalized to lowercase ------------------

assert_eq('', ManagedInstall::ssoKeyHash(), 'unset SSO key hash is empty');
$keyHash = hash('sha256', 'sso key material');
putenv('CASHUPAY_SSO_KEY_HASH=' . $keyHash);
assert_eq($keyHash, ManagedInstall::ssoKeyHash(), 'a 64-hex hash passes through');
// The code lowercases + trims BEFORE validating, so an uppercase or padded
// paste of the same hash still arms the endpoint — normalized to lowercase.
putenv('CASHUPAY_SSO_KEY_HASH=' . strtoupper($keyHash));
assert_eq($keyHash, ManagedInstall::ssoKeyHash(), 'uppercase input is normalized to lowercase');
putenv('CASHUPAY_SSO_KEY_HASH=  ' . $keyHash . '  ');
assert_eq($keyHash, ManagedInstall::ssoKeyHash(), 'surrounding whitespace is trimmed before validation');
putenv('CASHUPAY_SSO_KEY_HASH=' . substr($keyHash, 0, 63));
assert_eq('', ManagedInstall::ssoKeyHash(), '63 hex chars is rejected');
putenv('CASHUPAY_SSO_KEY_HASH=' . substr($keyHash, 0, 63) . 'g');
assert_eq('', ManagedInstall::ssoKeyHash(), 'a non-hex character is rejected');
putenv('CASHUPAY_SSO_KEY_HASH');

// --- retryUrlTemplate(): http(s) only ---------------------------------------

assert_eq('', ManagedInstall::retryUrlTemplate(), 'unset retry template is empty');
$tpl = 'https://shop.example/?cashupay_retry={invoiceId}';
putenv('CASHUPAY_RETRY_URL_TEMPLATE=' . $tpl);
assert_eq($tpl, ManagedInstall::retryUrlTemplate(), 'an https template passes through (no rtrim — the placeholder may be last)');
putenv('CASHUPAY_RETRY_URL_TEMPLATE=http://shop.example/retry/{invoiceId}');
assert_eq('http://shop.example/retry/{invoiceId}', ManagedInstall::retryUrlTemplate(), 'http is accepted');
putenv('CASHUPAY_RETRY_URL_TEMPLATE=/retry/{invoiceId}');
assert_eq('', ManagedInstall::retryUrlTemplate(), 'a relative template collapses to empty');
putenv('CASHUPAY_RETRY_URL_TEMPLATE=javascript:alert(1)');
assert_eq('', ManagedInstall::retryUrlTemplate(), 'a javascript: template collapses to empty');
putenv('CASHUPAY_RETRY_URL_TEMPLATE');

// --- returnUrl(): http(s) only, preserved verbatim ---------------------------
//
// The wizard's completion screen renders this into an href (target="_top"),
// so anything that is not http(s) must collapse to '' — and the query string
// (the orchestrator's admin-post action) must survive untrimmed.

assert_eq('', ManagedInstall::returnUrl(), 'unset return URL is empty');
$return = 'https://wp.test/wp-admin/admin-post.php?action=cashupay_provision_return';
putenv('CASHUPAY_MANAGED_RETURN_URL=' . $return);
assert_eq($return, ManagedInstall::returnUrl(), 'an https URL passes through, query string intact');
putenv('CASHUPAY_MANAGED_RETURN_URL=http://wp.test/wp-admin/admin-post.php?action=x');
assert_eq('http://wp.test/wp-admin/admin-post.php?action=x', ManagedInstall::returnUrl(), 'http is accepted');
putenv('CASHUPAY_MANAGED_RETURN_URL=  ' . $return . '  ');
assert_eq($return, ManagedInstall::returnUrl(), 'surrounding whitespace is trimmed');
putenv('CASHUPAY_MANAGED_RETURN_URL=/wp-admin/admin-post.php');
assert_eq('', ManagedInstall::returnUrl(), 'a relative URL collapses to empty');
putenv('CASHUPAY_MANAGED_RETURN_URL=javascript:alert(1)');
assert_eq('', ManagedInstall::returnUrl(), 'a javascript: URL collapses to empty');
putenv('CASHUPAY_MANAGED_RETURN_URL');

// --- seedAdminIfProvisioned() ----------------------------------------------

function user_rows(): array {
    return Database::fetchAll("SELECT username, password_hash, role FROM users ORDER BY rowid ASC");
}

// No hash provisioned: a no-op, even with an empty users table.
ManagedInstall::seedAdminIfProvisioned();
assert_eq([], user_rows(), 'no provisioned hash seeds nothing');

// A merchant-created account is never touched, even when a hash appears
// later (e.g. the orchestrator re-writes user_config.php on an update).
$plaintext = 'orchestrator-chosen-secret';
$provisionedHash = password_hash($plaintext, PASSWORD_DEFAULT);
Database::insert('users', [
    'id'            => 'user_merchant',
    'username'      => 'shopkeeper',
    'password_hash' => password_hash('merchant password', PASSWORD_DEFAULT),
    'role'          => Auth::ROLE_ADMIN,
    'created_at'    => Database::timestamp(),
]);
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=' . $provisionedHash);
ManagedInstall::seedAdminIfProvisioned();
$rows = user_rows();
assert_eq(1, count($rows), 'a non-empty users table is left alone');
assert_eq('shopkeeper', $rows[0]['username'], 'the merchant account survives');
assert_neq($provisionedHash, $rows[0]['password_hash'], 'the merchant password is not overwritten');

// Empty table + provisioned hash: seeds exactly the admin account, with
// EXACTLY the provisioned hash (no re-hash — the plaintext never lives here).
Database::delete('users', 'id = ?', ['user_merchant']);
ManagedInstall::seedAdminIfProvisioned();
$rows = user_rows();
assert_eq(1, count($rows), 'the seed creates exactly one user');
assert_eq('admin', $rows[0]['username'], 'the seeded account is named admin');
assert_eq(Auth::ROLE_ADMIN, $rows[0]['role'], 'the seeded account is an admin');
assert_eq($provisionedHash, $rows[0]['password_hash'], 'the provisioned hash is stored verbatim');
assert_true(password_verify($plaintext, $rows[0]['password_hash']), 'the orchestrator plaintext verifies against the stored hash');

// Idempotent: a second wizard bootstrap must not duplicate the account.
ManagedInstall::seedAdminIfProvisioned();
assert_eq(1, count(user_rows()), 'a second call seeds nothing more');
putenv('CASHUPAY_ADMIN_PASSWORD_HASH');

// --- adminSeededFromProvisionedHash(): the wizard's password-screen gate -----
//
// The skip needs BOTH halves: a provisioned hash AND an admin account to
// sign in with. The seeded admin from above is still in the table.

assert_false(ManagedInstall::adminSeededFromProvisionedHash(),
    'no provisioned hash means no skip, even with an admin present');
putenv('CASHUPAY_ADMIN_PASSWORD_HASH=' . $provisionedHash);
assert_true(ManagedInstall::adminSeededFromProvisionedHash(),
    'hash + the seeded admin account allows the skip');

// A merchant-created admin with a DIFFERENT password also allows the skip:
// the merchant knows their own credentials, and the wizard's password screen
// would refuse to run over an existing admin anyway.
Database::update('users',
    ['password_hash' => password_hash('merchant password', PASSWORD_DEFAULT)],
    "username = 'admin'", []);
assert_true(ManagedInstall::adminSeededFromProvisionedHash(),
    'a pre-existing merchant admin still allows the skip');

// The case the gate exists for: a hash provisioned over a users table with
// NO admin row (only lesser roles survived, so the seed no-oped). Skipping
// would complete a wizard nobody can ever log into — the password screen
// must stay.
Database::query("DELETE FROM users");
Database::insert('users', [
    'id'            => 'user_lesser_only',
    'username'      => 'clerk',
    'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'          => Auth::ROLE_USER,
    'created_at'    => Database::timestamp(),
]);
assert_false(ManagedInstall::adminSeededFromProvisionedHash(),
    'a users table with no admin row must keep the password screen');
Database::query("DELETE FROM users");
putenv('CASHUPAY_ADMIN_PASSWORD_HASH');

// --- Config::isPayerEmailCaptureEnabled(): the managed default flip ---------
//
// Explicit setting wins; unset/'' falls back to the deployment shape — ON
// standalone, OFF managed (the shop platform owns customer emails).

assert_true(Config::isPayerEmailCaptureEnabled(), 'standalone default with no explicit setting is ON');
putenv('CASHUPAY_MANAGED_INSTALL=1');
assert_false(Config::isPayerEmailCaptureEnabled(), 'a managed install defaults capture OFF');

// An operator's explicit choice beats the managed default in both directions.
Config::set('payer_email_capture_enabled', true);
assert_true(Config::isPayerEmailCaptureEnabled(), 'explicit true wins over the managed default');
Config::set('payer_email_capture_enabled', '1');
assert_true(Config::isPayerEmailCaptureEnabled(), "explicit '1' also reads as on");
putenv('CASHUPAY_MANAGED_INSTALL');
Config::set('payer_email_capture_enabled', false);
assert_false(Config::isPayerEmailCaptureEnabled(), 'explicit false wins over the standalone default');
// Any other explicit value is OFF — the toggle stores true/false, so an
// unexpected value must fail closed rather than falling back.
Config::set('payer_email_capture_enabled', 'yes');
assert_false(Config::isPayerEmailCaptureEnabled(), 'an unexpected explicit value reads as off');

// The empty string is "unset", not "explicit off": back to the shape default.
Config::set('payer_email_capture_enabled', '');
assert_true(Config::isPayerEmailCaptureEnabled(), "'' falls back to the standalone default (on)");
putenv('CASHUPAY_MANAGED_INSTALL=1');
assert_false(Config::isPayerEmailCaptureEnabled(), "'' falls back to the managed default (off)");

// Cleanup so later assertions in this process see pristine state.
putenv('CASHUPAY_MANAGED_INSTALL');
Config::delete('payer_email_capture_enabled');
assert_true(Config::isPayerEmailCaptureEnabled(), 'cleanup: deleted key restores the standalone default');

echo "test_managed_install: ok\n";
