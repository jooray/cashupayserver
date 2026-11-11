<?php
/**
 * Customer list aggregation. A "customer" is a distinct email address that was
 * captured on the payment-complete screen of at least one settled invoice. The
 * list groups invoices by email (case-insensitive) and surfaces, per customer,
 * their most-recent paid invoice plus their newsletter opt-in (most-recent
 * choice wins). Everything — the admin list, its page count, and the CSV export
 * — is built from Customers::baseQuery() so the three never diverge.
 *
 * Kept out of admin.php so the SQL is unit-testable without the SPA's
 * session/routing side effects.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

class Customers {
    /**
     * Build the base SELECT producing one row per distinct customer email,
     * carrying that email's most-recent PAID invoice (scoped to a store when
     * $storeId is given) and its newsletter opt-in. Returns [sql, params];
     * callers append ORDER/LIMIT, wrap in COUNT(*), or stream for CSV.
     *
     * Exposed columns: email, invoice_id, store_id, paid_ts, newsletter_opt_in.
     *
     * @param string|null $storeId   Restrict to a single store, or null for all.
     * @param string|null $subFilter 'subscribed' | 'unsubscribed' | null (all).
     * @return array{0:string,1:array}
     */
    public static function baseQuery(?string $storeId, ?string $subFilter): array {
        $params = [];
        // Only settled invoices with a captured email are "customers". TRIM
        // guards against a stray empty string slipping past the NOT NULL check.
        $scope = "status = 'Settled' AND customer_email IS NOT NULL AND TRIM(customer_email) != ''";
        if ($storeId !== null && $storeId !== '') {
            $scope .= " AND store_id = ?";
            $params[] = $storeId;
        }

        // ROW_NUMBER picks the single most-recent invoice per email; LOWER()
        // folds case so "A@x" and "a@x" are one customer. paid_ts falls back to
        // created_at for any settled row missing paid_at (legacy / Greenfield
        // mark-paid path).
        $sql = "SELECT email, invoice_id, store_id, paid_ts, newsletter_opt_in FROM (
                    SELECT customer_email AS email,
                           id AS invoice_id,
                           store_id,
                           COALESCE(paid_at, created_at) AS paid_ts,
                           newsletter_opt_in,
                           ROW_NUMBER() OVER (
                               PARTITION BY LOWER(customer_email)
                               ORDER BY COALESCE(paid_at, created_at) DESC, id DESC
                           ) AS rn
                    FROM invoices
                    WHERE {$scope}
                ) ranked
                WHERE rn = 1";

        if ($subFilter === 'subscribed') {
            $sql .= " AND COALESCE(newsletter_opt_in, 0) = 1";
        } elseif ($subFilter === 'unsubscribed') {
            $sql .= " AND COALESCE(newsletter_opt_in, 0) = 0";
        }

        return [$sql, $params];
    }

    /**
     * Normalize the request params shared by the JSON list and the CSV export:
     * the store filter (the SPA sends '__all__' or omits it for all stores) and
     * the subscription filter (whitelisted). Returns [storeId|null, subFilter|null].
     *
     * @return array{0:?string,1:?string}
     */
    public static function filterArgs(array $get): array {
        $storeId = $get['store_id'] ?? null;
        if ($storeId === '__all__' || $storeId === '') {
            $storeId = null;
        }
        $subFilter = $get['subscription'] ?? null;
        if (!in_array($subFilter, ['subscribed', 'unsubscribed'], true)) {
            $subFilter = null;
        }
        return [$storeId, $subFilter];
    }

    /**
     * Total number of distinct customers matching the filters (page count).
     */
    public static function count(?string $storeId, ?string $subFilter): int {
        [$base, $params] = self::baseQuery($storeId, $subFilter);
        $row = Database::fetchOne("SELECT COUNT(*) AS c FROM ({$base}) sub", $params);
        return (int)($row['c'] ?? 0);
    }

    /**
     * A page of customers, most-recently-paid first.
     */
    public static function page(?string $storeId, ?string $subFilter, int $limit, int $offset): array {
        [$base, $params] = self::baseQuery($storeId, $subFilter);
        return Database::fetchAll(
            $base . " ORDER BY paid_ts DESC, email ASC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }
}
