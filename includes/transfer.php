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
        string $status = 'completed',
        ?string $detail = null
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
            'createdTime' => (int)$row['created_at'],
        ];
    }
}
