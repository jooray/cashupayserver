<?php
/**
 * Product catalog + cart backend.
 *
 * Hermetic coverage (no network, no mint):
 *   - Product create/validate/update/delete + per-store scoping
 *   - catalog sort ordering (most_purchased / newest / title / price)
 *   - image reference validation (emoji + uploaded-filename allowlist)
 *   - Cart::priceItems sats math for a sat-denominated store (product lines,
 *     custom lines, quantity bounds, disabled products)
 *   - Cart::reconcileSettledCounts: increments purchase_count once a cart
 *     invoice is Settled, exactly once (idempotent), custom lines ignored
 *
 * The full checkout() path (which calls Invoice::create and needs a live mint)
 * is exercised by the iterate.py stack + the Playwright e2e test.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/products.php';
require_once dirname(__DIR__, 2) . '/app/includes/cart.php';

make_store('s1');
make_store('s2');

// ---------------- Product CRUD + validation ----------------

$p = Product::create('s1', ['title' => 'Coffee', 'price' => '1000']);
assert_eq('1000', $p['price'], 'sat price normalized');
assert_eq('sat', $p['currency'], 'currency snapshot = store default');
assert_eq(1, (int)$p['enabled'], 'enabled by default');

// Validation failures.
$threw = false;
try { Product::create('s1', ['title' => '', 'price' => '10']); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'empty title rejected');

$threw = false;
try { Product::create('s1', ['title' => 'X', 'price' => '0']); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'zero price rejected');

$threw = false;
try { Product::create('s1', ['title' => 'X', 'price' => 'abc']); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'non-numeric price rejected');

$threw = false;
try { Product::create('s1', ['title' => 'X', 'price' => '1.5']); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'fractional sat price rejected');

// Emoji + upload image references.
$pe = Product::create('s1', ['title' => 'Tea', 'price' => '500', 'image_type' => 'emoji', 'image_value' => "\u{2615}"]);
assert_eq('emoji', $pe['image_type'], 'emoji image stored');
assert_eq("\u{2615}", $pe['image_value'], 'emoji value stored');

assert_true(Product::isValidImageFilename('prod_0123456789ab.png'), 'valid upload filename');
assert_true(!Product::isValidImageFilename('../../etc/passwd'), 'path traversal rejected');
assert_true(!Product::isValidImageFilename('prod_xx.gif'), 'disallowed ext rejected');
$threw = false;
try { Product::normalizeImage('upload', '../evil.png'); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'normalizeImage rejects bad upload ref');

// Per-store scoping: s1's product is invisible to s2.
assert_null(Product::get($p['id'], 's2'), 'product scoped to its store');
assert_not_null(Product::get($p['id'], 's1'), 'product visible to its store');

// Update: title, price, disable.
$p2 = Product::update($p['id'], 's1', ['title' => 'Espresso', 'price' => '1200', 'enabled' => false]);
assert_eq('Espresso', $p2['title'], 'title updated');
assert_eq('1200', $p2['price'], 'price updated');
assert_eq(0, (int)$p2['enabled'], 'disabled');

// listByStore onlyEnabled drops the disabled product.
$enabled = Product::listByStore('s1', null, true);
$enabledIds = array_column($enabled, 'id');
assert_true(!in_array($p['id'], $enabledIds, true), 'disabled product excluded from catalog');
assert_true(in_array($pe['id'], $enabledIds, true), 'enabled product in catalog');

// ---------------- Sort ordering ----------------

make_store('s3');
$a = Product::create('s3', ['title' => 'Banana', 'price' => '300']);
$b = Product::create('s3', ['title' => 'apple', 'price' => '100']);
$c = Product::create('s3', ['title' => 'Cherry', 'price' => '200']);
// Give them purchase counts directly for the most_purchased sort.
Database::update('products', ['purchase_count' => 5], 'id = ?', [$b['id']]);
Database::update('products', ['purchase_count' => 9], 'id = ?', [$c['id']]);

$byPurchased = array_column(Product::listByStore('s3', 'most_purchased'), 'id');
assert_eq($c['id'], $byPurchased[0], 'most_purchased: highest count first');

$byTitle = array_column(Product::listByStore('s3', 'title_asc'), 'title');
assert_eq(['apple', 'Banana', 'Cherry'], $byTitle, 'title_asc case-insensitive');

$byPriceAsc = array_column(Product::listByStore('s3', 'price_asc'), 'price');
assert_eq(['100', '200', '300'], $byPriceAsc, 'price_asc');

$byPriceDesc = array_column(Product::listByStore('s3', 'price_desc'), 'price');
assert_eq(['300', '200', '100'], $byPriceDesc, 'price_desc');

// Per-store sort setting round-trips and is validated.
Product::setStoreSort('s3', 'newest');
assert_eq('newest', Product::storeSort('s3'), 'store sort saved');
Product::setStoreSort('s3', 'garbage');
assert_eq('most_purchased', Product::storeSort('s3'), 'invalid sort normalizes to default');

// ---------------- Cart pricing (sat store, hermetic) ----------------

$prod = Product::create('s1', ['title' => 'Widget', 'price' => '1000']);
$priced = Cart::priceItems('s1', [['product_id' => $prod['id'], 'quantity' => 3]]);
assert_eq(3000, $priced['totalSats'], 'product line total sats');
assert_eq(3000, $priced['lines'][0]['amount_sats'], 'product line amount_sats');
assert_null($priced['lines'][0]['display_amount'], 'sat store: no fiat parenthetical');
assert_eq('sat', $priced['lines'][0]['display_currency'], 'display currency sat');

// Custom line item.
$priced2 = Cart::priceItems('s1', [['title' => 'Tip', 'price' => '500', 'currency' => 'sat', 'quantity' => 2]]);
assert_eq(1000, $priced2['totalSats'], 'custom line total sats');
assert_null($priced2['lines'][0]['product_id'], 'custom line has no product_id');

// Mixed cart sums.
$priced3 = Cart::priceItems('s1', [
    ['product_id' => $prod['id'], 'quantity' => 1],
    ['title' => 'Extra', 'price' => '250', 'currency' => 'sat', 'quantity' => 1],
]);
assert_eq(1250, $priced3['totalSats'], 'mixed cart total');

// Quantity bounds + disabled product.
$threw = false;
try { Cart::priceItems('s1', [['product_id' => $prod['id'], 'quantity' => 0]]); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'quantity 0 rejected');
$threw = false;
try { Cart::priceItems('s1', [['product_id' => $prod['id'], 'quantity' => 1000]]); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'quantity over max rejected');
$threw = false;
try { Cart::priceItems('s1', [['product_id' => $p['id'], 'quantity' => 1]]); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'disabled product rejected at checkout');

// ---------------- Settle-time purchase-count reconciliation ----------------

$counted = Product::create('s1', ['title' => 'Sticker', 'price' => '100']);
$now = time();
Database::insert('invoices', [
    'id' => 'inv_test1',
    'store_id' => 's1',
    'status' => 'New',
    'amount' => '300',
    'currency' => 'sat',
    'created_at' => $now,
    'expiration_time' => $now + 3600,
]);
Database::insert('invoice_items', [
    'id' => 'item_a', 'invoice_id' => 'inv_test1', 'store_id' => 's1',
    'product_id' => $counted['id'], 'title' => 'Sticker', 'unit_price' => '100',
    'unit_currency' => 'sat', 'quantity' => 2, 'amount_sats' => 200, 'created_at' => $now,
]);
Database::insert('invoice_items', [
    'id' => 'item_b', 'invoice_id' => 'inv_test1', 'store_id' => 's1',
    'product_id' => null, 'title' => 'Custom', 'unit_price' => '100',
    'unit_currency' => 'sat', 'quantity' => 1, 'amount_sats' => 100, 'created_at' => $now,
]);

// Not settled yet → no counting.
assert_eq(0, Cart::reconcileSettledCounts(), 'no Settled invoices to count');
assert_eq(0, (int)Product::get($counted['id'], 's1')['purchase_count'], 'count unchanged while New');

// Settle it → counted once.
Database::update('invoices', ['status' => 'Settled'], 'id = ?', ['inv_test1']);
assert_eq(1, Cart::reconcileSettledCounts(), 'one invoice counted');
assert_eq(2, (int)Product::get($counted['id'], 's1')['purchase_count'], 'purchase_count += line qty');

// Idempotent: a second sweep does nothing.
assert_eq(0, Cart::reconcileSettledCounts(), 'idempotent: already counted');
assert_eq(2, (int)Product::get($counted['id'], 's1')['purchase_count'], 'count stable on re-run');

// getItems returns both lines.
assert_eq(2, count(Cart::getItems('inv_test1')), 'getItems returns all line items');

// Delete nulls the product_id on line items but keeps the snapshot title.
Product::delete($counted['id'], 's1');
assert_null(Product::get($counted['id'], 's1'), 'product deleted');
$items = Cart::getItems('inv_test1');
$itemA = null;
foreach ($items as $it) { if ($it['id'] === 'item_a') { $itemA = $it; } }
assert_not_null($itemA, 'line item survives product delete');
assert_null($itemA['product_id'], 'product_id nulled on delete (SET NULL)');
assert_eq('Sticker', $itemA['title'], 'snapshot title preserved');

echo "ok\n";
