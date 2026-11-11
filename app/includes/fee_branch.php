<?php
/**
 * CashuPayServer — per-install BIP32 branch for dev-fee on-chain addresses
 *
 * The dev fee's on-chain destination is one BareBits zpub shared by every
 * install (CASHUPAY_DEV_FEE_ONCHAIN_XPUB). Each install keeps its own address
 * index counter, so two installs would otherwise derive the SAME address at
 * the same index — and once a customer of install A paid it, install B's
 * invoice on that address would also look paid (the poller sums every UTXO
 * at an xpub-derived address). To keep addresses unique, each install asks
 * the BareBits endpoint (CASHUPAY_DEV_FEE_BRANCH_ENDPOINT, getbarebits.com/
 * xpub.php) for a sequential branch number once, and from then on derives
 * fee addresses at
 *
 *     <zpub> / <branch> / 0 / <index>
 *
 * instead of the shared  <zpub> / 0 / <index>  chain. BareBits sweeps every
 * branch with one Bitcoin Core descriptor wallet (one wpkh(xpub/<b>/0/*)
 * descriptor per branch, see scripts/fee-branch-descriptors.php).
 *
 * Failure policy: the endpoint is best-effort. Until a branch is stored the
 * dev fee simply does not take the on-chain rail of any invoice (the rail
 * stays with the merchant; the fee keeps accruing and is collected by the
 * cron settlement or a later redirect). Deriving on the shared chain instead
 * would recreate the very collision this exists to prevent. The cron tick
 * retries on every run; invoice creation also tries inline when a fee
 * redirect is about to need the branch, throttled to one attempt per
 * INLINE_RETRY_INTERVAL_SEC so a dead endpoint cannot add its timeout to
 * every checkout.
 *
 * The request carries nothing but a fixed client marker (so the endpoint can
 * tell an install from a stray spider); see PRIVACY.md section 1. Test rigs
 * never contact the live endpoint: every fixture sets
 * CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled explicitly, and as a backstop an
 * install whose data dir carries the fixtures' `.updater_disabled` sentinel
 * stays off unless the env names an endpoint. (The env var
 * CASHUPAY_UPDATER_DISABLED alone is NOT a test signal: the Windows desktop
 * launcher sets it for real users.)
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safe_http.php';

// Guarded fallback — canonically defined in includes/dev_fee.php next to the
// other dev-fee destinations (same env-override-for-tests rule, same license
// note). Keep the two defaults in sync.
if (!defined('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT')) {
    $fbEnv = getenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT');
    define('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT',
        ($fbEnv !== false && $fbEnv !== '') ? $fbEnv : 'https://getbarebits.com/xpub/xpub.php');
    unset($fbEnv);
}

final class FeeBranch {
    public const CONFIG_BRANCH          = 'dev_fee_onchain_branch';
    public const CONFIG_ACQUIRED_AT     = 'dev_fee_branch_acquired_at';
    public const CONFIG_LAST_ATTEMPT_AT = 'dev_fee_branch_last_attempt_at';
    public const CONFIG_LAST_ERROR      = 'dev_fee_branch_last_error';

    /** BIP32 non-hardened child range; 0 is reserved to mean "no branch". */
    public const MIN_BRANCH = 1;
    public const MAX_BRANCH = 2147483647;

    /** Form field the endpoint requires before it allocates anything. */
    public const CLIENT_MARKER = 'barebits-payserver';

    /** Inline (invoice-creation) attempts are spaced at least this far apart. */
    public const INLINE_RETRY_INTERVAL_SEC = 60;

    public const DEFAULT_TIMEOUT_SEC = 5;
    public const DEFAULT_CONNECT_TIMEOUT_SEC = 3;

    /** The branch this install derives dev-fee addresses under, or null. */
    public static function current(): ?int {
        $v = Config::get(self::CONFIG_BRANCH, null);
        return self::isValidBranch($v) ? (int)$v : null;
    }

    /** Strict range check on whatever came back from config or the wire. */
    public static function isValidBranch(mixed $v): bool {
        if (is_int($v)) {
            return $v >= self::MIN_BRANCH && $v <= self::MAX_BRANCH;
        }
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v) === 1) {
            $n = (int)$v;
            return $n >= self::MIN_BRANCH && $n <= self::MAX_BRANCH;
        }
        return false;
    }

    public static function endpointUrl(): string {
        return trim((string)CASHUPAY_DEV_FEE_BRANCH_ENDPOINT);
    }

    /**
     * True when no allocation request must be made: the endpoint constant is
     * switched off, or this is a test-fixture install (sentinel file) that
     * never named an endpoint explicitly — so a suite can't burn branch
     * numbers on the live service.
     */
    public static function isDisabled(): bool {
        $url = strtolower(self::endpointUrl());
        if (in_array($url, ['', 'disabled', 'off', '0', 'none'], true)) {
            return true;
        }
        $env = getenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT');
        if (($env === false || $env === '') && self::testFixtureSentinelPresent()) {
            return true;
        }
        return false;
    }

    /**
     * tests/fixtures/payserver.py (and the swap rig) drop `.updater_disabled`
     * into the data dir of every server they boot. Real installs never have
     * it — the desktop launcher disables the updater via env only.
     */
    private static function testFixtureSentinelPresent(): bool {
        $dataDir = defined('CASHUPAY_DATA_DIR')
            ? (string)CASHUPAY_DATA_DIR
            : (string)(getenv('CASHUPAY_DATA_DIR') ?: '');
        return $dataDir !== '' && is_file($dataDir . '/.updater_disabled');
    }

    /**
     * Return the stored branch, or try to obtain one now. Never throws; a
     * failed attempt is recorded in config (last attempt time + error) and
     * yields null so the caller skips the dev fee's on-chain rail.
     *
     * Options:
     *   - throttle: bool (default false). When true, skip the request if the
     *     previous attempt was less than INLINE_RETRY_INTERVAL_SEC ago. The
     *     cron passes false (retry every tick); invoice creation passes true.
     *   - timeout / connectTimeout: seconds.
     */
    public static function ensure(array $opts = []): ?int {
        $existing = self::current();
        if ($existing !== null) {
            return $existing;
        }
        if (self::isDisabled()) {
            return null;
        }
        $now = time();
        if (!empty($opts['throttle'])) {
            $last = (int)Config::get(self::CONFIG_LAST_ATTEMPT_AT, 0);
            if ($last > 0 && ($now - $last) < self::INLINE_RETRY_INTERVAL_SEC) {
                return null;
            }
        }
        Config::set(self::CONFIG_LAST_ATTEMPT_AT, $now);

        $resp = SafeHttp::request(self::endpointUrl(), [
            'method'         => 'POST',
            'headers'        => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'body'           => http_build_query(['client' => self::CLIENT_MARKER]),
            'timeout'        => (int)($opts['timeout'] ?? self::DEFAULT_TIMEOUT_SEC),
            'connectTimeout' => (int)($opts['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_SEC),
            'userAgent'      => 'cashupayserver-fee-branch',
            // Test rigs point the endpoint at 127.0.0.1; production is https.
            'allowPrivate'   => SafeHttp::privateEndpointsAllowed(),
            'maxBytes'       => 4096,
        ]);

        try {
            $branch = self::parseAllocation((int)$resp['status'], (string)$resp['body'], (string)($resp['error'] ?? ''));
        } catch (\RuntimeException $e) {
            Config::set(self::CONFIG_LAST_ERROR, $e->getMessage());
            error_log('[fee-branch] allocation failed: ' . $e->getMessage() . ' (will retry)');
            return null;
        }
        return self::store($branch, $now);
    }

    /**
     * Turn an endpoint response into a branch number or throw with a
     * diagnosable reason. Exposed for unit tests.
     */
    public static function parseAllocation(int $status, string $body, string $error = ''): int {
        if ($status === 0) {
            throw new \RuntimeException('endpoint unreachable: ' . ($error !== '' ? $error : 'no response'));
        }
        if ($status === 429) {
            $decoded = json_decode($body, true);
            $retry = is_array($decoded) ? (int)($decoded['retry_after'] ?? 0) : 0;
            throw new \RuntimeException('endpoint rate-limited this address' . ($retry > 0 ? " (retry after {$retry}s)" : ''));
        }
        if ($status !== 200) {
            throw new \RuntimeException("endpoint returned HTTP {$status}");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('branch', $decoded)) {
            throw new \RuntimeException('endpoint returned an unparseable body');
        }
        if (!is_int($decoded['branch']) || !self::isValidBranch($decoded['branch'])) {
            throw new \RuntimeException('endpoint returned an invalid branch: ' . json_encode($decoded['branch']));
        }
        return $decoded['branch'];
    }

    /**
     * Persist a freshly allocated branch unless another process stored one
     * in the meantime (two concurrent first-time requests can both reach the
     * endpoint; the loser's number is simply left unused — harmless, the
     * sweep wallet just sees an empty branch). Read-before-write, so take the
     * write lock up front.
     */
    private static function store(int $branch, int $now): int {
        Database::beginImmediate();
        try {
            $row = Database::fetchOne("SELECT value FROM config WHERE key = ?", [self::CONFIG_BRANCH]);
            $already = $row !== null ? json_decode((string)$row['value'], true) : null;
            if (self::isValidBranch($already)) {
                Database::getInstance()->commit();
                error_log("[fee-branch] branch {$already} was stored concurrently; discarding fetched branch {$branch}");
                Config::set(self::CONFIG_BRANCH, (int)$already); // refresh the per-process cache
                return (int)$already;
            }
            Config::set(self::CONFIG_BRANCH, $branch);
            Config::set(self::CONFIG_ACQUIRED_AT, $now);
            Config::delete(self::CONFIG_LAST_ERROR);
            Database::getInstance()->commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::getInstance()->rollBack();
            }
            throw $e;
        }
        error_log("[fee-branch] acquired branch {$branch}; dev-fee addresses now derive at {$branch}/0/<index>");
        return $branch;
    }

    /** Cron entry point: one unthrottled attempt per tick until stored. */
    public static function cronTick(): string {
        $have = self::current();
        if ($have !== null) {
            return "ok (branch {$have})";
        }
        if (self::isDisabled()) {
            return 'disabled';
        }
        $got = self::ensure(['throttle' => false]);
        if ($got !== null) {
            return "acquired (branch {$got})";
        }
        return 'error: ' . (string)Config::get(self::CONFIG_LAST_ERROR, 'allocation failed');
    }

    /** Read-only summary for the admin stats card and diagnostics. */
    public static function status(): array {
        $branch = self::current();
        return [
            'branch'          => $branch,
            'acquired_at'     => $branch !== null ? (int)Config::get(self::CONFIG_ACQUIRED_AT, 0) ?: null : null,
            'last_attempt_at' => (int)Config::get(self::CONFIG_LAST_ATTEMPT_AT, 0) ?: null,
            'last_error'      => $branch !== null ? null : (Config::get(self::CONFIG_LAST_ERROR, null) ?: null),
            'disabled'        => self::isDisabled(),
            'endpoint'        => self::isDisabled() ? null : self::endpointUrl(),
        ];
    }
}
