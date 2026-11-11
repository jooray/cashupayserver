<?php
/**
 * EmailSender::resolveConfig — the per-field SMTP cascade introduced with the
 * UI-editable SMTP settings:
 *
 *   per-store override (when smtp_override_enabled = 1)
 *     -> global config-table value
 *       -> user_config.php constant
 *         -> '' (empty)
 *
 * A blank value at one layer cascades to the next; it never blanks the layer
 * below. Constants are process-global and can't be undefined, so this case
 * defines exactly one (CASHUPAY_SMTP_FROM_NAME) and only after the global
 * value for that field has been cleared, to exercise the constant layer.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// ---- Nothing configured anywhere ----
$cfg = EmailSender::resolveConfig(null);
assert_eq('', $cfg['host'], 'host empty with nothing set');
assert_eq('', $cfg['from_address'], 'from_address empty with nothing set');
assert_false(EmailSender::isSmtpConfigured(), 'isSmtpConfigured false when nothing set');

// send() refuses without a resolvable From address (transport untouched).
$threw = false;
try {
    EmailSender::send('a@example.com', 's', 'b');
} catch (RuntimeException $e) {
    $threw = true;
}
assert_true($threw, 'send throws when no From address resolves');

// ---- Global (config table) layer ----
Config::set('smtp_host', 'global.example.com');
Config::set('smtp_port', '2525');
Config::set('smtp_username', 'guser');
Config::set('smtp_password', 'gpass');
Config::set('smtp_encryption', 'ssl');
Config::set('smtp_from_address', 'from@global.example.com');
Config::set('smtp_from_name', 'Global Sender');

$cfg = EmailSender::resolveConfig(null);
assert_eq('global.example.com', $cfg['host'], 'global host');
assert_eq('2525', $cfg['port'], 'global port (as string)');
assert_eq('guser', $cfg['username'], 'global username');
assert_eq('gpass', $cfg['password'], 'global password');
assert_eq('ssl', $cfg['encryption'], 'global encryption');
assert_eq('from@global.example.com', $cfg['from_address'], 'global from_address');
assert_eq('Global Sender', $cfg['from_name'], 'global from_name');
assert_true(EmailSender::isSmtpConfigured(), 'isSmtpConfigured true once global host set');

// ---- Per-store override (enabled), partial fields cascade to global ----
make_store('s1');
Database::update('stores', [
    'smtp_override_enabled' => 1,
    'smtp_host' => 'store.example.com',
    'smtp_from_address' => 'from@store.example.com',
    // port/username/password/encryption/from_name intentionally left NULL
], 'id = ?', ['s1']);

$cfg = EmailSender::resolveConfig('s1');
assert_eq('store.example.com', $cfg['host'], 's1 host from override');
assert_eq('from@store.example.com', $cfg['from_address'], 's1 from_address from override');
assert_eq('2525', $cfg['port'], 's1 port cascades to global');
assert_eq('guser', $cfg['username'], 's1 username cascades to global');
assert_eq('gpass', $cfg['password'], 's1 password cascades to global');
assert_eq('ssl', $cfg['encryption'], 's1 encryption cascades to global');
assert_eq('Global Sender', $cfg['from_name'], 's1 from_name cascades to global');

// ---- Per-store override DISABLED — store fields are ignored entirely ----
make_store('s2');
Database::update('stores', [
    'smtp_override_enabled' => 0,
    'smtp_host' => 'ignored.example.com',
], 'id = ?', ['s2']);

$cfg = EmailSender::resolveConfig('s2');
assert_eq('global.example.com', $cfg['host'], 's2 ignores store host when override disabled');

// ---- Constant layer (only reached when DB value is blank) ----
Config::set('smtp_from_name', '');           // clear the global value for this field
define('CASHUPAY_SMTP_FROM_NAME', 'Const Sender');
$cfg = EmailSender::resolveConfig(null);
assert_eq('Const Sender', $cfg['from_name'], 'from_name falls back to constant when global blank');
// A field with neither DB nor constant value stays empty.
Config::set('smtp_username', '');
$cfg = EmailSender::resolveConfig(null);
assert_eq('', $cfg['username'], 'username empty when neither DB nor constant set');

echo "test_email_sender_resolution: ok\n";
