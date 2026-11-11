<?php
/**
 * CashuPayServer - Outgoing token transfers ledger
 *
 * Records every Cashu token the server hands out (a dashboard export, or the
 * recovery token for funds stranded at a previous mint) BEFORE the token is
 * shown to anyone. The row carries the token itself and the proof secrets it
 * is made of, so a token whose QR code was never scanned can be shown again,
 * checked against the mint, or taken back — instead of being money that
 * silently left the balance and exists nowhere else.
 *
 * Lightning withdrawals are not recorded here: they live in the `melts`
 * ledger (see MeltLog in includes/dev_fee.php).
 */

require_once __DIR__ . '/database.php';

class Transfer {
    /** A Cashu token exported from a store's spendable balance. */
    const TYPE_TOKEN_EXPORT = 'token_export';
    /** A recovery token serialised from a namespace no store uses any more. */
    const TYPE_STRANDED_EXPORT = 'stranded_export';

    /** Handed out; nobody has cashed it in yet (or we have not noticed). */
    const STATUS_PENDING = 'pending';
    /** The mint confirmed every proof spent — the recipient has the money. */
    const STATUS_COMPLETED = 'completed';
    /** The operator took it back; the token no longer works. */
    const STATUS_RECLAIMED = 'reclaimed';
    /** The export never reached the operator (reservation failed etc.). */
    const STATUS_FAILED = 'failed';

    const TOKEN_TYPES = [self::TYPE_TOKEN_EXPORT, self::TYPE_STRANDED_EXPORT];

    /**
     * Open a transfer BEFORE the token is disclosed.
     *
     * Throws when the row cannot be written: a token must not go out without
     * the record that lets it be shown again or reconciled.
     *
     * @param string[] $secrets proof secrets the token carries
     * @return string transfer id
     */
    public static function open(
        string $storeId,
        string $type,
        int $amount,
        string $unit,
        array $secrets,
        string $token,
        ?string $mintUrl = null,
        ?string $note = null,
        ?string $destination = null
    ): string {
        $id = Database::generateId('xfer');
        $now = Database::timestamp();
        try {
            Database::insert('transfers', [
                'id' => $id,
                'store_id' => $storeId,
                'type' => $type,
                'amount' => $amount,
                'unit' => strtolower($unit),
                'mint_url' => $mintUrl !== null ? rtrim($mintUrl, '/') : null,
                'destination' => $destination,
                'reference' => self::encodeSecrets($secrets),
                'token' => $token,
                'status' => self::STATUS_PENDING,
                'note' => $note !== null ? mb_substr($note, 0, 500) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            error_log('Transfer::open failed: ' . $e->getMessage());
            throw new RuntimeException(
                'Could not record this export, so nothing was handed out. Please try again in a moment.',
                0,
                $e
            );
        }
        return $id;
    }

    /** Only a pending row may reach a terminal state. */
    public static function complete(string $id, ?string $note = null): bool {
        return self::settle($id, self::STATUS_COMPLETED, $note);
    }

    public static function markReclaimed(string $id, ?string $note = null): bool {
        return self::settle($id, self::STATUS_RECLAIMED, $note);
    }

    public static function fail(string $id, ?string $note = null): bool {
        return self::settle($id, self::STATUS_FAILED, $note);
    }

    /** Annotate a pending row without changing its status. */
    public static function note(string $id, string $note): bool {
        return self::update($id, ['note' => mb_substr($note, 0, 500)]);
    }

    /** Stamp updated_at (the reconciler's "last checked" throttle). */
    public static function touch(string $id): bool {
        return self::update($id, []);
    }

    private static function settle(string $id, string $status, ?string $note): bool {
        $fields = ['status' => $status];
        if ($note !== null) {
            $fields['note'] = mb_substr($note, 0, 500);
        }
        return self::update($id, $fields);
    }

    /**
     * @return bool whether a pending row changed (false for unknown ids and
     *              rows that already reached a terminal state — history is
     *              never rewritten by a later check)
     */
    private static function update(string $id, array $fields): bool {
        try {
            $fields['updated_at'] = Database::timestamp();
            return Database::update(
                'transfers',
                $fields,
                'id = ? AND status = ?',
                [$id, self::STATUS_PENDING]
            ) > 0;
        } catch (Throwable $e) {
            error_log('Transfer::update failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getById(string $id): ?array {
        if ($id === '') {
            return null;
        }
        return Database::fetchOne("SELECT * FROM transfers WHERE id = ?", [$id]) ?: null;
    }

    /**
     * Pending token transfers, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getPending(int $limit = 50, ?string $storeId = null, ?int $updatedBefore = null): array {
        $sql = "SELECT * FROM transfers WHERE status = ?";
        $params = [self::STATUS_PENDING];
        if ($storeId !== null) {
            $sql .= " AND store_id = ?";
            $params[] = $storeId;
        }
        if ($updatedBefore !== null) {
            // `col <= ?` with an integer param (never `(? - col) >= ?`): PDO
            // binds as TEXT and only a bare column comparison coerces it.
            $sql .= " AND (updated_at IS NULL OR updated_at <= ?)";
            $params[] = $updatedBefore;
        }
        $sql .= " ORDER BY created_at ASC, id ASC LIMIT ?";
        $params[] = $limit;
        return Database::fetchAll($sql, $params);
    }

    /** Most recent transfers of a store, newest first. */
    public static function getByStore(string $storeId, int $limit = 10): array {
        return Database::fetchAll(
            "SELECT * FROM transfers WHERE store_id = ? ORDER BY created_at DESC, id DESC LIMIT ?",
            [$storeId, $limit]
        );
    }

    /**
     * The pending token transfer that handed out exactly these proofs.
     *
     * Lets whoever *notices* a token was redeemed settle it there and then.
     * Order is not significant — compare as sets; a strict subset or superset
     * is a different token.
     *
     * @param string[] $secrets
     */
    public static function findPendingBySecrets(string $storeId, array $secrets): ?array {
        $wanted = self::normalizeSecrets($secrets);
        if (empty($wanted)) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count(self::TOKEN_TYPES), '?'));
        $rows = Database::fetchAll(
            "SELECT * FROM transfers
             WHERE store_id = ? AND status = ? AND type IN ($placeholders)
               AND instr(reference, ?) > 0
             ORDER BY created_at ASC",
            array_merge([$storeId, self::STATUS_PENDING], self::TOKEN_TYPES, [$wanted[0]])
        );
        foreach ($rows as $row) {
            if (self::secretsOf($row) === $wanted) {
                return $row;
            }
        }
        return null;
    }

    /**
     * The pending stranded-funds export for a wallet namespace, if any.
     */
    public static function findPendingStranded(string $mintUrl, string $unit): ?array {
        return Database::fetchOne(
            "SELECT * FROM transfers
             WHERE type = ? AND status = ? AND mint_url = ? AND unit = ?
             ORDER BY created_at DESC LIMIT 1",
            [self::TYPE_STRANDED_EXPORT, self::STATUS_PENDING, rtrim($mintUrl, '/'), strtolower($unit)]
        ) ?: null;
    }

    /** @return string[] sorted, de-duplicated secrets a row's token carries */
    public static function secretsOf(array $row): array {
        return self::normalizeSecrets(explode(',', (string)($row['reference'] ?? '')));
    }

    private static function encodeSecrets(array $secrets): string {
        return implode(',', self::normalizeSecrets($secrets));
    }

    /** @return string[] */
    private static function normalizeSecrets(array $secrets): array {
        $out = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Browser-safe shape. The token is bearer money and `reference` is the
     * list of proof secrets, so neither rides a list payload; the token is
     * fetched explicitly (admin action transfer_token).
     */
    public static function formatForApi(array $row): array {
        return [
            'id' => (string)$row['id'],
            'type' => (string)$row['type'],
            'amount' => (int)$row['amount'],
            'unit' => (string)$row['unit'],
            'mintUrl' => $row['mint_url'] ?? null,
            'status' => (string)$row['status'],
            'note' => $row['note'] ?? null,
            'hasToken' => !empty($row['token']),
            'proofCount' => count(self::secretsOf($row)),
            'createdTime' => (int)$row['created_at'],
            'updatedTime' => isset($row['updated_at']) ? (int)$row['updated_at'] : (int)$row['created_at'],
        ];
    }
}
