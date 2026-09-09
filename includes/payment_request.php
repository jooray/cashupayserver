<?php
/**
 * CashuPayServer - NUT-18 payment requests
 *
 * A payment request published as a QR code is an instruction to a stranger's wallet:
 * "send this much, in this unit, from this mint, to this URL". Without a persisted
 * record the receiving endpoint can only accept whatever arrives and add it to the
 * balance — it cannot tell whether the payment matched what was asked for, cannot
 * recognise a second delivery of the same request, and has nothing to return when the
 * sender retries because its response was lost.
 *
 * So each request is a row: unguessable id, the store and terms it was created with, and
 * a single terminal result that a retry replays instead of re-crediting.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class PaymentRequest {
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';

    /** How long a published request stays payable. */
    const TTL = 3600;

    /**
     * Create a request for a store.
     *
     * The id is 16 random bytes because it travels in a URL that anyone holding the QR
     * can see: it must not be guessable from another store's request.
     */
    public static function create(string $storeId, int $amount, string $unit, string $mintUrl, ?string $memo = null): array {
        if ($amount <= 0) {
            throw new Exception('Amount must be positive');
        }

        $now = time();
        $id = bin2hex(random_bytes(16));

        Database::insert('payment_requests', [
            'id' => $id,
            'store_id' => $storeId,
            'amount' => $amount,
            'unit' => strtolower($unit),
            'mint_url' => $mintUrl,
            'memo' => $memo !== null ? mb_substr($memo, 0, 200) : null,
            'status' => self::STATUS_PENDING,
            'expires_at' => $now + self::TTL,
            'created_at' => $now,
        ]);

        return self::getById($id);
    }

    public static function getById(string $id): ?array {
        return Database::fetchOne('SELECT * FROM payment_requests WHERE id = ?', [$id]) ?: null;
    }

    public static function isExpired(array $request): bool {
        return $request['status'] === self::STATUS_PENDING && (int)$request['expires_at'] < time();
    }

    /**
     * Record a completed receipt, exactly once.
     *
     * @param array  $request        The request being paid
     * @param int    $receivedAmount Amount actually credited, in the request's unit
     * @param array  $result         Response body to replay on a retry
     * @return bool True when this call recorded the payment; false when it was already paid
     */
    public static function markPaid(array $request, int $receivedAmount, array $result): bool {
        $changed = Database::update(
            'payment_requests',
            [
                'status' => self::STATUS_PAID,
                'received_amount' => $receivedAmount,
                'received_at' => time(),
                'result' => json_encode($result),
            ],
            'id = ? AND status = ?',
            [$request['id'], self::STATUS_PENDING]
        );

        return $changed === 1;
    }

    /** The stored response for an already-paid request, so a retry is idempotent. */
    public static function storedResult(array $request): ?array {
        if ($request['status'] !== self::STATUS_PAID || empty($request['result'])) {
            return null;
        }
        $decoded = json_decode((string)$request['result'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Drop requests nobody paid, so the table does not grow without bound. */
    public static function cleanup(int $olderThanSeconds = 604800): int {
        return Database::delete(
            'payment_requests',
            'status = ? AND created_at < ?',
            [self::STATUS_PENDING, time() - $olderThanSeconds]
        );
    }
}
