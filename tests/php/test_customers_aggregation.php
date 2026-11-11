<?php
/**
 * Customer list aggregation (Customers::baseQuery/count/page):
 *   - one row per distinct email, case-insensitive
 *   - "most recent invoice" + newsletter status follow the query scope
 *     (all stores vs a single store), most-recent choice wins
 *   - only settled invoices with a captured email count
 *   - store + subscription filters and pagination
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/customers.php';

make_store('s1');
make_store('s2');

function mkinv(string $id, string $store, ?string $email, ?int $nl, int $t, string $status = 'Settled'): void {
    Database::insert('invoices', [
        'id' => $id,
        'store_id' => $store,
        'status' => $status,
        'amount' => '10',
        'currency' => 'sat',
        'created_at' => $t,
        'expiration_time' => $t + 3600,
        'paid_at' => $status === 'Settled' ? $t : null,
        'customer_email' => $email,
        'newsletter_opt_in' => $nl,
    ]);
}

// alice pays twice in s1: the later one (t=200) is a case variant and opts OUT.
mkinv('i1', 's1', 'alice@x.com', 1, 100);
mkinv('i2', 's1', 'Alice@x.com', 0, 200);
// bob once in s1, subscribed.
mkinv('i3', 's1', 'bob@x.com', 1, 150);
// alice also pays in s2 at t=300 (most recent overall), subscribed.
mkinv('i4', 's2', 'alice@x.com', 1, 300);
// carol's invoice is unsettled → excluded entirely.
mkinv('i5', 's1', 'carol@x.com', 1, 120, 'New');
// settled invoice with no captured email → excluded.
mkinv('i6', 's1', null, null, 130);

// ---- All stores -----------------------------------------------------------
assert_eq(2, Customers::count(null, null), 'two distinct customers across all stores');

$all = Customers::page(null, null, 50, 0);
assert_eq(2, count($all), 'page returns two rows');
// Ordered most-recent-paid first → alice's s2 invoice (t=300) leads.
assert_eq('i4', $all[0]['invoice_id'], 'most recent customer first');

$byEmail = [];
foreach ($all as $r) { $byEmail[strtolower($r['email'])] = $r; }
// alice folds across case + stores into one row, carrying her latest (i4).
assert_eq('i4', $byEmail['alice@x.com']['invoice_id'], 'alice latest = i4 (s2)');
assert_eq('s2', $byEmail['alice@x.com']['store_id'], 'alice latest store = s2');
assert_eq(1, (int)$byEmail['alice@x.com']['newsletter_opt_in'], 'alice newsletter follows i4');
assert_eq('i3', $byEmail['bob@x.com']['invoice_id'], 'bob latest = i3');

// ---- Scoped to s1 ---------------------------------------------------------
// Within s1 alice's latest is i2 (t=200, opted out); bob unchanged.
assert_eq(2, Customers::count('s1', null), 'two customers in s1');
$s1 = Customers::page('s1', null, 50, 0);
$byE1 = [];
foreach ($s1 as $r) { $byE1[strtolower($r['email'])] = $r; }
assert_eq('i2', $byE1['alice@x.com']['invoice_id'], 'alice latest in s1 = i2');
assert_eq(0, (int)$byE1['alice@x.com']['newsletter_opt_in'], 'alice opted out in s1 scope');

// ---- Subscription filter (all stores) -------------------------------------
// Latest-wins: alice(i4 sub) + bob(i3 sub) are both subscribed.
assert_eq(2, Customers::count(null, 'subscribed'), 'both subscribed across all stores');
assert_eq(0, Customers::count(null, 'unsubscribed'), 'none unsubscribed across all stores');

// ---- Subscription filter scoped to s1 -------------------------------------
// In s1: alice latest (i2) is unsubscribed; bob subscribed.
assert_eq(1, Customers::count('s1', 'subscribed'), 'bob subscribed in s1');
assert_eq(1, Customers::count('s1', 'unsubscribed'), 'alice unsubscribed in s1');
$s1Unsub = Customers::page('s1', 'unsubscribed', 50, 0);
assert_eq(1, count($s1Unsub), 'one unsubscribed row in s1');
assert_eq('alice@x.com', strtolower($s1Unsub[0]['email']), 'unsubscribed row is alice');

// ---- Pagination -----------------------------------------------------------
assert_eq(1, count(Customers::page(null, null, 1, 0)), 'page size 1, first page');
assert_eq(1, count(Customers::page(null, null, 1, 1)), 'page size 1, second page');
assert_eq(0, count(Customers::page(null, null, 1, 2)), 'page size 1, past the end');

echo "test_customers_aggregation: ok\n";
