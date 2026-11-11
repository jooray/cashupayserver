<?php
/**
 * Config::redactStoreSecrets must strip every secret column from a store row
 * before it is serialized to the admin panel — the panel is reachable by the
 * non-admin ROLE_USER, who must never receive a store's spendable seed phrase,
 * SMTP password, raw internal API key, or xpubs. Non-secret fields (including
 * the derived camelCase internalApiKey the Request Payment feature needs) must
 * survive. See includes/config.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/config.php';

// A representative store row as returned by SELECT * FROM stores, with the
// dashboard's derived fields already attached.
$row = [
    'id' => 'store_abc',
    'name' => 'Acme',
    'mint_url' => 'https://mint.example',
    'mint_unit' => 'sat',
    'default_currency' => 'USD',
    'onchain_static_address' => 'bc1qexampleaddr',   // public receive addr, NOT secret
    'seed_phrase' => 'abandon abandon abandon ... art',
    'internal_api_key' => 'raw-secret-key-value',
    'smtp_password' => 'hunter2',
    'smtp_username' => 'smtp-user',                   // not a secret per se
    'onchain_xpub' => 'xpub6Cexample',
    'hosting_fee_onchain_xpub' => 'xpub6Dexample',
    'internalApiKey' => 'raw-secret-key-value',        // derived camelCase field
    'isConfigured' => true,
];

$safe = Config::redactStoreSecrets($row);

// ---- Secret columns are gone ----
foreach (['seed_phrase', 'internal_api_key', 'smtp_password', 'onchain_xpub', 'hosting_fee_onchain_xpub'] as $col) {
    assert_true(!array_key_exists($col, $safe), "secret column '$col' is stripped");
}

// The constant and the redaction must stay in sync — every declared secret is removed.
foreach (Config::STORE_SECRET_COLUMNS as $col) {
    assert_true(!array_key_exists($col, $safe), "declared secret column '$col' is stripped");
}

// ---- Non-secret fields survive ----
assert_eq('store_abc', $safe['id'], 'id retained');
assert_eq('Acme', $safe['name'], 'name retained');
assert_eq('https://mint.example', $safe['mint_url'], 'mint_url retained');
assert_eq('USD', $safe['default_currency'], 'currency retained');
assert_eq('bc1qexampleaddr', $safe['onchain_static_address'], 'public receive address retained');
assert_eq('smtp-user', $safe['smtp_username'], 'smtp_username retained (not a secret)');
assert_true($safe['isConfigured'], 'derived isConfigured retained');

// The receive-only camelCase key the Request Payment UI needs must survive —
// only the raw snake_case internal_api_key column is stripped.
assert_eq('raw-secret-key-value', $safe['internalApiKey'], 'derived internalApiKey retained');

// ---- Idempotent + safe on a row missing the secret columns ----
$again = Config::redactStoreSecrets($safe);
assert_eq($safe, $again, 'redaction is idempotent');

$sparse = Config::redactStoreSecrets(['id' => 'x', 'name' => 'y']);
assert_eq(['id' => 'x', 'name' => 'y'], $sparse, 'no-op when no secret columns present');

fwrite(STDERR, "ok\n");
