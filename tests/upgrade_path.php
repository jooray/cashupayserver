<?php
/**
 * In-place upgrade of a live v0.4.1 installation.
 *
 * The operators of this software are shop owners, not administrators: an upgrade that
 * loses their configuration, hides their money, or drops them back into the setup wizard
 * is not something they can diagnose. This walks a realistic v6-schema database through
 * the current code and asserts that nothing of theirs moves.
 *
 * Run: php tests/upgrade_path.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$dataDir = sys_get_temp_dir() . '/cashupay-upgrade-' . bin2hex(random_bytes(8));
mkdir($dataDir, 0700, true);
define('CASHUPAY_DATA_DIR', $dataDir);

require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';

use Cashu\WalletStorage;

function check(bool $cond, string $what): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "  ok: $what\n";
}

// --- A database as v0.4.1 left it --------------------------------------------
$dbPath = $dataDir . '/cashupay.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = time();

$pdo->exec("CREATE TABLE config (key TEXT PRIMARY KEY, value TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE stores (id TEXT PRIMARY KEY, name TEXT NOT NULL, mint_url TEXT, mint_unit TEXT NOT NULL DEFAULT 'sat', seed_phrase TEXT, wallet_account_id TEXT, internal_api_key TEXT, auto_melt_enabled INTEGER DEFAULT 0, auto_melt_address TEXT, auto_melt_threshold INTEGER DEFAULT 2000, price_provider_primary TEXT, price_provider_secondary TEXT, exchange_fee_percent TEXT, created_at INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE store_mints (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id TEXT NOT NULL, mint_url TEXT NOT NULL, unit TEXT NOT NULL DEFAULT 'sat', priority INTEGER NOT NULL DEFAULT 0, enabled INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, UNIQUE(store_id, mint_url))");
$pdo->exec("CREATE TABLE invoices (id TEXT PRIMARY KEY, store_id TEXT, status TEXT, additional_status TEXT, quote_id TEXT, mint_url TEXT, amount TEXT, currency TEXT, amount_sats INTEGER, exchange_rate TEXT, bolt11 TEXT, metadata TEXT, checkout_config TEXT, expiration_time INTEGER, created_at INTEGER, last_polled_at INTEGER, processing_since INTEGER)");
$pdo->exec("CREATE TABLE webhooks (id TEXT PRIMARY KEY, store_id TEXT, url TEXT, secret TEXT, events TEXT, enabled INTEGER DEFAULT 1, deleted_at INTEGER, created_at INTEGER)");
$pdo->exec("CREATE TABLE webhook_deliveries (id TEXT PRIMARY KEY, webhook_id TEXT, payload TEXT, status_code INTEGER, response TEXT, attempts INTEGER DEFAULT 0, next_attempt_at INTEGER DEFAULT 0, leased_until INTEGER, lease_token TEXT, last_attempt_at INTEGER, delivered_at INTEGER, idempotency_key TEXT, target_url TEXT, signing_secret TEXT, created_at INTEGER)");
$pdo->exec("CREATE TABLE api_keys (id TEXT PRIMARY KEY, key_hash TEXT, store_id TEXT, label TEXT, permissions TEXT, application_identifier TEXT, redirect_host TEXT, created_at INTEGER)");
$pdo->exec("CREATE TABLE transfers (id TEXT PRIMARY KEY, store_id TEXT NOT NULL, type TEXT NOT NULL, amount INTEGER NOT NULL, fee INTEGER NOT NULL DEFAULT 0, unit TEXT NOT NULL DEFAULT 'sat', destination TEXT, status TEXT NOT NULL DEFAULT 'completed', detail TEXT, created_at INTEGER NOT NULL)");
WalletStorage::initializeSchema($pdo);

// Config is JSON-encoded by Config::set().
$cfg = $pdo->prepare("INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)");
$cfg->execute(['setup_complete', json_encode(true), $now, $now]);
$cfg->execute(['admin_password_hash', json_encode(password_hash('shop-secret', PASSWORD_DEFAULT)), $now, $now]);
$cfg->execute(['cron_key', json_encode('existing-cron-key'), $now, $now]);

$pdo->exec("INSERT INTO stores (id, name, mint_url, mint_unit, seed_phrase, wallet_account_id, created_at)
            VALUES ('store_1','Juraj''s Shop','https://mint.example','sat','abandon abandon about','wa_1',$now)");
$pdo->exec("INSERT INTO invoices (id, store_id, status, quote_id, amount, currency, expiration_time, created_at)
            VALUES ('inv_settled','store_1','Settled','q-settled','10','USD',$now,$now)");
$pdo->exec("INSERT INTO invoices (id, store_id, status, quote_id, amount, currency, expiration_time, created_at)
            VALUES ('inv_expired','store_1','Expired','q-unresolved','5','USD'," . ($now - 200 * 86400) . "," . ($now - 200 * 86400) . ")");
$pdo->exec("INSERT INTO webhooks (id, store_id, url, secret, events, enabled, created_at)
            VALUES ('wh_1','store_1','https://shop.example/?wc-api=btcpaygf_default','sekret','[]',1,$now)");

$walletId = WalletStorage::deriveWalletId('https://mint.example', 'sat', 'wa_1');
$proof = $pdo->prepare("INSERT INTO cashu_proofs (wallet_id, keyset_id, amount, secret, C, state, created_at) VALUES (?,?,?,?,?,?,?)");
// A proof an old export handed out and left PENDING, and one that is genuinely spendable.
$proof->execute([$walletId, '009a1f293253e41e', 64, 'exported-secret', '02' . str_repeat('11', 32), 'PENDING', $now]);
$proof->execute([$walletId, '009a1f293253e41e', 32, 'spendable-secret', '02' . str_repeat('22', 32), 'UNSPENT', $now]);
// A proof genuinely reserved by an in-flight melt must NOT be reclassified.
$proof->execute([$walletId, '009a1f293253e41e', 16, 'reserved-secret', '02' . str_repeat('33', 32), 'PENDING', $now]);
$pdo->prepare("INSERT INTO cashu_pending_operations (id, wallet_id, type, data, created_at) VALUES (?,?,?,?,?)")
    ->execute(['melt:q9', $walletId, 'melt', json_encode(['input_secrets' => ['reserved-secret']]), $now]);

$pdo->exec('PRAGMA user_version = 6');
$pdo = null;

// --- Open it with the current code, as the first request after upgrading would ---
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/transfer.php';
require_once dirname(__DIR__) . '/includes/invoice.php';

$db = Database::getInstance();

check((int)$db->query('PRAGMA user_version')->fetchColumn() === 8, 'schema migrates to the current version automatically');

// The operator must not be dropped back into the setup wizard.
check(Config::isSetupComplete(), 'the installation is still set up');
check(Database::isInitialized(), 'the database is still recognised as initialised');
check(Config::get('cron_key') === 'existing-cron-key', 'the existing cron key is preserved');
check(password_verify('shop-secret', Config::getAdminPasswordHash()), 'the admin password still works');

check(Database::fetchOne("SELECT name FROM stores WHERE id='store_1'")['name'] === "Juraj's Shop", 'the store survives');
check(Database::fetchOne("SELECT status FROM invoices WHERE id='inv_settled'")['status'] === 'Settled', 'settled invoices survive');
check(Database::fetchOne("SELECT secret FROM webhooks WHERE id='wh_1'")['secret'] === 'sekret', 'webhook secrets survive');

// The PENDING reclassification must distinguish an old export from a live reservation.
$states = [];
foreach ($db->query("SELECT secret, state FROM cashu_proofs") as $row) {
    $states[$row['secret']] = $row['state'];
}
check($states['exported-secret'] === 'EXPORTED', 'a proof left PENDING by an old export becomes EXPORTED');
check($states['reserved-secret'] === 'PENDING', 'a proof reserved by a live melt journal stays PENDING');
check($states['spendable-secret'] === 'UNSPENT', 'spendable proofs are untouched');

// Balance now excludes the exported proof, which is the point: it was handed to someone.
check(Invoice::getBalance('store_1') === 32, 'balance excludes the exported proof (32, not 96)');

// v7 columns exist and old rows are readable through the new code.
$transferColumns = array_column($db->query('PRAGMA table_info(transfers)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['token', 'reference', 'updated_at'] as $column) {
    check(in_array($column, $transferColumns, true), "transfers.$column added");
}

check(
    $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='payment_requests'")->fetchColumn() !== false,
    'v8 adds the payment_requests table'
);

// An unresolved quote well past the 90-day cleanup window must not be deleted.
require_once dirname(__DIR__) . '/includes/background_runner.php';
$cleanup = new ReflectionMethod(BackgroundRunner::class, 'cleanupInvoices');
$cleanup->invoke(null);
check(
    Database::fetchOne("SELECT id FROM invoices WHERE id='inv_expired'") !== null,
    'a 200-day-old invoice whose quote may still be paid is not deleted'
);

echo "upgrade_path: OK\n";

foreach (glob($dataDir . '/*') ?: [] as $file) { @unlink($file); }
@rmdir($dataDir);
