<?php
/**
 * Admin-facing event log.
 *
 * Two jobs:
 *
 * 1. A DB home (admin_event_log) for direct-receive endpoint failures —
 *    NWC / noffer / LNURL — which previously only reached error_log().
 *    Rows are written from Invoice::create's destination walk (context
 *    'checkout') and the settlement pollers (context 'poll'). Poll-context
 *    writes are throttled: a dead wallet is re-polled every cron tick, and
 *    one row per identical failure per POLL_THROTTLE_SEC is plenty.
 *
 * 2. A unified read model for the admin "Log" tab: recent() merges the
 *    admin_event_log with the error/event records the rest of the codebase
 *    already keeps — mint reliability events, failed webhook deliveries,
 *    permanently-failed notification emails, and swap/sweep attempt errors —
 *    into one timestamp-ordered feed.
 *
 * Log rows are shown only to a logged-in admin, so they may name the failing
 * destination (LN address, masked NWC label, noffer string) and carry the
 * raw exception message. They must still never contain an NWC connection URI
 * (it embeds the wallet secret) — callers pass NwcUri::displayLabel() only.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class AdminLog {
    /**
     * Site-wide flag: when true, payment.php hides the receive-errors banner
     * (and its JSON mirror) from payers. Recording is unaffected — failures
     * still land in invoices.receive_errors and this log.
     */
    public const SUPPRESS_CONFIG_KEY = 'suppress_receive_errors_on_invoice';

    /** Total row cap for admin_event_log; oldest rows beyond it are pruned. */
    public const ROW_CAP = 1000;

    /** Poll-context writes: skip if an identical row is younger than this. */
    public const POLL_THROTTLE_SEC = 600;

    /** Longest message persisted; exception text can be arbitrarily large. */
    private const MAX_MESSAGE_LEN = 300;

    /** Categories recent() understands (admin_event_log + derived sources). */
    public const CATEGORIES = ['nwc', 'noffer', 'lnurl', 'strike', 'mint', 'webhook', 'email', 'swap', 'sweep'];

    public static function suppressOnInvoice(): bool {
        return Config::get(self::SUPPRESS_CONFIG_KEY, false) === true;
    }

    /**
     * Record one endpoint failure. Never throws — logging must not be able
     * to break checkout or the pollers.
     *
     * @param string $category 'nwc' | 'noffer' | 'lnurl'
     * @param string $context  'checkout' (invoice creation) | 'poll' (settlement)
     * @param string|null $label safe destination identity (LN address, masked
     *                           NWC label, noffer string) — never a raw NWC URI
     */
    public static function log(
        string $category,
        string $context,
        ?string $storeId,
        ?string $invoiceId,
        ?string $label,
        string $message
    ): void {
        try {
            if (function_exists('mb_substr')) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LEN);
            } else {
                $message = substr($message, 0, self::MAX_MESSAGE_LEN);
            }
            if ($context === 'poll') {
                $recent = Database::fetchOne(
                    "SELECT id FROM admin_event_log
                      WHERE category = ? AND COALESCE(invoice_id, '') = ?
                        AND message = ? AND timestamp > ?
                      LIMIT 1",
                    [$category, (string)$invoiceId, $message, time() - self::POLL_THROTTLE_SEC]
                );
                if ($recent) {
                    return;
                }
            }
            Database::insert('admin_event_log', [
                'timestamp' => Database::timestamp(),
                'category' => $category,
                'context' => $context,
                'store_id' => $storeId,
                'invoice_id' => $invoiceId,
                'label' => $label,
                'message' => $message,
            ]);
            self::prune();
        } catch (Throwable $e) {
            error_log('[admin-log] failed to record event: ' . $e->getMessage());
        }
    }

    /**
     * Drop oldest rows beyond ROW_CAP. Same delete-by-id-subquery idiom as
     * MintReliability::pruneLog (SQLite has no DELETE … ORDER BY LIMIT).
     */
    private static function prune(): void {
        Database::query(
            "DELETE FROM admin_event_log
             WHERE id IN (
                 SELECT id FROM admin_event_log
                 ORDER BY timestamp DESC, id DESC
                 LIMIT -1 OFFSET ?
             )",
            [self::ROW_CAP]
        );
    }

    /**
     * Unified recent-issues feed for the admin Log tab: admin_event_log rows
     * merged with the existing error/event tables, normalized to
     * {timestamp, category, storeId, invoiceId, ref, message} and ordered
     * newest-first. $category / $storeId filter the merged set; rows without
     * a store (site-level events) only appear when no store filter is set.
     *
     * @return array{entries: list<array<string,mixed>>, total: int}
     */
    public static function recent(
        int $limit = 50,
        int $offset = 0,
        ?string $category = null,
        ?string $storeId = null
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            $category = null;
        }

        // Each SELECT normalizes one source to the same column tuple. All
        // sources are capped upstream (admin_event_log ROW_CAP, mint event
        // prune, webhook/notification cleanup crons), so the union stays
        // bounded. Messages are built in SQL; display escaping is the
        // client's job.
        $union = "
            SELECT timestamp AS ts, category, store_id, invoice_id,
                   label AS ref,
                   context || ': ' || message AS message
              FROM admin_event_log
            UNION ALL
            SELECT timestamp, 'mint', store_id, NULL,
                   mint_url,
                   event_type
                   || CASE WHEN failure_type IS NOT NULL AND failure_type != ''
                           THEN ' [' || failure_type || ']' ELSE '' END
                   || CASE WHEN address IS NOT NULL AND address != ''
                           THEN ' addr=' || address ELSE '' END
                   || CASE WHEN details IS NOT NULL AND details != ''
                           THEN ' ' || details ELSE '' END
              FROM mint_event_log
            UNION ALL
            SELECT d.created_at, 'webhook', w.store_id, d.invoice_id,
                   w.url,
                   'delivery of ' || d.event_type || ' failed after ' || d.attempts || ' attempt(s)'
                   || CASE WHEN d.status_code IS NOT NULL
                           THEN ' (HTTP ' || d.status_code || ')' ELSE '' END
              FROM webhook_deliveries d
              JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.status = 'failed'
            UNION ALL
            SELECT failed_at, 'email', store_id, invoice_id,
                   event_type,
                   'email delivery failed after ' || attempts || ' attempt(s)'
                   || CASE WHEN last_error IS NOT NULL AND last_error != ''
                           THEN ': ' || last_error ELSE '' END
              FROM notification_queue
             WHERE failed_at IS NOT NULL
            UNION ALL
            SELECT updated_at, 'swap', store_id, invoice_id,
                   provider,
                   'swap ' || status || ': ' || error_message
              FROM swap_attempts
             WHERE error_message IS NOT NULL AND error_message != ''
            UNION ALL
            SELECT updated_at, 'sweep', store_id, NULL,
                   provider,
                   'sweep ' || status || ': ' || error_message
              FROM sweep_attempts
             WHERE error_message IS NOT NULL AND error_message != ''
        ";

        $where = [];
        $params = [];
        if ($category !== null) {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        if ($storeId !== null && $storeId !== '') {
            $where[] = 'store_id = ?';
            $params[] = $storeId;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $totalRow = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM ({$union}){$whereSql}",
            $params
        );
        $total = (int)($totalRow['n'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT ts, category, store_id, invoice_id, ref, message
               FROM ({$union}){$whereSql}
              ORDER BY ts DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'timestamp' => (int)$row['ts'],
                'category' => (string)$row['category'],
                'storeId' => $row['store_id'] !== null ? (string)$row['store_id'] : null,
                'invoiceId' => $row['invoice_id'] !== null ? (string)$row['invoice_id'] : null,
                'ref' => $row['ref'] !== null ? (string)$row['ref'] : null,
                'message' => (string)$row['message'],
            ];
        }
        return ['entries' => $entries, 'total' => $total];
    }
}
