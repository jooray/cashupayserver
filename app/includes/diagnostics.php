<?php
/**
 * Diagnostic Report builder.
 *
 * Produces a single JSON report that an operator can download from the
 * site-wide settings menu and hand to the developers when CashuPayServer is
 * misbehaving. The report is streamed to an output handle so that even an
 * "export all" on a busy server never has to hold every row in memory at once.
 *
 * Anonymization (the default) drops or scrubs anything that could identify a
 * customer or expose payment routing:
 *   - product names                -> kept as product_id only
 *   - invoice notes / metadata     -> omitted
 *   - txids, preimages, bolt11,
 *     on-chain / lightning addrs   -> omitted, and scrubbed from free text
 *   - notification recipient,
 *     subject and body             -> omitted
 *   - real invoice ids             -> replaced with per-report surrogates
 *     (inv_1, inv_2, ...) so cart line items and melts still join up.
 *
 * When $anonymize is false the per-row PII and free text are emitted verbatim
 * for the rare debugging case that needs the real data. SERVER SECRETS are
 * NEVER exported in either mode — the config section is a tight allowlist, so
 * the wallet mnemonic/xpub, admin password hash, cron key, API-key hashes and
 * SMTP/webhook secrets can never leak regardless of the toggle.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/updater.php';
require_once __DIR__ . '/dev_fee.php'; // FEE_NOTE_* constants

class Diagnostics {
    public const SCHEMA_VERSION = 1;

    /**
     * Config keys safe to include in ANY mode — non-secret operational flags
     * useful for diagnosing behaviour. This is an exclusive allowlist: any key
     * not named here (including future-added secrets) is dropped automatically.
     */
    private const SAFE_CONFIG_KEYS = [
        'allow_private_endpoints',
        'auto_update_enabled',
        'cron_warning_dismissed_at',
        'deployment_id',
        'dev_fee_onchain_branch',
        'dev_fee_branch_acquired_at',
        'dev_fee_branch_last_attempt_at',
        'dev_fee_branch_last_error',
        'fee_tracking_start_at',
        'free_trial_started_at',
        'free_trial_until_ts',
        'free_trial_expired_at',
        'free_trial_expired_reason',
        'free_trial_revenue_cap_sats',
        'installed_at',
        'last_external_cron_at',
        'last_external_cron_swaps_at',
        'last_proof_sync',
        'network_fs_warning_dismissed',
        'notifications_enabled',
        'notifications_invoice_paid_enabled',
        'notifications_auto_cashout_enabled',
        'notifications_payer_receipt_enabled',
        'notifications_update_available_enabled',
        'setup_complete',
        'update_channel',
        'updater_auto_rollback_dismissed',
        'updater_banner_dismissed',
        'updater_blocked_shas',
        'updater_last_auto_rollback',
        'updater_last_check',
        'updater_last_rollback',
        'updater_last_update',
        'updater_notified_sha',
        'updater_pending_verify',
        'url_mode',
        'usage_stats_enabled',
        'usage_stats_level',
        'usage_stats_last_sent_at',
        'usage_stats_last_attempt_at',
        'usage_stats_last_error',
    ];

    /**
     * Operator-PII config keys, included only when NOT anonymizing.
     * (Still never secrets — just the operator's own contact address.)
     */
    private const PII_CONFIG_KEYS = [
        'notifications_to_email',
    ];

    /** Invoice columns kept in the anonymized report (everything else dropped). */
    private const INVOICE_SAFE_COLS = [
        'id', 'store_id', 'status', 'additional_status',
        'amount', 'currency', 'amount_sats', 'exchange_rate',
        'mint_url', 'settled_rail', 'fee_redirect_note',
        'created_at', 'expiration_time', 'last_polled_at', 'paid_at',
        'onchain_address_index', 'onchain_amount_sat', 'onchain_amount_tweak_sats',
        'onchain_first_seen_at', 'onchain_created_tip_height',
        'onchain_needs_manual_confirmation', 'cart_purchase_counted',
    ];

    /** Extra invoice columns added only when NOT anonymizing. */
    private const INVOICE_PII_COLS = [
        'quote_id', 'bolt11', 'metadata', 'checkout_config',
        'onchain_address', 'onchain_manual_candidates', 'fee_redirect_destination',
    ];

    /** Invoice-item columns kept in the anonymized report. */
    private const ITEM_SAFE_COLS = [
        'id', 'invoice_id', 'store_id', 'product_id',
        'unit_price', 'unit_currency', 'quantity', 'amount_sats',
        'display_amount', 'display_currency', 'image_type', 'created_at',
    ];

    /** Extra invoice-item columns added only when NOT anonymizing. */
    private const ITEM_PII_COLS = ['title', 'image_value'];

    /** Melt columns kept in the anonymized report (derived `type` added separately). */
    private const MELT_SAFE_COLS = [
        'id', 'store_id', 'amount_sats', 'network_fee_sats',
        'invoice_id', 'via', 'created_at',
    ];

    /** Extra melt columns added only when NOT anonymizing. */
    private const MELT_PII_COLS = ['destination', 'preimage', 'note'];

    /** Maps a real invoice id to a stable per-report surrogate. */
    private array $invoiceIdMap = [];
    private int $invoiceIdSeq = 0;

    private bool $anonymize;
    private ?string $range; // null = all, '1m' = past 30 days

    public function __construct(bool $anonymize, ?string $range) {
        $this->anonymize = $anonymize;
        $this->range = $range;
    }

    /**
     * Write the full JSON report to $out. Scalar sections are encoded whole;
     * the potentially-large per-row sections are streamed one row at a time.
     */
    public function stream($out): void {
        fwrite($out, '{');

        $this->writeScalar($out, 'meta', $this->meta(), true);
        $this->writeScalar($out, 'system', $this->system());
        $this->writeScalar($out, 'mint_reliability', $this->mintReliability());
        $this->writeScalar($out, 'aggregates', $this->aggregates());

        $this->writeArray($out, 'mint_event_log', $this->mintEventRows());
        $this->writeArray($out, 'notification_failures', $this->notificationRows());
        // Invoices first so the surrogate-id map is populated before items/melts.
        $this->writeArray($out, 'invoices', $this->invoiceRows());
        $this->writeArray($out, 'invoice_items', $this->invoiceItemRows());
        $this->writeArray($out, 'melts', $this->meltRows());

        fwrite($out, '}');
    }

    /** Convenience for tests: return the decoded report as an array. */
    public function toArray(): array {
        $h = fopen('php://temp', 'r+');
        $this->stream($h);
        rewind($h);
        $json = stream_get_contents($h);
        fclose($h);
        return json_decode($json, true);
    }

    // ---- section builders -------------------------------------------------

    private function meta(): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => time(),
            'generated_at_utc' => gmdate('c'),
            'anonymized'     => $this->anonymize,
            'range'          => $this->range === null ? 'all' : $this->range,
            'deployment_id'  => (string) Config::get('deployment_id', 'ANONYMOUS'),
            'version'        => CASHUPAY_VERSION,
            'build'          => Updater::getLocalBuildInfo(),
        ];
    }

    private function system(): array {
        $dbPath = Database::getDbPath();
        $dataDir = Database::getDataDir();
        $sqlite = Database::fetchOne("SELECT sqlite_version() AS v");

        $config = [];
        foreach (self::SAFE_CONFIG_KEYS as $k) {
            $v = Config::get($k, null);
            if ($v !== null) $config[$k] = $v;
        }
        if (!$this->anonymize) {
            foreach (self::PII_CONFIG_KEYS as $k) {
                $v = Config::get($k, null);
                if ($v !== null) $config[$k] = $v;
            }
        }

        return [
            'php_version'     => PHP_VERSION,
            'os'              => php_uname(),
            'sqlite_version'  => $sqlite['v'] ?? null,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'db_size_bytes'   => is_file($dbPath) ? filesize($dbPath) : null,
            'disk_free_bytes' => @disk_free_space($dataDir) ?: null,
            'disk_total_bytes'=> @disk_total_space($dataDir) ?: null,
            'store_count'     => (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM stores")['c'] ?? 0),
            'config'          => $config,
        ];
    }

    private function mintReliability(): array {
        $rows = Database::fetchAll("SELECT * FROM mint_reliability ORDER BY mint_url ASC");
        foreach ($rows as &$r) {
            if ($this->anonymize) {
                $r['last_failure_message'] = $this->scrub($r['last_failure_message'] ?? null);
                $r['trusted_list_disabled_reason'] = $this->scrub($r['trusted_list_disabled_reason'] ?? null);
            }
        }
        unset($r);
        return $rows;
    }

    private function aggregates(): array {
        $startTs = $this->range !== null ? Stats::rangeStart($this->range) : null;
        [$where, $params] = $this->rangeClause($startTs, 'created_at');

        $byStatus = Database::fetchAll(
            "SELECT status, COUNT(*) AS n, COALESCE(SUM(amount_sats),0) AS sats
             FROM invoices{$where} GROUP BY status ORDER BY status", $params);
        $byRail = Database::fetchAll(
            "SELECT settled_rail, COUNT(*) AS n, COALESCE(SUM(amount_sats),0) AS sats
             FROM invoices{$where} GROUP BY settled_rail ORDER BY settled_rail", $params);
        $byCurrency = Database::fetchAll(
            "SELECT currency, COUNT(*) AS n
             FROM invoices{$where} GROUP BY currency ORDER BY currency", $params);
        $melts = Database::fetchAll(
            "SELECT note, COUNT(*) AS n, COALESCE(SUM(amount_sats),0) AS sats,
                    COALESCE(SUM(network_fee_sats),0) AS fee_sats
             FROM melts{$where} GROUP BY note", $params);

        $meltsByType = [];
        foreach ($melts as $m) {
            $type = $this->meltType($m['note']);
            $meltsByType[$type] = [
                'count'    => (int) $m['n'],
                'amount_sats' => (int) $m['sats'],
                'network_fee_sats' => (int) $m['fee_sats'],
            ];
        }

        return [
            'invoices_by_status'   => $byStatus,
            'invoices_by_rail'     => $byRail,
            'invoices_by_currency' => $byCurrency,
            'melts_by_type'        => $meltsByType,
        ];
    }

    // ---- streamed row generators -----------------------------------------

    private function mintEventRows(): \Generator {
        $startTs = $this->range !== null ? Stats::rangeStart($this->range) : null;
        [$where, $params] = $this->rangeClause($startTs, 'timestamp');
        foreach (Database::fetchAll(
            "SELECT * FROM mint_event_log{$where} ORDER BY timestamp ASC", $params) as $r) {
            $out = [
                'id'           => $r['id'],
                'mint_url'     => $r['mint_url'],
                'timestamp'    => (int) $r['timestamp'],
                'event_type'   => $r['event_type'],
                'failure_type' => $r['failure_type'],
                'store_id'     => $r['store_id'],
            ];
            if ($this->anonymize) {
                $out['details'] = $this->scrub($r['details'] ?? null);
            } else {
                $out['address'] = $r['address'];
                $out['details'] = $r['details'];
            }
            yield $out;
        }
    }

    private function notificationRows(): \Generator {
        $startTs = $this->range !== null ? Stats::rangeStart($this->range) : null;
        // Only surface problems: failed or still-pending sends.
        $clauses = ['(last_error IS NOT NULL OR sent_at IS NULL)'];
        $params = [];
        if ($startTs !== null) { $clauses[] = 'created_at >= ?'; $params[] = $startTs; }
        $where = ' WHERE ' . implode(' AND ', $clauses);
        foreach (Database::fetchAll(
            "SELECT * FROM notification_queue{$where} ORDER BY created_at ASC", $params) as $r) {
            $out = [
                'id'         => $r['id'],
                'store_id'   => $r['store_id'],
                'event_type' => $r['event_type'],
                'attempts'   => (int) $r['attempts'],
                'created_at' => (int) $r['created_at'],
                'sent_at'    => $r['sent_at'] !== null ? (int) $r['sent_at'] : null,
            ];
            if ($this->anonymize) {
                $out['last_error'] = $this->scrub($r['last_error'] ?? null);
            } else {
                $out['to_email']   = $r['to_email'];
                $out['subject']    = $r['subject'];
                $out['body']       = $r['body'];
                $out['last_error'] = $r['last_error'];
            }
            yield $out;
        }
    }

    private function invoiceRows(): \Generator {
        foreach (Stats::streamInvoices(Stats::ALL_STORES, $this->range, false) as $r) {
            $cols = self::INVOICE_SAFE_COLS;
            if (!$this->anonymize) $cols = array_merge($cols, self::INVOICE_PII_COLS);
            $out = $this->project($r, $cols);
            $out['id'] = $this->surrogate($r['id']);
            yield $out;
        }
    }

    private function invoiceItemRows(): \Generator {
        $startTs = $this->range !== null ? Stats::rangeStart($this->range) : null;
        [$where, $params] = $this->rangeClause($startTs, 'created_at');
        foreach (Database::fetchAll(
            "SELECT * FROM invoice_items{$where} ORDER BY created_at ASC, rowid ASC", $params) as $r) {
            $cols = self::ITEM_SAFE_COLS;
            if (!$this->anonymize) $cols = array_merge($cols, self::ITEM_PII_COLS);
            $out = $this->project($r, $cols);
            $out['invoice_id'] = $this->surrogate($r['invoice_id']);
            yield $out;
        }
    }

    private function meltRows(): \Generator {
        foreach (Stats::streamMelts(Stats::ALL_STORES, $this->range, null) as $r) {
            $cols = self::MELT_SAFE_COLS;
            if (!$this->anonymize) $cols = array_merge($cols, self::MELT_PII_COLS);
            $out = $this->project($r, $cols);
            $out['type'] = $this->meltType($r['note'] ?? null);
            if (array_key_exists('invoice_id', $out) && $out['invoice_id'] !== null) {
                $out['invoice_id'] = $this->surrogate($out['invoice_id']);
            }
            yield $out;
        }
    }

    // ---- helpers ----------------------------------------------------------

    /** Keep only the named columns that are present on the row. */
    private function project(array $row, array $cols): array {
        $out = [];
        foreach ($cols as $c) {
            if (array_key_exists($c, $row)) $out[$c] = $row[$c];
        }
        return $out;
    }

    /** Classify a melt note into a stable, non-sensitive type. */
    private function meltType(?string $note): string {
        if ($note === null || $note === '') return 'payout';
        switch ($note) {
            case FEE_NOTE_UPSTREAM: return 'fee_upstream';
            case FEE_NOTE_DEV:      return 'fee_dev';
            case FEE_NOTE_HOSTING:  return 'fee_hosting';
            default:                return 'other';
        }
    }

    /** Real invoice id -> per-report surrogate (identity when not anonymizing). */
    private function surrogate(?string $realId): ?string {
        if ($realId === null) return null;
        if (!$this->anonymize) return $realId;
        if (!isset($this->invoiceIdMap[$realId])) {
            $this->invoiceIdMap[$realId] = 'inv_' . (++$this->invoiceIdSeq);
        }
        return $this->invoiceIdMap[$realId];
    }

    private function rangeClause(?int $startTs, string $column): array {
        if ($startTs === null) return ['', []];
        return [" WHERE {$column} >= ?", [$startTs]];
    }

    /**
     * Redact payment-identifying tokens from free text while leaving the
     * human-readable error intact. Only used in anonymized mode.
     */
    private function scrub(?string $text): ?string {
        if ($text === null || $text === '') return $text;
        $patterns = [
            // Lightning invoices / LNURL (run before hex so the ln* prefix wins).
            '/\bln(?:bc|tb|bcrt)[0-9a-z]+\b/i'                 => '[REDACTED_LN]',
            '/\blnurl[0-9a-z]+\b/i'                            => '[REDACTED_LNURL]',
            // Email / lightning addresses.
            '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i'       => '[REDACTED_ADDR]',
            // On-chain addresses: bech32 then base58.
            '/\b(?:bc1|tb1|bcrt1)[0-9ac-hj-np-z]{8,}\b/i'      => '[REDACTED_ADDR]',
            '/\b[13mn2][a-km-zA-HJ-NP-Z1-9]{25,34}\b/'         => '[REDACTED_ADDR]',
            // txid / preimage / payment hash (64 hex) then any long hex blob.
            '/\b[0-9a-f]{64}\b/i'                              => '[REDACTED_HASH]',
            '/\b[0-9a-f]{32,}\b/i'                             => '[REDACTED_HEX]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    // ---- low-level JSON writers ------------------------------------------

    private function writeScalar($out, string $key, $value, bool $first = false): void {
        if (!$first) fwrite($out, ',');
        fwrite($out, json_encode($key) . ':' . json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /** Stream an array section one element at a time (keeps memory flat). */
    private function writeArray($out, string $key, \Generator $rows): void {
        fwrite($out, ',' . json_encode($key) . ':[');
        $first = true;
        foreach ($rows as $row) {
            if (!$first) fwrite($out, ',');
            fwrite($out, json_encode($row, JSON_UNESCAPED_SLASHES));
            $first = false;
        }
        fwrite($out, ']');
    }
}
