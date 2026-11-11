<?php
/**
 * CashuPayServer - Product catalog
 *
 * Per-store products for the shopping-cart request flow. A product's price is
 * stored as a decimal string in its own `currency` (a snapshot of the store's
 * display currency at creation: 'sat' or a fiat code). Mixed-currency products
 * within one store are allowed — everything converts to sats at checkout (see
 * Cart::checkout), so changing a store's display currency never invalidates
 * existing products.
 *
 * Product management (create/update/delete/enable) is admin-only; the catalog
 * listing is available to any logged-in user (the request modal). Auth is
 * enforced by the callers in admin.php, not here.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Product {
    /** Valid catalog sort keys. The first is the global default. */
    public const SORTS = ['most_purchased', 'newest', 'title_asc', 'price_asc', 'price_desc'];
    public const DEFAULT_SORT = 'most_purchased';
    public const MAX_TITLE_LEN = 200;
    public const DEFAULT_EMOJI = "\u{1F4E6}"; // 📦 — shown when no image/emoji is set

    /** Where uploaded product images live (outside the web root, under data/). */
    public static function uploadsDir(): string {
        return Database::getDataDir() . '/uploads/products';
    }

    public static function normalizeSort(?string $sort): string {
        return in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    /** Per-store default catalog sort (stores.product_sort). */
    public static function storeSort(string $storeId): string {
        $store = Config::getStore($storeId);
        return self::normalizeSort($store['product_sort'] ?? self::DEFAULT_SORT);
    }

    public static function setStoreSort(string $storeId, string $sort): void {
        Database::update('stores', ['product_sort' => self::normalizeSort($sort)], 'id = ?', [$storeId]);
    }

    /**
     * Validate + canonicalize a price for a given currency. Sats are whole
     * numbers; fiat is fixed to 2 decimals. Throws on non-positive / non-numeric.
     */
    public static function normalizePrice(string $price, string $currency): string {
        $price = trim($price);
        if ($price === '' || !is_numeric($price)) {
            throw new InvalidArgumentException('Price must be a number');
        }
        $val = (float)$price;
        if ($val <= 0) {
            throw new InvalidArgumentException('Price must be greater than zero');
        }
        $cur = strtoupper($currency);
        if ($cur === 'SAT' || $cur === 'SATS') {
            if (floor($val) != $val) {
                throw new InvalidArgumentException('Sat prices must be whole numbers');
            }
            return (string)(int)round($val);
        }
        return number_format($val, 2, '.', '');
    }

    /**
     * Normalize an (image_type, image_value) pair to a stored shape.
     * Returns ['none'|'emoji'|'upload', value|null]. Uploaded filenames must
     * match the strict pattern minted by the upload handler — this is the
     * single gate that keeps arbitrary paths out of the image columns.
     */
    public static function normalizeImage($type, $value): array {
        $type = is_string($type) ? $type : 'none';
        if ($type === 'emoji') {
            $emoji = trim((string)$value);
            if ($emoji === '') {
                return ['none', null];
            }
            // Allow multi-codepoint emoji (ZWJ sequences, skin tones) but cap
            // length so the column can't be abused to store arbitrary text.
            if (strlen($emoji) > 32) {
                throw new InvalidArgumentException('Emoji is too long');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $emoji)) {
                throw new InvalidArgumentException('Invalid emoji');
            }
            return ['emoji', $emoji];
        }
        if ($type === 'upload') {
            $fn = basename((string)$value);
            if (!self::isValidImageFilename($fn)) {
                throw new InvalidArgumentException('Invalid image reference');
            }
            return ['upload', $fn];
        }
        return ['none', null];
    }

    public static function isValidImageFilename(string $fn): bool {
        return (bool)preg_match('/^prod_[a-f0-9]{12,}\.(png|jpg|jpeg|webp)$/', $fn);
    }

    // ---------------- CRUD ----------------

    public static function create(string $storeId, array $d): array {
        $store = Config::getStore($storeId);
        if ($store === null) {
            throw new InvalidArgumentException('Unknown store');
        }
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Title is required');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LEN) {
            throw new InvalidArgumentException('Title is too long');
        }
        // Snapshot the store's current display currency as the product currency.
        $currency = (string)($store['default_currency'] ?? 'sat');
        $price = self::normalizePrice((string)($d['price'] ?? ''), $currency);
        [$imageType, $imageValue] = self::normalizeImage($d['image_type'] ?? 'none', $d['image_value'] ?? null);

        $id = 'prod_' . bin2hex(random_bytes(12));
        $now = time();
        Database::insert('products', [
            'id' => $id,
            'store_id' => $storeId,
            'title' => $title,
            'price' => $price,
            'currency' => $currency,
            'image_type' => $imageType,
            'image_value' => $imageValue,
            'purchase_count' => 0,
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::get($id, $storeId);
    }

    public static function update(string $id, string $storeId, array $d): array {
        $existing = self::get($id, $storeId);
        if ($existing === null) {
            throw new InvalidArgumentException('Product not found');
        }
        $updates = ['updated_at' => time()];

        if (array_key_exists('title', $d)) {
            $title = trim((string)$d['title']);
            if ($title === '') {
                throw new InvalidArgumentException('Title is required');
            }
            if (mb_strlen($title) > self::MAX_TITLE_LEN) {
                throw new InvalidArgumentException('Title is too long');
            }
            $updates['title'] = $title;
        }
        if (array_key_exists('price', $d)) {
            // Price stays in the product's existing currency.
            $updates['price'] = self::normalizePrice((string)$d['price'], (string)$existing['currency']);
        }
        if (array_key_exists('image_type', $d)) {
            [$imageType, $imageValue] = self::normalizeImage($d['image_type'] ?? 'none', $d['image_value'] ?? null);
            // If an uploaded image is being replaced or removed, delete the old file.
            if ($existing['image_type'] === 'upload'
                && $existing['image_value']
                && $existing['image_value'] !== $imageValue) {
                self::deleteImageFile((string)$existing['image_value']);
            }
            $updates['image_type'] = $imageType;
            $updates['image_value'] = $imageValue;
        }
        if (array_key_exists('enabled', $d)) {
            $updates['enabled'] = $d['enabled'] ? 1 : 0;
        }

        Database::update('products', $updates, 'id = ? AND store_id = ?', [$id, $storeId]);
        return self::get($id, $storeId);
    }

    public static function delete(string $id, string $storeId): void {
        $existing = self::get($id, $storeId);
        if ($existing === null) {
            return;
        }
        if ($existing['image_type'] === 'upload' && $existing['image_value']) {
            self::deleteImageFile((string)$existing['image_value']);
        }
        Database::delete('products', 'id = ? AND store_id = ?', [$id, $storeId]);
    }

    public static function get(string $id, string $storeId): ?array {
        return Database::fetchOne(
            'SELECT * FROM products WHERE id = ? AND store_id = ?',
            [$id, $storeId]
        );
    }

    /**
     * List a store's products. $sort defaults to the store's configured sort.
     * $onlyEnabled restricts to the catalog the request modal should show.
     */
    public static function listByStore(string $storeId, ?string $sort = null, bool $onlyEnabled = false): array {
        $sort = self::normalizeSort($sort ?? self::storeSort($storeId));
        $sql = 'SELECT * FROM products WHERE store_id = ?';
        if ($onlyEnabled) {
            $sql .= ' AND enabled = 1';
        }
        $sql .= ' ORDER BY ' . self::orderByClause($sort);
        return Database::fetchAll($sql, [$storeId]);
    }

    private static function orderByClause(string $sort): string {
        switch ($sort) {
            case 'newest':
                return 'created_at DESC, id DESC';
            case 'title_asc':
                return 'title COLLATE NOCASE ASC, id ASC';
            // NOTE: price sorts compare the raw numeric price, ignoring
            // currency. In the common single-currency store this is exact; a
            // store with mixed-currency products (after a currency switch) gets
            // an approximate ordering, which is acceptable for a picker.
            case 'price_asc':
                return 'CAST(price AS REAL) ASC, id ASC';
            case 'price_desc':
                return 'CAST(price AS REAL) DESC, id ASC';
            case 'most_purchased':
            default:
                return 'purchase_count DESC, created_at DESC, id DESC';
        }
    }

    // ---------------- Images ----------------

    public static function deleteImageFile(?string $filename): void {
        if (!$filename) {
            return;
        }
        $fn = basename($filename);
        if (!self::isValidImageFilename($fn)) {
            return;
        }
        $path = self::uploadsDir() . '/' . $fn;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Absolute, base-rooted URL for an uploaded product image. */
    public static function imageUrl(string $filename): string {
        return rtrim(Config::getBaseUrl(), '/') . '/product-image.php?f=' . rawurlencode($filename);
    }

    // ---------------- API shaping ----------------

    public static function formatForApi(array $row): array {
        $imageType = (string)($row['image_type'] ?? 'none');
        $imageValue = $row['image_value'] ?? null;
        $out = [
            'id' => $row['id'],
            'storeId' => $row['store_id'],
            'title' => $row['title'],
            'price' => $row['price'],
            'currency' => $row['currency'],
            'imageType' => $imageType,
            'imageValue' => $imageValue,
            'imageUrl' => ($imageType === 'upload' && $imageValue) ? self::imageUrl((string)$imageValue) : null,
            'purchaseCount' => (int)($row['purchase_count'] ?? 0),
            'enabled' => (int)($row['enabled'] ?? 0) === 1,
            'createdAt' => (int)($row['created_at'] ?? 0),
        ];
        return $out;
    }
}
