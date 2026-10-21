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
    /** A token is being swapped for this request; it names the proofs paying it. */
    const STATUS_RECEIVING = 'receiving';
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
            'id = ? AND status IN (?, ?)',
            [$request['id'], self::STATUS_PENDING, self::STATUS_RECEIVING]
        );

        return $changed === 1;
    }

    /**
     * Bind a request to the proofs about to pay it, before any swap.
     *
     * Returns 'claimed' (this call bound it), 'same' (already bound to exactly these
     * proofs: a retry of the same token), 'busy' (another token is paying it) or
     * 'paid'. Two different tokens can no longer both pay one request, and a retry
     * after a lost response is answered from what happened to *these* proofs.
     *
     * @param string[] $secrets
     */
    public static function claim(array $request, array $secrets): string {
        $key = self::secretsKey($secrets);
        $claimed = Database::update(
            'payment_requests',
            ['status' => self::STATUS_RECEIVING, 'input_secrets' => $key, 'receiving_since' => time()],
            'id = ? AND status = ?',
            [$request['id'], self::STATUS_PENDING]
        );
        if ($claimed === 1) {
            return 'claimed';
        }
        $current = self::getById((string)$request['id']);
        if ($current === null) {
            return 'busy';
        }
        if ($current['status'] === self::STATUS_PAID) {
            return 'paid';
        }
        return ($current['status'] === self::STATUS_RECEIVING && hash_equals((string)$current['input_secrets'], $key))
            ? 'same' : 'busy';
    }

    /** Undo a claim whose token was refused, so the request can be paid again. */
    public static function release(array $request, array $secrets): void {
        Database::update(
            'payment_requests',
            ['status' => self::STATUS_PENDING, 'input_secrets' => null, 'receiving_since' => null],
            'id = ? AND status = ? AND input_secrets = ?',
            [$request['id'], self::STATUS_RECEIVING, self::secretsKey($secrets)]
        );
    }

    /**
     * What happened to the proofs a claimed request is bound to, from the wallet itself.
     *
     * 'paid'     every input is SPENT in our wallet and no swap still holds it: the
     *            swap finished (possibly via the background recovery). $amount is what
     *            it credited (inputs minus the mint's input fee).
     * 'released' none of them is ours and no swap holds them: the mint refused the
     *            token, or it never got that far.
     * 'pending'  a swap journal still holds them, or the picture is mixed: wait.
     *
     * Only rows created at or after $since (the claim's receiving_since) count. A token
     * the wallet already held before this claim (a donation, or the payment for another
     * request) left its inputs SPENT long ago; counting those marked a request paid with
     * no new funds (C3/M-1). storeProofs() and preparePendingSpend() never overwrite an
     * existing row's created_at, so such secrets stay invisible here.
     *
     * @param string[] $secrets
     * @param int      $since   receiving_since of the claim bound to these secrets
     * @return array{state: string, amount: int}
     */
    public static function receiptState(\Cashu\Wallet $wallet, array $secrets, int $since): array {
        $storage = $wallet->getStorage();
        $reserved = array_flip($storage->getReservedInputSecrets());
        foreach ($secrets as $secret) {
            if (isset($reserved[$secret])) {
                return ['state' => 'pending', 'amount' => 0];
            }
        }
        $in = implode(',', array_fill(0, count($secrets), '?'));
        $rows = Database::fetchAll(
            "SELECT secret, state FROM cashu_proofs WHERE wallet_id = ? AND created_at >= ? AND secret IN ($in)",
            array_merge([$storage->getWalletId(), $since], $secrets)
        );
        if (empty($rows)) {
            return ['state' => 'released', 'amount' => 0];
        }
        $spent = array_filter($rows, fn($r) => $r['state'] === \Cashu\ProofState::SPENT);
        if (count($spent) === count($secrets)) {
            $proofs = $storage->getProofsBySecretsAsObjects($secrets);
            $amount = \Cashu\Wallet::sumProofs($proofs) - $wallet->calculateFee($proofs);
            return ['state' => 'paid', 'amount' => max(0, $amount)];
        }
        if (count(array_filter($rows, fn($r) => $r['state'] === \Cashu\ProofState::UNSPENT)) === count($rows)
            && count($rows) === count($secrets) && empty($spent)) {
            // Stored as ours but never swapped and no journal: not a payment we hold.
            return ['state' => 'released', 'amount' => 0];
        }
        return ['state' => 'pending', 'amount' => 0];
    }

    /**
     * Whether the wallet already knows any of these secrets (a stored proof row, or a
     * swap journal holding it). Asked right after a fresh claim: a token this shop has
     * already received can never be the payment for a new request (C3/M-1). Asked only
     * on a fresh bind, never on a retry of the same claim, so a lost response is still
     * answered from the wallet.
     *
     * @param string[] $secrets
     */
    public static function secretsKnown(\Cashu\Wallet $wallet, array $secrets): bool {
        $storage = $wallet->getStorage();
        $reserved = array_flip($storage->getReservedInputSecrets());
        foreach ($secrets as $secret) {
            if (isset($reserved[$secret])) {
                return true;
            }
        }
        $in = implode(',', array_fill(0, count($secrets), '?'));
        $row = Database::fetchOne(
            "SELECT 1 AS known FROM cashu_proofs WHERE wallet_id = ? AND secret IN ($in) LIMIT 1",
            array_merge([$storage->getWalletId()], $secrets)
        );
        return !empty($row);
    }

    /**
     * Background step: finish requests whose swap the wallet recovery completed (or
     * the mint refused) after the sender had already been answered.
     *
     * @return array{checked: int, paid: int, released: int, errors: int}
     */
    public static function reconcileReceiving(): array {
        require_once __DIR__ . '/invoice.php';
        $result = ['checked' => 0, 'paid' => 0, 'released' => 0, 'errors' => 0];
        $rows = Database::fetchAll(
            'SELECT * FROM payment_requests WHERE status = ? ORDER BY receiving_since ASC LIMIT 20',
            [self::STATUS_RECEIVING]
        );
        foreach ($rows as $request) {
            $result['checked']++;
            try {
                $secrets = json_decode((string)$request['input_secrets'], true);
                if (!is_array($secrets) || $secrets === []) {
                    continue;
                }
                $wallet = Invoice::getWalletForStore((string)$request['store_id'], (string)$request['mint_url'], (string)$request['unit']);
                $wallet->loadMint();
                $state = self::receiptState($wallet, $secrets, (int)($request['receiving_since'] ?? PHP_INT_MAX));
                if ($state['state'] === 'paid') {
                    $response = [
                        'success' => true,
                        'id' => $request['id'],
                        'amount' => $state['amount'],
                        'unit' => $request['unit'],
                    ];
                    if (self::markPaid($request, $state['amount'], $response)) {
                        $result['paid']++;
                        error_log("CashuPayServer: payment request {$request['id']} paid (recovered): {$state['amount']} {$request['unit']}");
                    }
                } elseif ($state['state'] === 'released') {
                    self::release($request, $secrets);
                    $result['released']++;
                }
            } catch (Throwable $e) {
                $result['errors']++;
                error_log("CashuPayServer: payment request {$request['id']} check failed: " . $e->getMessage());
            }
        }
        return $result;
    }

    /** @param string[] $secrets */
    private static function secretsKey(array $secrets): string {
        $secrets = array_values(array_unique(array_map('strval', $secrets)));
        sort($secrets, SORT_STRING);
        return json_encode($secrets);
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
