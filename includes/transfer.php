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

    /**
     * Open an outgoing operation *before* any money moves.
     *
     * A ledger row written only on success cannot describe a withdrawal whose response
     * was lost, so the operator sees an error while the payment settles at the mint and
     * a retry pays twice. This records the intent first and returns its id; callers
     * settle it with complete() or fail().
     *
     * @return string|null Transfer id, or null if the ledger write failed
     */
    public static function open(
        string $storeId,
        string $type,
        int $amount,
        string $unit,
        ?string $destination = null,
        ?string $reference = null,
        ?string $detail = null,
        ?string $token = null
    ): ?string {
        $id = Database::generateId('xfer');
        try {
            Database::insert('transfers', [
                'id' => $id,
                'store_id' => $storeId,
                'type' => $type,
                'amount' => $amount,
                'fee' => 0,
                'unit' => $unit,
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
            return null;
        }
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

    /** @return array<int, array<string, mixed>> operations that never reached a terminal state */
    public static function getPending(int $limit = 50): array {
        return Database::fetchAll(
            "SELECT * FROM transfers WHERE status = ? ORDER BY created_at ASC LIMIT ?",
            [self::STATUS_PENDING, $limit]
        );
    }

    public static function getById(string $id): ?array {
        return Database::fetchOne("SELECT * FROM transfers WHERE id = ?", [$id]) ?: null;
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
            'reference' => $row['reference'] ?? null,
            'updatedTime' => isset($row['updated_at']) ? (int)$row['updated_at'] : (int)$row['created_at'],
            'createdTime' => (int)$row['created_at'],
        ];
    }
}
