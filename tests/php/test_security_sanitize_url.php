<?php
/**
 * Security::sanitizeUrl is the http(s)-only gate now relied on by the cart
 * checkout redirect (rendered as an <a href> on the public payment page) and
 * the webhook create/update path. It must accept http/https and reject any
 * other scheme (javascript:, data:, ftp:, file:) and unparseable input —
 * htmlspecialchars does NOT neutralize a javascript: scheme in an href, so a
 * stored bad value would be a stored-XSS sink.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

// Accepted.
assert_eq('https://example.com/return', Security::sanitizeUrl('https://example.com/return'), 'https');
assert_eq('http://example.com/return', Security::sanitizeUrl('http://example.com/return'), 'http');

// Rejected schemes -> null.
assert_null(Security::sanitizeUrl('javascript:alert(1)'), 'javascript');
assert_null(Security::sanitizeUrl('JavaScript:alert(1)'), 'javascript mixed-case');
assert_null(Security::sanitizeUrl('data:text/html,<script>alert(1)</script>'), 'data');
assert_null(Security::sanitizeUrl('ftp://example.com/x'), 'ftp');
assert_null(Security::sanitizeUrl('file:///etc/passwd'), 'file');

// Garbage / no scheme -> null.
assert_null(Security::sanitizeUrl('not a url'), 'plain text');
assert_null(Security::sanitizeUrl('example.com/no-scheme'), 'no scheme');

fwrite(STDERR, "ok\n");
