<?php
/**
 * CashuPayServer - Store Lightning Address list
 *
 * A store can have multiple Lightning destinations tried in an ordered
 * priority (fallback) chain — Strike API keys, Lightning addresses (LUD-16),
 * NWC connections (NIP-47), and CLINK noffers (NIP-69). They are used for two
 * things:
 *
 *   1. Receiving — Invoice::create walks the list and presents the first
 *      destination that produces a working invoice (LUD-21 direct-receive
 *      for addresses, make_invoice for NWC, NIP-69 request for noffers),
 *      falling through when a host is down or can't produce one.
 *   2. Withdrawing — auto-melt (LightningAddress::checkAutoMelt) and the
 *      override-settle-and-forward handler melt to the first destination
 *      that accepts the payment.
 *
 * Storage is the store_ln_addresses table, ordered by `position` ASC. This
 * replaced the single stores.auto_melt_address column.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/clink/noffer.php';
require_once __DIR__ . '/nwc/uri.php';
require_once __DIR__ . '/lnurl_receive.php';
require_once __DIR__ . '/strike/client.php';
require_once __DIR__ . '/rail_order.php';

class StoreLnAddresses {
    /** Destination types stored in store_ln_addresses.type. */
    public const TYPE_LNADDRESS = 'lnaddress';
    public const TYPE_NOFFER = 'noffer';
    public const TYPE_NWC = 'nwc';
    public const TYPE_STRIKE = 'strike';

    /**
     * Prefix of the opaque reference the admin/setup UIs post in place of a
     * stored NWC connection string. NWC URIs embed a secret, so browsers only
     * ever receive a masked label plus `keep:<row-id>`; resolveKeepRefs()
     * turns the ref back into the stored value server-side on save.
     */
    public const KEEP_REF_PREFIX = 'keep:';

    /**
     * User/log-safe representation of a destination value. Addresses and
     * noffers are public strings and pass through; NWC connection URIs embed
     * the wallet secret and Strike API keys ARE the secret — both collapse to
     * their masked label. Every consumer that surfaces a chain value outside
     * the server (dashboard payloads, notifications, melt log, error_log
     * lines) must go through this.
     */
    public static function displayValue(string $type, string $value): string {
        if ($type === self::TYPE_NWC) {
            return NwcUri::displayLabel($value);
        }
        if ($type === self::TYPE_STRIKE) {
            return StrikeClient::maskKey($value);
        }
        return $value;
    }

    /** Human label for a destination type, for validation error messages. */
    private static function typeLabel(string $type): string {
        if ($type === self::TYPE_NOFFER) {
            return 'noffer';
        }
        if ($type === self::TYPE_NWC) {
            return 'NWC connection';
        }
        if ($type === self::TYPE_STRIKE) {
            return 'Strike API key';
        }
        return 'Lightning address';
    }

    /**
     * Chain-wide duplicate-detection key. Addresses/noffers fold case (bech32
     * noffers are lowercase already); NWC URIs collapse to wallet pubkey +
     * secret so re-pastes with reordered query params still count as the same
     * connection.
     */
    private static function dedupKeyFor(string $type, string $value): string {
        if ($type === self::TYPE_NWC && NwcUri::isValid($value)) {
            return self::TYPE_NWC . ':' . NwcUri::dedupKey($value);
        }
        if ($type === self::TYPE_NWC) {
            return self::TYPE_NWC . ':' . strtolower($value);
        }
        if ($type === self::TYPE_STRIKE) {
            // API keys are case-sensitive credentials — no case folding.
            return self::TYPE_STRIKE . ':' . $value;
        }
        return strtolower($value);
    }

    /**
     * Lightning-address shape check (LUD-16: local-part@host.tld). Kept in
     * sync with the regex used by Invoice::create and LnUrlReceive so the
     * admin/setup validators and the runtime resolvers agree on what's valid.
     */
    public static function isValid(string $address): bool {
        return (bool)preg_match(
            '/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/',
            trim($address)
        );
    }

    /**
     * Validate a destination value for a given type: LUD-16 for 'lnaddress',
     * a structurally valid noffer for 'noffer'. The single gate both the admin
     * save handler and replaceForStore() use.
     */
    public static function isValidEntry(string $type, string $value): bool {
        $value = trim($value);
        if ($type === self::TYPE_NOFFER) {
            return ClinkNoffer::isValid($value);
        }
        if ($type === self::TYPE_NWC) {
            return NwcUri::isValid($value);
        }
        if ($type === self::TYPE_STRIKE) {
            return StrikeClient::isValidKey($value);
        }
        return self::isValid($value);
    }

    /**
     * Resolve `keep:<row-id>` references in a UI-posted secret-bearing list
     * (NWC connections or Strike API keys) back to the stored values. A ref
     * must name a row that belongs to this store AND is of the expected type —
     * anything else throws, so a forged or stale ref can never smuggle
     * another store's (or another type's) secret into this chain. Full values
     * (new entries) pass through untouched.
     *
     * Returns [values (resolved, chain order), keptKeys (dedup keys of the
     * resolved rows — probeAndGateChain treats those as already-stored)].
     *
     * @param string[] $values raw posted entries
     * @return array{0: string[], 1: array<string,bool>}
     */
    public static function resolveKeepRefs(string $storeId, array $values, string $type = self::TYPE_NWC): array {
        $byId = [];
        foreach (self::listForStore($storeId) as $row) {
            if ($row['type'] === $type) {
                $byId[$row['id']] = $row['address'];
            }
        }
        $resolved = [];
        $keptKeys = [];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v === '') {
                continue;
            }
            if (str_starts_with($v, self::KEEP_REF_PREFIX)) {
                $id = (int)substr($v, strlen(self::KEEP_REF_PREFIX));
                if (!isset($byId[$id])) {
                    throw new InvalidArgumentException(
                        'Unknown ' . self::typeLabel($type) . ' reference — reload the page and try again.'
                    );
                }
                $resolved[] = $byId[$id];
                $keptKeys[self::dedupKeyFor($type, $byId[$id])] = true;
                continue;
            }
            $resolved[] = $v;
        }
        return [$resolved, $keptKeys];
    }

    /**
     * Build an ordered, validated destination chain from the separate operator
     * lists kept apart in the admin UI: Strike API keys first, then Lightning
     * addresses, then NWC connections, then CLINK noffers as final fallback.
     * That grouping is only the STORED order — the order Invoice::create
     * walks at runtime is the store's configurable type order, applied by
     * listForStore() at read time (RailOrder; default matches this
     * grouping). Within a type, this list order is the runtime order.
     *
     * Each value is trimmed and blanks are dropped; each is validated against
     * its declared type (so a noffer pasted into the address list, or an
     * address pasted into the noffer list, is rejected with a clear message);
     * duplicates across all lists throw. Returns
     * [['type'=>string,'value'=>string], ...] in priority order — the shape the
     * save handler's probe loop and replaceForStore() consume.
     *
     * NWC values must be full connection URIs and Strike entries full API keys
     * here — callers resolve the UI's `keep:` references via resolveKeepRefs()
     * first.
     */
    public static function chainFromLists(array $lnAddresses, array $noffers, array $nwcConnections = [], array $strikeKeys = []): array {
        $typed = [];
        foreach ($strikeKeys as $v) {
            $typed[] = [self::TYPE_STRIKE, (string)$v];
        }
        foreach ($lnAddresses as $v) {
            $typed[] = [self::TYPE_LNADDRESS, (string)$v];
        }
        foreach ($nwcConnections as $v) {
            $typed[] = [self::TYPE_NWC, (string)$v];
        }
        foreach ($noffers as $v) {
            $typed[] = [self::TYPE_NOFFER, (string)$v];
        }
        $out = [];
        $seen = [];
        foreach ($typed as [$type, $raw]) {
            $val = trim($raw);
            if ($val === '') {
                continue;
            }
            if (!self::isValidEntry($type, $val)) {
                // Never echo an NWC or Strike paste back — a malformed one may
                // still carry (or be) the secret.
                $shown = ($type === self::TYPE_NWC || $type === self::TYPE_STRIKE) ? '(value hidden)' : $val;
                throw new InvalidArgumentException('Invalid ' . self::typeLabel($type) . " format: {$shown}");
            }
            $key = self::dedupKeyFor($type, $val);
            if (isset($seen[$key])) {
                $shown = self::displayValue($type, $val);
                throw new InvalidArgumentException("Duplicate destination: {$shown}");
            }
            $seen[$key] = true;
            $out[] = ['type' => $type, 'value' => $val];
        }
        return $out;
    }

    /**
     * Save-time probe + LUD-21 gate for an ordered destination chain
     * ([['type'=>string,'value'=>string], ...] as produced by
     * chainFromLists). The single gate both the admin save handler and
     * the setup lightning step run before persisting a chain.
     *
     * Every lnaddress is probed. A NEW address (not already stored for
     * this store) must confirm LUD-21 verify support: without a verify
     * URL this server can't detect settlement (it doesn't run the LN
     * node), so direct-receive would silently drop Lightning from the
     * checkout and the operator would only find out from a customer.
     * "Host unreachable" blocks too — at entry time that's usually a
     * typo'd domain, and we can't tell it apart from a dead host.
     *
     * Addresses already stored pass through with a refreshed,
     * non-blocking probe result (the per-address operator warning keeps
     * working), so an operator editing the rest of the card is never
     * locked out by a host that has since gone dark. noffers settle via
     * Nostr receipts, not LUD-21 — they skip the probe entirely.
     *
     * NWC connections get the analogous treatment over NIP-47: a NEW
     * connection must pass NwcClient::probeConnection (a real 1-sat
     * make_invoice + lookup_invoice round trip) — if the wallet can't
     * mint and re-find invoices at save time, the rail could never
     * settle a checkout. Already-stored connections are grandfathered
     * without re-probing (each probe mints a visible test invoice in
     * the merchant's wallet, so re-running it on every unrelated card
     * edit would be noise). The probe's spend-permission warning is
     * passed through in `results` for the UI; it never blocks.
     *
     * Returns ['entries' => rows for replaceForStore(),
     *          'results' => [['address','type','lud21Support','warning'], ...]]
     * with both lists in chain order. For nwc rows, `address` in
     * `results` is the masked display label — raw connection URIs never
     * leave the server.
     */
    public static function probeAndGateChain(string $storeId, array $destinations): array {
        $stored = [];
        $storedNwc = [];
        $storedStrike = [];
        foreach (self::listForStore($storeId) as $row) {
            if ($row['type'] === self::TYPE_LNADDRESS) {
                $stored[strtolower($row['address'])] = true;
            } elseif ($row['type'] === self::TYPE_NWC) {
                $storedNwc[self::dedupKeyFor(self::TYPE_NWC, $row['address'])] = true;
            } elseif ($row['type'] === self::TYPE_STRIKE) {
                $storedStrike[self::dedupKeyFor(self::TYPE_STRIKE, $row['address'])] = true;
            }
        }
        $entries = [];
        $results = [];
        foreach ($destinations as $dest) {
            if ($dest['type'] === self::TYPE_NOFFER) {
                $entries[] = ['type' => self::TYPE_NOFFER, 'address' => $dest['value'], 'supports_verify' => null];
                $results[] = ['address' => $dest['value'], 'type' => 'noffer', 'lud21Support' => null];
                continue;
            }
            if ($dest['type'] === self::TYPE_STRIKE) {
                // Same treatment as NWC: a NEW key must pass a real probe —
                // one 1-sat create + quote + read round trip exercising all
                // three scopes the rail needs (a key missing a scope could
                // never mint or settle a checkout invoice). Already-stored
                // keys are grandfathered without re-probing (each probe
                // leaves a visible 1-sat unpaid invoice in the merchant's
                // Strike history, so re-running it on every unrelated card
                // edit would be noise).
                $label = StrikeClient::maskKey($dest['value']);
                if (!isset($storedStrike[self::dedupKeyFor(self::TYPE_STRIKE, $dest['value'])])) {
                    $probe = StrikeClient::probeKey($dest['value']);
                    if (!$probe['ok']) {
                        throw new RuntimeException(
                            "{$label} can't be saved: " . $probe['error'] . '. Check that the key '
                            . 'was copied completely and has the "create invoices", "generate '
                            . 'invoice quotes" and "read invoices" scopes, then try saving again.'
                        );
                    }
                }
                $entries[] = ['type' => self::TYPE_STRIKE, 'address' => $dest['value'], 'supports_verify' => null];
                $results[] = ['address' => $label, 'type' => 'strike', 'lud21Support' => null];
                continue;
            }
            if ($dest['type'] === self::TYPE_NWC) {
                require_once __DIR__ . '/nwc/client.php';
                $label = NwcUri::displayLabel($dest['value']);
                $warning = null;
                if (!isset($storedNwc[self::dedupKeyFor(self::TYPE_NWC, $dest['value'])])) {
                    $probe = NwcClient::probeConnection($dest['value']);
                    if (!$probe['ok']) {
                        throw new RuntimeException(
                            "{$label} can't be saved: the wallet didn't pass the connection test ("
                            . $probe['error'] . '). Check the connection string and that the wallet '
                            . 'and its relay are online, then try saving again.'
                        );
                    }
                    $warning = $probe['warning'];
                }
                $entries[] = ['type' => self::TYPE_NWC, 'address' => $dest['value'], 'supports_verify' => null];
                $results[] = ['address' => $label, 'type' => 'nwc', 'lud21Support' => null, 'warning' => $warning];
                continue;
            }
            $support = null;
            try {
                $support = LnUrlReceive::probeLud21Support($dest['value']);
            } catch (Throwable $e) {
                error_log('[lnurl-receive] LUD-21 save-time probe threw: ' . $e->getMessage());
            }
            if ($support !== 1 && !isset($stored[strtolower($dest['value'])])) {
                if ($support === 0) {
                    // Strike addresses are the common way to hit this wall;
                    // point those operators at the rail that does work.
                    $strikeHint = preg_match('/@strike\.me$/i', trim($dest['value']))
                        ? ' Strike addresses don\'t support LUD-21 — use the Strike API'
                          . ' option instead (create an API key at dashboard.strike.me).'
                        : '';
                    throw new RuntimeException(
                        "{$dest['value']} can't be saved: its wallet host doesn't support "
                        . 'payment verification (LUD-21 verify), so Lightning payments to it '
                        . 'could never be confirmed at checkout. Use a wallet whose Lightning '
                        . 'address supports LUD-21.' . $strikeHint
                    );
                }
                throw new RuntimeException(
                    "Couldn't verify {$dest['value']}: the wallet host didn't respond to a "
                    . 'Lightning-address lookup. Check the spelling and that the host is '
                    . 'online, then try saving again.'
                );
            }
            $entries[] = ['type' => self::TYPE_LNADDRESS, 'address' => $dest['value'], 'supports_verify' => $support];
            $results[] = ['address' => $dest['value'], 'type' => 'lnaddress', 'lud21Support' => $support];
        }
        return ['entries' => $entries, 'results' => $results];
    }

    /**
     * Full ordered list for a store: [['id'=>int,'address'=>string,
     * 'type'=>string,'supports_verify'=>?int], ...] in effective priority
     * order. When the store has an explicit type order configured
     * (stores.ln_rail_order — see RailOrder), the stored rows are
     * stable-sorted by it: types in the configured order, rows of the same
     * type keeping their stored position order. Sorting here, at read time,
     * means every chain consumer (Invoice::create, auto-melt,
     * settle-and-forward, primaryForStore) follows an order change instantly
     * without rewriting the stored chain. A store with no explicit order
     * (NULL — every pre-existing store) gets the stored per-row order
     * verbatim: the exact historical behavior, and the only way a chain
     * written through the legacy shape-classified API contract can keep a
     * deliberate cross-type interleaving.
     */
    public static function listForStore(string $storeId): array {
        $rows = Database::fetchAll(
            "SELECT id, address, type, supports_verify
               FROM store_ln_addresses
              WHERE store_id = ?
              ORDER BY position ASC, id ASC",
            [$storeId]
        );
        $rows = array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'address' => (string)$r['address'],
                'type' => (string)($r['type'] ?? self::TYPE_LNADDRESS),
                'supports_verify' => $r['supports_verify'] === null ? null : (int)$r['supports_verify'],
            ];
        }, $rows);
        $orderCsv = RailOrder::storedLnOrderCsv($storeId);
        if ($orderCsv !== null) {
            $rows = RailOrder::sortDestinations(
                $rows, RailOrder::lnOrderFromRow(['ln_rail_order' => $orderCsv])
            );
        }
        return $rows;
    }

    /**
     * Ordered typed destinations for the resolvers that walk the chain and act
     * per type: [['type'=>string,'value'=>string,'supports_verify'=>?int], ...]
     * in priority order.
     */
    public static function destinationsForStore(string $storeId): array {
        return array_map(static function (array $r): array {
            return [
                'type' => $r['type'],
                'value' => $r['address'],
                'supports_verify' => $r['supports_verify'],
            ];
        }, self::listForStore($storeId));
    }

    /**
     * Ordered plain destination strings for a store (priority first), across
     * both types. Used for capability checks (is there any payout target?) and
     * for representative display in failure notifications.
     */
    public static function addressesForStore(string $storeId): array {
        return array_map(
            static fn(array $r): string => $r['address'],
            self::listForStore($storeId)
        );
    }

    /**
     * The highest-priority (position 0) address, or null when the store has
     * none. Used where a single representative address is needed (e.g. failure
     * notifications).
     */
    public static function primaryForStore(string $storeId): ?string {
        $list = self::addressesForStore($storeId);
        return $list[0] ?? null;
    }

    /**
     * Replace a store's entire ordered list in one transaction. Entries are
     * applied in array order as positions 0..n-1. Each entry is either a plain
     * address string or ['address'=>string, 'supports_verify'=>?int].
     *
     * Blank entries are skipped; duplicates (case-insensitive on the address)
     * are rejected so the fallback chain never contains the same host twice.
     * Invalid addresses throw — callers validate first for nicer messages, but
     * this is the last line of defence.
     */
    public static function replaceForStore(string $storeId, array $entries): void {
        $normalized = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $address = trim((string)($entry['address'] ?? $entry['value'] ?? ''));
                $type = (string)($entry['type'] ?? self::TYPE_LNADDRESS);
                $verify = $entry['supports_verify'] ?? null;
            } else {
                $address = trim((string)$entry);
                $type = self::TYPE_LNADDRESS;
                $verify = null;
            }
            if ($address === '') {
                continue;
            }
            if ($type !== self::TYPE_LNADDRESS && $type !== self::TYPE_NOFFER
                    && $type !== self::TYPE_NWC && $type !== self::TYPE_STRIKE) {
                throw new InvalidArgumentException("Invalid destination type: {$type}");
            }
            if (!self::isValidEntry($type, $address)) {
                $shown = ($type === self::TYPE_NWC || $type === self::TYPE_STRIKE) ? '(value hidden)' : $address;
                throw new InvalidArgumentException('Invalid ' . self::typeLabel($type) . ": {$shown}");
            }
            // Dedup across the whole chain so the same destination never
            // appears twice: case-folded for addresses/noffers, pubkey+secret
            // identity for NWC connections, exact key for Strike.
            $key = self::dedupKeyFor($type, $address);
            if (isset($seen[$key])) {
                $shown = self::displayValue($type, $address);
                throw new InvalidArgumentException("Duplicate destination: {$shown}");
            }
            $seen[$key] = true;
            // supports_verify only has meaning for LNURL/LUD-21; force NULL
            // for noffers/NWC so the column isn't misread by the resolvers.
            $normalized[] = [
                'address' => $address,
                'type' => $type,
                'supports_verify' => ($type === self::TYPE_LNADDRESS && $verify !== null)
                    ? (int)$verify : null,
            ];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM store_ln_addresses WHERE store_id = ?");
            $del->execute([$storeId]);
            $ins = $pdo->prepare(
                "INSERT INTO store_ln_addresses (store_id, position, address, type, supports_verify)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($normalized as $i => $row) {
                $ins->execute([$storeId, $i, $row['address'], $row['type'], $row['supports_verify']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
