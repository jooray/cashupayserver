<?php
/**
 * CashuPayServer - Outgoing transfers ledger
 *
 * Records funds leaving a store's wallet — Lightning withdrawals,
 * auto-withdrawals, token exports and donations — for accountability and
 * display alongside incoming invoices on the dashboard.
 */

require_once __DIR__ . '/database.php';

class Transfer {
    // Known transfer types.
    const TYPE_LIGHTNING = 'lightning';          // manual withdrawal to a BOLT-11 invoice
    const TYPE_LIGHTNING_ADDRESS = 'lightning_address'; // manual withdrawal to a Lightning address
    const TYPE_AUTO_WITHDRAW = 'auto_withdraw';   // threshold auto-withdrawal (cron)
    const TYPE_TOKEN_EXPORT = 'token_export';     // exported as a Cashu token
    const TYPE_DONATION = 'donation';             // sent to the donation sink

    // Lifecycle. `pending` means the money may or may not have left; only cron or an
    // operator action may resolve it.
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /** Withdrawals that pay a Lightning invoice through a melt. */
    const WITHDRAWAL_TYPES = [self::TYPE_LIGHTNING, self::TYPE_LIGHTNING_ADDRESS, self::TYPE_AUTO_WITHDRAW];

    /** Value handed out as a bearer token, resolved by whoever redeems it. */
    const TOKEN_TYPES = [self::TYPE_TOKEN_EXPORT, self::TYPE_DONATION];

    /**
     * Open an outgoing operation *before* any money moves.
     *
     * A ledger row written only on success cannot describe a withdrawal whose response
     * was lost, so the operator sees an error while the payment settles at the mint and
     * a retry pays twice. This records the intent first and returns its id; callers
     * settle it with complete() or fail().
     *
     * Throws when the row cannot be written: a money-moving path must not go ahead
     * without the record that lets it be reconciled.
     *
     * @param ?string $mintUrl The mint the money leaves from. Recorded so a withdrawal
     *                         made at a mint that is no longer the primary can still be
     *                         reconciled there. NULL means the store's primary.
     * @return string Transfer id
     */
    public static function open(
        string $storeId,
        string $type,
        int $amount,
        string $unit,
        ?string $destination = null,
        ?string $reference = null,
        ?string $detail = null,
        ?string $token = null,
        ?string $mintUrl = null
    ): string {
        $id = Database::generateId('xfer');
        try {
            Database::insert('transfers', [
                'id' => $id,
                'store_id' => $storeId,
                'type' => $type,
                'amount' => $amount,
                'fee' => 0,
                'unit' => $unit,
                'mint_url' => $mintUrl !== null ? rtrim($mintUrl, '/') : null,
                'destination' => $destination,
                'status' => self::STATUS_PENDING,
                'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
                'token' => $token,
                'reference' => $reference,
                'updated_at' => Database::timestamp(),
                'created_at' => Database::timestamp(),
            ]);
            return $id;
        } catch (Throwable $e) {
            error_log('Transfer::open failed: ' . $e->getMessage());
            throw new RuntimeException(
                'Could not record this transfer, so nothing was sent. Please try again in a moment.',
                0,
                $e
            );
        }
    }

    /**
     * The `reference` of a Lightning withdrawal: its melt quote and the proofs it spends.
     *
     * The quote alone is not enough. A mint may forget a quote (restart, pruning); the
     * library then settles its own journal from the inputs' state and deletes it, and
     * the ledger row needs the same inputs to reach the same answer.
     *
     * @param string[] $inputSecrets
     */
    public static function meltReference(string $quoteId, array $inputSecrets): string {
        return json_encode(['quote' => $quoteId, 'inputs' => array_values($inputSecrets)]);
    }

    /** The melt quote id of a withdrawal row (plain quote id on rows before inputs were kept). */
    public static function meltQuoteId(array $row): string {
        $reference = (string)($row['reference'] ?? '');
        if (str_starts_with($reference, '{')) {
            $decoded = json_decode($reference, true);
            return is_array($decoded) ? (string)($decoded['quote'] ?? '') : '';
        }
        return $reference;
    }

    /** @return string[] proof secrets a withdrawal row spent, or [] if not recorded */
    public static function meltInputSecrets(array $row): array {
        $reference = (string)($row['reference'] ?? '');
        if (!str_starts_with($reference, '{')) {
            return [];
        }
        $decoded = json_decode($reference, true);
        $inputs = is_array($decoded) ? ($decoded['inputs'] ?? []) : [];
        return is_array($inputs) ? array_values(array_filter(array_map('strval', $inputs), 'strlen')) : [];
    }

    /** Attach the spent inputs to a withdrawal row once they are known. */
    public static function setReference(?string $id, string $reference): void {
        self::update($id, ['reference' => $reference]);
    }

    /**
     * Does this store have a Lightning withdrawal whose outcome is not known yet?
     *
     * Each retry fetches a fresh invoice and therefore a fresh quote, so the library's
     * per-quote guard does not stop a second payment. Starting another withdrawal while
     * one is unresolved is how a destination gets paid twice.
     */
    public static function hasPendingWithdrawal(string $storeId): bool {
        $placeholders = implode(',', array_fill(0, count(self::WITHDRAWAL_TYPES), '?'));
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM transfers
             WHERE store_id = ? AND status = ? AND type IN ($placeholders)",
            array_merge([$storeId, self::STATUS_PENDING], self::WITHDRAWAL_TYPES)
        );
        return (int)($row['cnt'] ?? 0) > 0;
    }

    /** Settle an operation opened with open(). */
    public static function complete(
        ?string $id,
        int $amount,
        int $fee = 0,
        ?string $detail = null,
        ?string $token = null
    ): void {
        self::update($id, [
            'status' => self::STATUS_COMPLETED,
            'amount' => $amount,
            'fee' => $fee,
        ] + ($detail !== null ? ['detail' => mb_substr($detail, 0, 500)] : [])
          + ($token !== null ? ['token' => $token] : []));
    }

    /** Annotate a pending operation without changing its status. */
    public static function note(?string $id, string $detail): void {
        self::update($id, ['detail' => mb_substr($detail, 0, 500)]);
    }

    public static function fail(?string $id, ?string $detail = null): void {
        self::update($id, [
            'status' => self::STATUS_FAILED,
        ] + ($detail !== null ? ['detail' => mb_substr($detail, 0, 500)] : []));
    }

    private static function update(?string $id, array $fields): void {
        if ($id === null) {
            return;
        }
        try {
            $fields['updated_at'] = Database::timestamp();
            Database::update('transfers', $fields, 'id = ?', [$id]);
        } catch (Throwable $e) {
            error_log('Transfer::update failed: ' . $e->getMessage());
        }
    }

    /**
     * Operations that never reached a terminal state, withdrawals first.
     *
     * A pending export only waits for its recipient and may do so for months; a pending
     * withdrawal is money whose fate is unknown. Ordering by age alone let the first
     * kind fill the window and hide the second.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getPending(int $limit = 50): array {
        $placeholders = implode(',', array_fill(0, count(self::TOKEN_TYPES), '?'));
        return Database::fetchAll(
            "SELECT * FROM transfers WHERE status = ?
             ORDER BY CASE WHEN type IN ($placeholders) THEN 1 ELSE 0 END, created_at ASC, id ASC
             LIMIT ?",
            array_merge([self::STATUS_PENDING], self::TOKEN_TYPES, [$limit])
        );
    }

    /**
     * One page of pending rows of the given types, after a (created_at, id) cursor.
     *
     * @param string[] $types
     * @param array{0: int, 1: string}|null $after
     * @return array<int, array<string, mixed>>
     */
    public static function getPendingPage(array $types, ?array $after, int $limit = 50): array {
        if (empty($types)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $params = array_merge([self::STATUS_PENDING], array_values($types));
        $cursorSql = '';
        if ($after !== null) {
            $cursorSql = ' AND (created_at > ? OR (created_at = ? AND id > ?))';
            array_push($params, (int)$after[0], (int)$after[0], (string)$after[1]);
        }
        $params[] = $limit;
        return Database::fetchAll(
            "SELECT * FROM transfers WHERE status = ? AND type IN ($placeholders)$cursorSql
             ORDER BY created_at ASC, id ASC LIMIT ?",
            $params
        );
    }

    public static function getById(string $id): ?array {
        return Database::fetchOne("SELECT * FROM transfers WHERE id = ?", [$id]) ?: null;
    }

    /**
     * The pending token transfer that handed out exactly these proofs, if any.
     *
     * Lets whoever *notices* a token was redeemed settle it there and then. The proof
     * secrets are the only durable link between a token in someone's wallet and the
     * ledger row that recorded handing it over, and they are stored as a comma-separated
     * reference. Order is not significant, so compare as sets.
     *
     * @param string[] $secrets
     */
    public static function findPendingBySecrets(string $storeId, array $secrets): ?array {
        $wanted = array_values(array_unique(array_filter($secrets, 'strlen')));
        if (empty($wanted)) {
            return null;
        }
        sort($wanted);

        // Query by store and by one of the secrets directly. Scanning a fixed window of
        // pending rows missed any token that was not among the oldest few dozen.
        $placeholders = implode(',', array_fill(0, count(self::TOKEN_TYPES), '?'));
        $rows = Database::fetchAll(
            "SELECT * FROM transfers
             WHERE store_id = ? AND status = ? AND type IN ($placeholders)
               AND instr(reference, ?) > 0
             ORDER BY created_at ASC",
            array_merge([$storeId, self::STATUS_PENDING], self::TOKEN_TYPES, [$wanted[0]])
        );
        foreach ($rows as $row) {
            $have = array_values(array_unique(array_filter(
                explode(',', (string)($row['reference'] ?? '')),
                'strlen'
            )));
            sort($have);
            if ($have === $wanted) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Record an outgoing transfer. Never throws — a ledger write must not break
     * the money movement it records (the transfer already happened).
     *
     * @param int    $amount      amount sent, in the store's mint unit (smallest)
     * @param int    $fee         fee paid, same unit
     * @param string $unit        mint unit (sat, usd, …)
     * @param ?string $destination Lightning address / invoice / null (token export)
     * @param string $status      'completed' | 'pending' | 'failed'
     * @param ?string $detail     preimage, memo, or error message
     */
    public static function record(
        string $storeId,
        string $type,
        int $amount,
        int $fee,
        string $unit,
        ?string $destination = null,
        string $status = self::STATUS_COMPLETED,
        ?string $detail = null,
        ?string $token = null,
        ?string $reference = null
    ): void {
        try {
            Database::insert('transfers', [
                'id' => Database::generateId('xfer'),
                'store_id' => $storeId,
                'type' => $type,
                'amount' => $amount,
                'fee' => $fee,
                'unit' => $unit,
                'destination' => $destination,
                'status' => $status,
                'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
                'token' => $token,
                'reference' => $reference,
                'updated_at' => Database::timestamp(),
                'created_at' => Database::timestamp(),
            ]);
        } catch (Throwable $e) {
            error_log('Transfer::record failed: ' . $e->getMessage());
        }
    }

    /** @return array<int, array<string, mixed>> most recent transfers first */
    public static function getByStore(string $storeId, int $limit = 10): array {
        return Database::fetchAll(
            "SELECT * FROM transfers WHERE store_id = ? ORDER BY created_at DESC LIMIT ?",
            [$storeId, $limit]
        );
    }

    public static function formatForApi(array $row): array {
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'amount' => (int)$row['amount'],
            'fee' => (int)$row['fee'],
            'unit' => $row['unit'],
            'destination' => $row['destination'],
            'status' => $row['status'],
            'detail' => $row['detail'],
            // The token itself is deliberately not exposed here: it is bearer money and
            // the list endpoint is rendered in the browser. Fetch it explicitly instead.
            'hasToken' => !empty($row['token']),
            // `reference` holds proof secrets (or a withdrawal's spent inputs). It is
            // not needed by the browser and is not sent there.
            'mintUrl' => $row['mint_url'] ?? null,
            'updatedTime' => isset($row['updated_at']) ? (int)$row['updated_at'] : (int)$row['created_at'],
            'createdTime' => (int)$row['created_at'],
        ];
    }
}
