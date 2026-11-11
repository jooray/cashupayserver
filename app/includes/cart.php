<?php
/**
 * CashuPayServer - Shopping cart checkout
 *
 * Turns a cart (product lines + optional custom line items) into a single
 * SATS-denominated invoice. Each line is converted to sats server-side using
 * the store's exchange fee + price providers (the same path normal fiat
 * invoices use), so mixed-currency carts simply sum in sats. The per-line
 * snapshots are persisted in invoice_items for the checkout receipt and for
 * the settle-time purchase-count reconciliation.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/urls.php';
require_once __DIR__ . '/security.php';

class Cart {
    public const MAX_QTY = 999;
    public const MAX_ITEMS = 100;

    /**
     * Price a cart into per-line sats snapshots + a sats total, WITHOUT
     * creating an invoice. Pure of side effects (only reads products + rates),
     * which keeps the money math unit-testable. Returns
     * ['lines'=>[...], 'totalSats'=>int].
     */
    public static function priceItems(string $storeId, array $items): array {
        $store = Config::getStore($storeId);
        if ($store === null) {
            throw new InvalidArgumentException('Unknown store');
        }
        if (empty($items)) {
            throw new InvalidArgumentException('Cart is empty');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Too many items in cart');
        }

        $exchangeFee = (float)($store['exchange_fee_percent'] ?? 0);
        $primary = $store['price_provider_primary'] ?? 'coingecko';
        $secondary = $store['price_provider_secondary'] ?? 'binance';
        $storeCurrency = strtoupper((string)($store['default_currency'] ?? 'sat'));
        $storeShowsSats = in_array($storeCurrency, ['SAT', 'SATS'], true);

        $lines = [];
        $totalSats = 0;
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 0);
            if ($qty < 1 || $qty > self::MAX_QTY) {
                throw new InvalidArgumentException('Invalid quantity');
            }

            $productId = (isset($item['product_id']) && $item['product_id'] !== '')
                ? (string)$item['product_id'] : null;

            if ($productId !== null) {
                $p = Product::get($productId, $storeId);
                if ($p === null) {
                    throw new InvalidArgumentException('Product not found');
                }
                if ((int)$p['enabled'] !== 1) {
                    throw new InvalidArgumentException('Product is not available: ' . $p['title']);
                }
                $title = (string)$p['title'];
                $unitPrice = (string)$p['price'];
                $unitCurrency = (string)$p['currency'];
                $imageType = (string)$p['image_type'];
                $imageValue = $p['image_value'];
            } else {
                // Custom one-off line item entered in the cart.
                $title = trim((string)($item['title'] ?? 'Custom item'));
                if ($title === '') {
                    $title = 'Custom item';
                }
                $unitCurrency = strtoupper((string)($item['currency'] ?? 'sat'));
                $unitPrice = Product::normalizePrice((string)($item['price'] ?? ''), $unitCurrency);
                $imageType = 'none';
                $imageValue = null;
            }

            // Per-unit sats, applying the store's exchange fee + providers.
            // For sat-priced lines this is the identity.
            $unitSats = ExchangeRates::convertToMintUnit(
                (string)$unitPrice, $unitCurrency, 'sat', $exchangeFee, $primary, $secondary
            );
            if ($unitSats <= 0) {
                throw new InvalidArgumentException('Could not price item: ' . $title);
            }
            $lineSats = $unitSats * $qty;
            $totalSats += $lineSats;

            // Store-currency equivalent shown in parentheses on checkout. Null
            // when the store already displays sats (no redundant parenthetical).
            $displayAmount = $storeShowsSats ? null : ExchangeRates::satsToFiat($lineSats, $storeCurrency);

            $lines[] = [
                'product_id' => $productId,
                'title' => $title,
                'unit_price' => $unitPrice,
                'unit_currency' => $unitCurrency,
                'quantity' => $qty,
                'amount_sats' => $lineSats,
                'display_amount' => $displayAmount,
                'display_currency' => $storeShowsSats ? 'sat' : $storeCurrency,
                'image_type' => $imageType,
                'image_value' => $imageValue,
            ];
        }

        if ($totalSats <= 0) {
            throw new InvalidArgumentException('Cart total is zero');
        }

        return ['lines' => $lines, 'totalSats' => $totalSats];
    }

    /**
     * @param array       $items    each: ['product_id'=>?string, 'quantity'=>int]
     *                              or a custom line: ['title','price','currency','quantity']
     * @param string|null $memo     optional note stored on the invoice
     * @param string|null $redirect optional checkout redirect URL
     * @param array       $privacy  optional per-invoice memo-privacy overrides:
     *                              ['hideStoreName'=>bool, 'hideNote'=>bool].
     *                              Omitted keys fall back to the store defaults.
     * @return array ['invoiceId','checkoutLink','amountSats']
     */
    public static function checkout(string $storeId, array $items, ?string $memo = null, ?string $redirect = null, array $privacy = [], bool $longLived = false): array {
        $priced = self::priceItems($storeId, $items);
        $lines = $priced['lines'];
        $totalSats = $priced['totalSats'];

        // Create the invoice in sats. The existing multi-rail logic handles it
        // exactly like a normal sat-denominated request.
        $metadata = [
            'itemDesc' => ($memo !== null && $memo !== '') ? $memo : (count($lines) . ' item(s)'),
            'cart' => true,
        ];
        // Per-invoice memo-privacy overrides (win over the store defaults when
        // present; see Invoice::buildInvoiceMemo).
        if (array_key_exists('hideStoreName', $privacy)) {
            $metadata['hideStoreName'] = (bool)$privacy['hideStoreName'];
        }
        if (array_key_exists('hideNote', $privacy)) {
            $metadata['hideNote'] = (bool)$privacy['hideNote'];
        }
        $options = ['amount' => (string)$totalSats, 'currency' => 'sat', 'metadata' => $metadata];
        if ($longLived) {
            // Admin cart / self-serve product link: a shareable payment
            // request, not a checkout window (see Config::getSharedInvoiceLifetime).
            $options['lifetime_sec'] = Config::getSharedInvoiceLifetime();
            $options['long_lived'] = true;
        }
        if ($redirect !== null && $redirect !== '') {
            // The redirect is rendered as an <a href> on the public payment
            // page; an unvalidated value lets a javascript:/data: URL execute
            // when the payer clicks "Continue to Store". Enforce http(s) only,
            // mirroring the Greenfield API path (includes/api/invoices.php).
            $safeRedirect = Security::sanitizeUrl($redirect);
            if ($safeRedirect === null) {
                throw new InvalidArgumentException('Invalid checkout redirect URL');
            }
            $options['checkout'] = ['redirectURL' => $safeRedirect, 'redirectAutomatically' => true];
        }

        $invoice = Invoice::create($storeId, $options);
        $invoiceId = (string)$invoice['id'];

        // Persist the line items (snapshots survive product edits/deletes).
        $now = time();
        foreach ($lines as $ln) {
            Database::insert('invoice_items', [
                'id' => 'item_' . bin2hex(random_bytes(10)),
                'invoice_id' => $invoiceId,
                'store_id' => $storeId,
                'product_id' => $ln['product_id'],
                'title' => $ln['title'],
                'unit_price' => $ln['unit_price'],
                'unit_currency' => $ln['unit_currency'],
                'quantity' => $ln['quantity'],
                'amount_sats' => $ln['amount_sats'],
                'display_amount' => $ln['display_amount'],
                'display_currency' => $ln['display_currency'],
                'image_type' => $ln['image_type'],
                'image_value' => $ln['image_value'],
                'created_at' => $now,
            ]);
        }

        return [
            'invoiceId' => $invoiceId,
            'checkoutLink' => Urls::payment($invoiceId),
            'amountSats' => $totalSats,
        ];
    }

    /**
     * Raw line-item rows for an invoice, in insertion order. Ids are random
     * and every item of one checkout shares the same created_at second, so
     * rowid (SQLite's insertion counter) is the only stable tiebreaker — an
     * `id ASC` tiebreak shuffled the receipt (tip before product) at random.
     */
    public static function getItems(string $invoiceId): array {
        return Database::fetchAll(
            'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY created_at ASC, rowid ASC',
            [$invoiceId]
        );
    }

    /** True if the invoice has any cart line items. */
    public static function hasItems(string $invoiceId): bool {
        $row = Database::fetchOne(
            'SELECT 1 AS x FROM invoice_items WHERE invoice_id = ? LIMIT 1',
            [$invoiceId]
        );
        return $row !== null;
    }

    public static function formatItemsForApi(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $imageType = (string)($r['image_type'] ?? 'none');
            $imageValue = $r['image_value'] ?? null;
            $out[] = [
                'productId' => $r['product_id'] ?? null,
                'title' => $r['title'],
                'unitPrice' => $r['unit_price'],
                'unitCurrency' => $r['unit_currency'],
                'quantity' => (int)$r['quantity'],
                'amountSats' => (int)$r['amount_sats'],
                'displayAmount' => $r['display_amount'] ?? null,
                'displayCurrency' => $r['display_currency'] ?? null,
                'imageType' => $imageType,
                'imageValue' => $imageValue,
                'imageUrl' => ($imageType === 'upload' && $imageValue) ? Product::imageUrl((string)$imageValue) : null,
            ];
        }
        return $out;
    }

    /**
     * Settle-time purchase-count reconciliation. Settlement happens on several
     * rails that don't share a single choke-point, so rather than hook each
     * one we sweep here (called from cron): for every Settled cart invoice not
     * yet counted, bump each linked product's purchase_count by the line
     * quantity and mark the invoice counted. The cart_purchase_counted flag
     * makes this exactly-once and idempotent. Returns the number of invoices
     * newly counted.
     */
    public static function reconcileSettledCounts(): int {
        $rows = Database::fetchAll(
            "SELECT id FROM invoices
             WHERE status = 'Settled' AND cart_purchase_counted = 0
               AND id IN (SELECT DISTINCT invoice_id FROM invoice_items)"
        );
        $counted = 0;
        foreach ($rows as $row) {
            $invId = (string)$row['id'];
            try {
                Database::beginTransaction();
                // Claim the invoice FIRST with a conditional flip, and only
                // apply the increments when this pass is the one that won the
                // 0 -> 1 transition. The outer SELECT is not a lock, so two
                // reconcile passes (cron overlapping a retry, or two cron
                // invocations) can both see cart_purchase_counted = 0; without
                // this gate both would bump purchase_count. The CAS is the
                // first (write) statement, so the transaction takes the write
                // lock immediately — no read-before-write upgrade window.
                $claimed = Database::update(
                    'invoices',
                    ['cart_purchase_counted' => 1],
                    'id = ? AND cart_purchase_counted = 0',
                    [$invId]
                );
                if ($claimed !== 1) {
                    Database::rollback();
                    continue; // another pass already counted this invoice
                }
                $items = Database::fetchAll(
                    'SELECT product_id, quantity FROM invoice_items WHERE invoice_id = ?',
                    [$invId]
                );
                foreach ($items as $it) {
                    if (!empty($it['product_id'])) {
                        Database::query(
                            'UPDATE products SET purchase_count = purchase_count + ? WHERE id = ?',
                            [(int)$it['quantity'], $it['product_id']]
                        );
                    }
                }
                Database::commit();
                $counted++;
            } catch (Throwable $e) {
                Database::rollback();
                error_log('[cart] purchase-count reconcile failed for ' . $invId . ': ' . $e->getMessage());
            }
        }
        return $counted;
    }
}
