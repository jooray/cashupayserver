<?php
/**
 * EmailSender::sendWithConfig() + smtpFieldsFromPost().
 *
 * The setup wizard's e-mail screen proves a CANDIDATE configuration works
 * before persisting it, so the sender needs an entry point that takes the
 * config explicitly instead of reading the stored one. This pins that it
 * honours the test transport override like send(), validates the From
 * address up front (no network needed), and that the shared form parser
 * admin.php and the wizard both use rejects what it always rejected.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/email_sender.php';

// --- sendWithConfig honours the transport override --------------------------
$captured = [];
EmailSender::$transportOverride = function ($to, $subject, $body) use (&$captured) {
    $captured[] = [$to, $subject, $body];
};
EmailSender::sendWithConfig(
    ['host' => 'smtp.example.com', 'from_address' => 'shop@example.com'],
    'owner@example.com', 'Subject', 'Body'
);
assert_eq(1, count($captured), 'the override received the message');
assert_eq(['owner@example.com', 'Subject', 'Body'], $captured[0], 'to/subject/body pass through');

// --- without an override, a blank From address is refused before any I/O ----
EmailSender::$transportOverride = null;
$threw = false;
try {
    EmailSender::sendWithConfig(['host' => 'smtp.example.com'], 'owner@example.com', 'S', 'B');
} catch (RuntimeException $e) {
    $threw = true;
    assert_true(str_contains($e->getMessage(), 'From address'), 'names the missing From address: ' . $e->getMessage());
}
assert_true($threw, 'a config without a From address throws');

// The stored config is NOT consulted: a from address in the config table
// does not rescue an explicit candidate config that lacks one.
Config::set('smtp_from_address', 'stored@example.com');
$threw = false;
try {
    EmailSender::sendWithConfig(['host' => 'smtp.example.com'], 'owner@example.com', 'S', 'B');
} catch (RuntimeException $e) {
    $threw = true;
}
assert_true($threw, 'sendWithConfig never falls back to the stored settings');

// --- smtpFieldsFromPost: the shared parser ---------------------------------
$f = EmailSender::smtpFieldsFromPost([
    'smtp_host' => ' smtp.example.com ', 'smtp_port' => '2525', 'smtp_username' => 'mailer',
    'smtp_encryption' => 'TLS', 'smtp_from_address' => 'shop@example.com',
    'smtp_from_name' => 'Shop', 'smtp_password' => ' p w ', 'smtp_password_clear' => '0',
]);
assert_eq('smtp.example.com', $f['host'], 'host is trimmed');
assert_eq('2525', $f['port']);
assert_eq('tls', $f['encryption'], 'encryption is lower-cased');
assert_eq(' p w ', $f['password'], 'the password is never trimmed');
assert_false($f['password_clear']);

foreach ([
    [['smtp_port' => '0'], 'port'],
    [['smtp_port' => '70000'], 'port'],
    [['smtp_port' => 'abc'], 'port'],
    [['smtp_encryption' => 'starttls'], 'encryption'],
    [['smtp_from_address' => 'not-an-address'], 'From'],
] as [$post, $needle]) {
    $threw = false;
    try {
        EmailSender::smtpFieldsFromPost($post);
    } catch (Exception $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), $needle), json_encode($post) . ' -> ' . $e->getMessage());
    }
    assert_true($threw, json_encode($post) . ' must be rejected');
}
// Blank optional fields are fine.
$blank = EmailSender::smtpFieldsFromPost([]);
assert_eq('', $blank['host']);
assert_eq('', $blank['port']);

// admin.php's wrapper delegates to the same parser (it is defined inside
// admin.php, so this only checks the class method is what the wizard uses).
assert_true(method_exists('EmailSender', 'smtpFieldsFromPost'), 'parser lives on EmailSender');

echo "ok\n";
