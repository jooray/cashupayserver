<?php
/**
 * UsageStats::buildPayload — the report IS the preview, so pin it:
 *   - each level carries exactly its allowlisted keys (nothing more, nothing
 *     less, stable order), and the allowlist matches the endpoint's copy;
 *   - counts come out right against seeded stores / rails / invoices, and the
 *     30-day window and Settled/paid_at semantics hold;
 *   - nothing identifying is in the JSON at any level: no deployment id, store
 *     name, mint URL, Lightning address, email, on-chain address, bolt11;
 *   - the install id is a placeholder until opt-in, then a v4 UUID that
 *     survives opt-out and re-opt-in; setChoice validates the level;
 *   - installKind / webServer / isDue helpers.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/usage_stats.php';
require_once dirname(__DIR__, 2) . '/app/includes/products.php';

$NOW = 1_800_000_000;
$MINT  = 'https://mint.secret-example.com';
$LNADDR = 'merchant@secret-lnaddr.example';
$NWC = 'nostr+walletconnect://deadbeef?relay=wss%3A%2F%2Frelay.secret.example&secret=cafebabe';
$XPUB = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
$STATIC = 'bc1qsecretstaticaddressxxxxxxxxxxxxxxxxxx';
$EMAIL = 'customer@secret.example';
$BOLT11 = 'lnbc10u1secretbolt11xxxxxxxxxxxxxxxxxxxx';
Config::set('deployment_id', 'SECRET-DEPLOYMENT-42');

// --- seed: 3 stores, mixed rails ------------------------------------------------
make_store('s_mint', $MINT);                    // mint + lnaddress + nwc + xpub + swaps
Database::update('stores', ['name' => 'Secret Store Name'], 'id = ?', ['s_mint']);
make_store('s_nomint', null);                   // noffer + static on-chain + strike on-chain
make_store('s_bare', null);                     // nothing at all
Database::insert('store_ln_addresses', ['store_id' => 's_mint', 'position' => 0, 'address' => $LNADDR, 'type' => 'lnaddress']);
Database::insert('store_ln_addresses', ['store_id' => 's_mint', 'position' => 1, 'address' => $NWC, 'type' => 'nwc']);
Database::insert('store_ln_addresses', ['store_id' => 's_nomint', 'position' => 0, 'address' => 'noffer1secretoffer', 'type' => 'noffer']);
Database::update('stores', ['onchain_address_mode' => 'xpub', 'onchain_xpub' => $XPUB, 'swaps_enabled' => 1], 'id = ?', ['s_mint']);
Database::update('stores', ['onchain_address_mode' => 'static', 'onchain_static_address' => $STATIC, 'onchain_strike_enabled' => 1], 'id = ?', ['s_nomint']);
// a store that switched from static back to xpub keeps the stale static column: not configured
Database::update('stores', ['onchain_address_mode' => 'xpub', 'onchain_static_address' => $STATIC], 'id = ?', ['s_bare']);

Database::insert('products', ['id' => 'p1', 'store_id' => 's_mint', 'title' => 'Secret Product', 'price' => '1000', 'currency' => 'sat', 'created_at' => $NOW, 'updated_at' => $NOW]);
Database::insert('products', ['id' => 'p2', 'store_id' => 's_mint', 'title' => 'Other', 'price' => '5', 'currency' => 'USD', 'enabled' => 0, 'created_at' => $NOW, 'updated_at' => $NOW]);
Database::insert('api_keys', ['id' => 'k1', 'key_hash' => str_repeat('a', 64), 'store_id' => 's_mint', 'label' => 'shop', 'permissions' => '[]', 'created_at' => $NOW]);
Database::insert('api_keys', ['id' => 'k2', 'key_hash' => str_repeat('b', 64), 'store_id' => 's_mint', 'label' => 'other', 'permissions' => '[]', 'created_at' => $NOW]);

$inv = 0;
$addInvoice = function (string $store, string $status, int $sats, int $createdAgo, ?int $paidAgo, ?string $rail) use (&$inv, $NOW, $MINT, $BOLT11, $EMAIL): void {
    $inv++;
    Database::insert('invoices', [
        'id' => "inv_{$inv}", 'store_id' => $store, 'status' => $status, 'amount' => (string)$sats, 'currency' => 'sat',
        'amount_sats' => $sats, 'mint_url' => $MINT, 'bolt11' => $BOLT11, 'customer_email' => $EMAIL,
        'created_at' => $NOW - $createdAgo, 'expiration_time' => $NOW - $createdAgo + 900,
        'paid_at' => $paidAgo === null ? null : $NOW - $paidAgo, 'settled_rail' => $rail,
    ]);
};
$addInvoice('s_mint', 'Settled', 1000, 86400, 86400 - 60, 'mint');
$addInvoice('s_mint', 'Settled', 2000, 2 * 86400, 2 * 86400 - 60, 'mint');
$addInvoice('s_mint', 'Settled', 500, 5 * 86400, 5 * 86400 - 60, 'onchain');
$addInvoice('s_nomint', 'Settled', 300, 10 * 86400, 10 * 86400 - 60, 'noffer');
$addInvoice('s_mint', 'Expired', 700, 3 * 86400, null, null);
$addInvoice('s_mint', 'New', 100, 60, null, null);
$addInvoice('s_mint', 'Settled', 9_999_999, 40 * 86400, 40 * 86400 - 60, 'lnaddress');   // outside the window
$addInvoice('s_mint', 'Expired', 50, 31 * 86400, null, null);                            // outside the window
// Settled with a NULL paid_at (legacy row) is not counted as settled-in-window.
$addInvoice('s_mint', 'Settled', 123, 86400, null, 'mint');

// --- allowlist shape -----------------------------------------------------------------
$l1 = UsageStats::buildPayload(1, $NOW);
$l2 = UsageStats::buildPayload(2, $NOW);
$l3 = UsageStats::buildPayload(3, $NOW);
foreach ([1 => $l1, 2 => $l2, 3 => $l3] as $level => $p) {
    assert_eq(['schema_version', 'event', 'client', 'install_id', 'sent_at', 'level', 'properties'], array_keys($p), "L{$level} envelope keys");
    assert_eq(1, $p['schema_version']);
    assert_eq('usage_report', $p['event']);
    assert_eq('barebits-payserver', $p['client']);
    assert_eq($level, $p['level']);
    assert_eq('2027-01-15T08:00:00Z', $p['sent_at'], 'sent_at is ISO-8601 UTC from $now');
    assert_eq(UsageStats::allowedPropertiesForLevel($level), array_keys($p['properties']), "L{$level} carries exactly its allowlisted keys, in order");
}
assert_eq(7, count($l1['properties']));
assert_eq(7 + 13, count($l2['properties']));
assert_eq(7 + 13 + 4 + 14, count($l3['properties']));
assert_eq(UsageStats::INSTALL_ID_PLACEHOLDER, $l1['install_id'], 'no id before opt-in: the preview says so');

// The endpoint's rule table must list exactly the same keys per level.
require_once dirname(__DIR__, 2) . '/scripts/analytics-endpoint/common.php';
foreach (UsageStats::LEVELS as $level) {
    $server = array_keys(analytics_rules_for_level($level));
    sort($server);
    $client = UsageStats::allowedPropertiesForLevel($level);
    sort($client);
    assert_eq($server, $client, "endpoint and client agree on the level-{$level} allowlist");
}

// --- level 1 values --------------------------------------------------------------------
$p = $l1['properties'];
assert_eq(CASHUPAY_VERSION, $p['version']);
assert_null($p['build_sha'], 'dev checkout without BUILD_INFO -> null');
assert_eq('main', $p['channel']);
assert_eq(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $p['php_version']);
assert_eq(PHP_OS_FAMILY, $p['os_family']);
assert_eq('standalone', $p['install_kind']);
assert_eq('unknown', $p['web_server'], 'CLI has no SERVER_SOFTWARE');

// --- level 2 values --------------------------------------------------------------------
$p = $l2['properties'];
assert_eq(3, $p['store_count']);
assert_eq(['mint', 'lnaddress', 'nwc', 'noffer', 'strike', 'onchain', 'swaps'], $p['rails_enabled'], 'every rail with at least one store, in canonical order');
assert_eq(1, $p['stores_with_mint']);
assert_eq(1, $p['stores_with_lnaddress']);
assert_eq(1, $p['stores_with_nwc']);
assert_eq(1, $p['stores_with_noffer']);
assert_eq(1, $p['stores_with_strike'], 'Strike on-chain minting counts as the Strike rail');
assert_eq(2, $p['stores_with_onchain'], 'xpub store + static store; the stale-static xpub store does not count');
assert_eq(1, $p['stores_with_swaps']);
assert_false($p['auto_update_enabled']);
assert_eq(2, $p['products_count'], 'disabled products still count as products');
assert_eq(2, $p['api_keys_count']);
assert_false($p['wordpress_connected']);
Config::set('auto_update_enabled', true);
Database::insert('api_keys', ['id' => 'k3', 'key_hash' => str_repeat('c', 64), 'store_id' => 's_mint', 'label' => 'wp', 'permissions' => '[]', 'application_identifier' => 'barebits-wordpress', 'created_at' => $NOW]);
$p = UsageStats::buildPayload(2, $NOW)['properties'];
assert_true($p['auto_update_enabled']);
assert_true($p['wordpress_connected'], 'a key paired by the WordPress plugin means connected');
assert_eq(3, $p['api_keys_count']);

// --- level 3 values --------------------------------------------------------------------
$p = $l3['properties'];
assert_eq(7, $p['invoices_30d_created'], 'created in the last 30 days (incl. New and Expired, excl. the two older rows)');
assert_eq(4, $p['invoices_30d_settled'], 'Settled with paid_at in window: 2 mint + onchain + noffer');
assert_eq(1, $p['invoices_30d_expired']);
assert_eq(3800, $p['invoices_30d_settled_sats']);
assert_eq(2, $p['settled_30d_mint_count']);
assert_eq(3000, $p['settled_30d_mint_sats']);
assert_eq(0, $p['settled_30d_lnaddress_count'], 'the 40-day-old lnaddress settlement is outside the window');
assert_eq(1, $p['settled_30d_onchain_count']);
assert_eq(500, $p['settled_30d_onchain_sats']);
assert_eq(1, $p['settled_30d_noffer_count']);
assert_eq(0, $p['settled_30d_swap_count']);
assert_eq(0, $p['settled_30d_strike_sats']);

// --- nothing identifying, at any level ----------------------------------------------------
$json = UsageStats::previewJson(3, $NOW);
foreach ([
    'SECRET-DEPLOYMENT-42', 'Secret Store Name', 'Secret Product', 'secret-example.com', $MINT, $LNADDR,
    'secret-lnaddr', 'walletconnect', 'cafebabe', 'noffer1secret', 'zpub6', $STATIC, $EMAIL, 'lnbc10u1', 'inv_1',
    'mint_url', 'deployment', 'email', 'bc1q', 'notification', 'auto_melt', 'cashout', 'seed', 'xpub',
] as $needle) {
    assert_false(stripos($json, $needle) !== false, "level-3 report must not contain {$needle}");
}
assert_true(str_contains($json, '"schema_version": 1'), 'preview is pretty-printed JSON');
assert_eq($json, UsageStats::previewJson(3, $NOW), 'preview is deterministic for a fixed $now');
assert_eq(json_decode($json, true), UsageStats::buildPayload(3, $NOW), 'preview and payload are the same object');

// --- opt-in state / install id -----------------------------------------------------------
assert_false(UsageStats::isAnswered(), 'fresh install: unanswered');
assert_false(UsageStats::isEnabled());
assert_null(UsageStats::level());
assert_null(UsageStats::installId());
$threw = false;
try {
    UsageStats::setChoice(true, null);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'enabling without a level is refused');
try {
    UsageStats::setChoice(true, 4);
    $threw = false;
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'level 4 is refused');
assert_false(UsageStats::isAnswered(), 'a refused call writes nothing');

UsageStats::setChoice(false);
assert_true(UsageStats::isAnswered(), 'an explicit decline is an answer');
assert_false(UsageStats::isEnabled());
assert_null(UsageStats::installId(), 'declining mints no id');

UsageStats::setChoice(true, 2);
assert_true(UsageStats::isEnabled());
assert_eq(2, UsageStats::level());
$id = UsageStats::installId();
assert_not_null($id);
assert_true(UsageStats::isUuid($id), "install id is a v4 UUID: {$id}");
assert_eq($id, UsageStats::buildPayload(1, $NOW)['install_id'], 'the stored id is what the report carries');
assert_false(str_contains($id, substr(hash('sha256', 'SECRET-DEPLOYMENT-42'), 0, 8)), 'id is random, not derived');

UsageStats::setChoice(false);
assert_eq(2, UsageStats::level(), 'opt-out keeps the previously chosen level for the card');
assert_eq($id, UsageStats::installId(), 'opt-out keeps the id so a re-enable is the same install upstream');
UsageStats::setChoice(true, 3);
assert_eq($id, UsageStats::installId(), 're-enable: same id');
assert_eq(3, UsageStats::level());
UsageStats::setChoice(false, 1);
assert_eq(1, UsageStats::level(), 'a level passed with a decline is remembered for the card');
assert_false(UsageStats::isEnabled());

// --- helpers ---------------------------------------------------------------------------------
assert_eq('apache', UsageStats::webServer('Apache/2.4.58 (Ubuntu)'));
assert_eq('nginx', UsageStats::webServer('nginx/1.24.0'));
assert_eq('litespeed', UsageStats::webServer('LiteSpeed'));
assert_eq('iis', UsageStats::webServer('Microsoft-IIS/10.0'));
assert_eq('php-dev-server', UsageStats::webServer('PHP 8.3.31 Development Server'));
assert_eq('caddy', UsageStats::webServer('Caddy'));
assert_eq('other', UsageStats::webServer('Cherokee/1.2'));
assert_eq('unknown', UsageStats::webServer(null));
assert_eq('unknown', UsageStats::webServer(''));
putenv('CASHUPAY_DOCKER=1');
assert_eq('docker', UsageStats::installKind(), 'CASHUPAY_DOCKER marks the Docker image');
putenv('CASHUPAY_DOCKER=0');
assert_eq(is_file('/.dockerenv') ? 'docker' : 'standalone', UsageStats::installKind(), '"0" is not set');
putenv('CASHUPAY_DOCKER');
putenv('CASHUPAY_MANAGED_INSTALL=1');
assert_eq('wordpress', UsageStats::installKind(), 'a managed install reports as wordpress');
putenv('CASHUPAY_MANAGED_INSTALL');
putenv('CASHUPAY_DESKTOP=1');
assert_eq('desktop', UsageStats::installKind(), 'the desktop launcher env wins');
putenv('CASHUPAY_DESKTOP');

Config::delete(UsageStats::CONFIG_LAST_SENT_AT);
Config::delete(UsageStats::CONFIG_LAST_ATTEMPT_AT);
assert_true(UsageStats::isDue($NOW), 'never sent -> due');
Config::set(UsageStats::CONFIG_LAST_SENT_AT, $NOW - 6 * 86400);
assert_false(UsageStats::isDue($NOW), 'sent 6 days ago -> not due');
Config::set(UsageStats::CONFIG_LAST_SENT_AT, $NOW - 7 * 86400 - 1);
assert_true(UsageStats::isDue($NOW), 'sent over 7 days ago -> due');
Config::set(UsageStats::CONFIG_LAST_ATTEMPT_AT, $NOW - 1800);
assert_false(UsageStats::isDue($NOW), 'a failed attempt 30 min ago holds the retry');
Config::set(UsageStats::CONFIG_LAST_ATTEMPT_AT, $NOW - 3601);
assert_true(UsageStats::isDue($NOW), 'an hour after the failed attempt -> due again');

$desc = UsageStats::levelDescriptions();
assert_eq([1, 2, 3], array_keys($desc));
foreach ($desc as $d) {
    assert_true($d['title'] !== '' && $d['summary'] !== '' && count($d['items']) >= 2);
}

echo "test_usage_stats_payload: ok\n";
