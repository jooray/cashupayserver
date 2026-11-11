<?php
/**
 * WM-9 / MC-2: Config::updateStore()
 *   - refuses to replace a store's seed phrase once set (a different phrase
 *     would orphan every proof derived from the written-down backup); the
 *     same value passes (setup / enable_store_mint re-pass it), and a store
 *     without a seed accepts one;
 *   - refuses a mint URL / unit change while the current wallet namespace
 *     holds proofs, counters or journals, unless the caller passes
 *     $allowMintChange (the operator confirmed the stranding warning); an
 *     empty namespace may be re-pointed freely.
 *   - Config::getStoreWalletAccounts() lists the primary and every backup,
 *     disabled ones included, de-duplicated.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_safety.php';

use Cashu\Proof;
use Cashu\WalletStorage;

make_store('s1', 'https://mint-a.example');
$seed = (string)Database::fetchOne("SELECT seed_phrase FROM stores WHERE id = 's1'")['seed_phrase'];

function store(string $id): array {
    return Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$id]);
}
function expect_refused(callable $fn, string $needle, string $label): void {
    try {
        $fn();
    } catch (Exception $e) {
        assert_true(stripos($e->getMessage(), $needle) !== false, "$label: wrong message: " . $e->getMessage());
        return;
    }
    fail("expected a refusal: $label");
}

// --- seed immutability ---------------------------------------------------------
expect_refused(fn() => Config::updateStore('s1', ['seed_phrase' => 'zoo zoo zoo zoo zoo zoo zoo zoo zoo zoo zoo wrong']),
    'cannot be replaced', 'different seed');
assert_eq($seed, store('s1')['seed_phrase'], 'seed unchanged after the refusal');

Config::updateStore('s1', ['seed_phrase' => $seed, 'name' => 'renamed']); // same value passes
assert_eq('renamed', store('s1')['name'], 'same-seed write passes and applies the rest');
Config::updateStore('s1', ['seed_phrase' => ' ' . $seed . "\n", 'name' => 'renamed2']); // whitespace-equal passes
assert_eq($seed, store('s1')['seed_phrase'], 'whitespace-equal seed is not treated as a change (and not rewritten)');
assert_eq('renamed2', store('s1')['name'], 'the rest of the update applied');

Database::insert('stores', ['id' => 's_noseed', 'name' => 'no seed', 'mint_url' => 'https://mint-b.example', 'created_at' => Database::timestamp()]);
Config::updateStore('s_noseed', ['seed_phrase' => $seed]);
assert_eq($seed, store('s_noseed')['seed_phrase'], 'a store without a seed accepts one');

// --- mint / unit change guard ----------------------------------------------------
// Empty namespace: re-pointing is fine.
Config::updateStore('s1', ['mint_url' => 'https://mint-b.example']);
assert_eq('https://mint-b.example', store('s1')['mint_url'], 'empty namespace may change mint');
assert_eq('manual', store('s1')['primary_mint_source'], 'primary_mint_source stamped manual');

// Funded namespace: refused without consent.
$storage = new WalletStorage(Database::getDbPath(), 'https://mint-b.example', 'sat');
$storage->storeProofs([new Proof('00ad268c4d1f5826', 64, 'secret-x', '02' . str_repeat('55', 32))]);
assert_true(StoreSafety::namespaceHasData('https://mint-b.example', 'sat'), 'namespace holds data');
expect_refused(fn() => Config::updateStore('s1', ['mint_url' => 'https://mint-c.example']), 'still holds', 'mint change on funded namespace');
expect_refused(fn() => Config::updateStore('s1', ['mint_unit' => 'usd']), 'still holds', 'unit change on funded namespace');
assert_eq('https://mint-b.example', store('s1')['mint_url'], 'mint unchanged');
assert_eq('sat', store('s1')['mint_unit'], 'unit unchanged');
Config::updateStore('s1', ['mint_url' => 'https://mint-b.example/']); // same mint, trailing slash: not a change
assert_eq('https://mint-b.example/', store('s1')['mint_url'], 'same-mint write passes');

// With consent the change goes through; the old namespace keeps its proofs.
Config::updateStore('s1', ['mint_url' => 'https://mint-c.example'], true);
assert_eq('https://mint-c.example', store('s1')['mint_url'], 'allowMintChange lets it through');
assert_eq(64, $storage->getBalance(), 'old namespace still holds the proofs (recoverable)');

// Journals and counters count as data too.
$sc = new WalletStorage(Database::getDbPath(), 'https://mint-c.example', 'sat');
$sc->savePendingOperation('melt:q', 'melt', ['input_secrets' => ['x']]);
expect_refused(fn() => Config::updateStore('s1', ['mint_url' => 'https://mint-d.example']), 'still holds', 'journal blocks the change');
$sc->deletePendingOperation('melt:q');
Config::updateStore('s1', ['mint_url' => 'https://mint-d.example']);
assert_eq('https://mint-d.example', store('s1')['mint_url'], 'free again once the journal is gone');

// --- getStoreWalletAccounts ----------------------------------------------------------
add_backup_mint('s1', 'https://backup-1.example', 10);
add_backup_mint('s1', 'https://backup-2.example/', 20);
Database::update('store_mints', ['enabled' => 0], 'mint_url = ?', ['https://backup-2.example']);
add_backup_mint('s1', 'https://mint-d.example', 30); // duplicate of the primary
$accounts = Config::getStoreWalletAccounts('s1');
assert_eq(3, count($accounts), 'primary + two distinct backups: ' . json_encode($accounts));
assert_eq(true, $accounts[0]['primary'], 'primary first');
assert_eq('https://mint-d.example', $accounts[0]['mint_url'], 'primary url');
assert_eq(false, $accounts[2]['enabled'], 'disabled backup listed (it may hold recovery state)');
assert_true(StoreSafety::isMintRetainedAsBackup('s1', 'https://backup-2.example', 'sat'), 'disabled backup counts as retained');
assert_false(StoreSafety::isMintRetainedAsBackup('s1', 'https://mint-z.example', 'sat'), 'unknown mint is not retained');

fwrite(STDERR, "test_updatestore_seed_immutable: all assertions passed\n");
