<?php
/**
 * Newsletter checkbox default resolution: per-store override wins over the
 * site-wide default, which itself defaults to "checked" when unset.
 * See Config::getNewsletterDefaultChecked().
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

$store = 'store_nl';
make_store($store);

// Unset everywhere → site-wide default is "checked".
assert_eq(true, Config::getNewsletterDefaultChecked($store), 'site default checked when unset');

// Flip the site-wide default off; store still inherits.
Config::set('newsletter_default_checked', false);
assert_eq(false, Config::getNewsletterDefaultChecked($store), 'inherits site default off');

// Per-store override = checked wins over the site-wide "off".
Database::update('stores', ['newsletter_default_checked' => 1], 'id = ?', [$store]);
assert_eq(true, Config::getNewsletterDefaultChecked($store), 'store override checked beats site off');

// Per-store override = unchecked wins over the site-wide "on".
Config::set('newsletter_default_checked', true);
Database::update('stores', ['newsletter_default_checked' => 0], 'id = ?', [$store]);
assert_eq(false, Config::getNewsletterDefaultChecked($store), 'store override unchecked beats site on');

// Clearing the override (NULL) falls back to the site-wide default again.
Database::update('stores', ['newsletter_default_checked' => null], 'id = ?', [$store]);
assert_eq(true, Config::getNewsletterDefaultChecked($store), 'inherits again after override cleared');

echo "test_newsletter_default_resolution: ok\n";
