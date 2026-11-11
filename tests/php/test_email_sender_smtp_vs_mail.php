<?php
/**
 * EmailSender picks the SMTP transport when CASHUPAY_SMTP_HOST is defined,
 * and PHP mail() otherwise. We can't exercise the real send paths in a unit
 * test (no SMTP server, no MTA), but we can verify the configuration branch
 * via isSmtpConfigured() and the transport override hook.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';

// No SMTP host defined yet.
assert_eq(false, EmailSender::isSmtpConfigured(), 'isSmtpConfigured false when host unset');

// Define a host as a constant (mirrors how user_config.php works).
define('CASHUPAY_SMTP_HOST', 'smtp.example.com');
assert_eq(true, EmailSender::isSmtpConfigured(), 'isSmtpConfigured true when host set');

// Transport override is invoked verbatim — no PHPMailer touched.
$captured = null;
EmailSender::$transportOverride = function($to, $subject, $body) use (&$captured) {
    $captured = compact('to', 'subject', 'body');
};
EmailSender::send('alice@example.com', 'Hello', "Body text\n");
assert_eq('alice@example.com', $captured['to']);
assert_eq('Hello', $captured['subject']);
assert_eq("Body text\n", $captured['body']);

// Transport override that throws propagates as RuntimeException (the queue
// drain depends on this contract).
EmailSender::$transportOverride = function() {
    throw new RuntimeException('boom');
};
$threw = false;
try {
    EmailSender::send('bob@example.com', 's', 'b');
} catch (RuntimeException $e) {
    $threw = true;
    assert_eq('boom', $e->getMessage());
}
assert_true($threw, 'override exception propagates');

echo "test_email_sender_smtp_vs_mail: ok\n";
