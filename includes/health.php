<?php
/**
 * CashuPayServer - Health check for uptime monitoring
 *
 * One read-only snapshot of whether this install can take payments right now. It is
 * served by health.php in two shapes:
 *
 *  - public: {"status": "ok" | "degraded" | "unavailable"} and nothing else. No version,
 *    no store or mint names, no paths, no timings. Anyone can already tell whether the
 *    checkout works by opening it; this says no more than that.
 *  - detailed: the individual checks and the background heartbeat, for the logged-in
 *    operator or a caller presenting the monitoring key (or the cron key).
 *
 * HTTP status: 200 for "ok" and "degraded" (the shop still takes payments, something
 * needs a look), 503 for "unavailable" (it does not, or soon will not).
 *
 * Building the report has no side effects: a handful of indexed SQLite reads and two
 * stat() calls. It never triggers the background runner, never contacts a mint or a
 * price provider, and never writes to the database. (Opening the database the first
 * time after an upgrade runs pending schema migrations, as every entry point does.)
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Health {
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const UNAVAILABLE = 'unavailable';

    /** Severity of one check. A failing critical check makes the install unavailable. */
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * Background heartbeat older than this is reported (same threshold as the
     * dashboard's "Needs attention" view). Background work is started by page traffic
     * on most installs, so a quiet shop goes stale without anything being wrong: it is
     * a warning, never a reason for 503.
     */
    public const HEARTBEAT_STALE_SECONDS = 900;

    /**
     * The tasks that turn a paid invoice into a completed order (BackgroundRunner's
     * ALWAYS_RUN minus the update check; tests/health_endpoint.php keeps them in sync).
     * One failure is a blip (a mint timing out once); this many in a row, for this long,
     * means paid orders are not being marked paid.
     */
    public const PAYMENT_PATH_TASKS = ['recover_wallet_operations', 'ready_store_wallets', 'poll_quotes', 'webhook_outbox'];
    public const PAYMENT_PATH_FAILURES = 3;
    public const PAYMENT_PATH_FAILING_SECONDS = 600;

    /** Free space below this in the data folder is reported; below the second, it fails. */
    public const DISK_WARN_BYTES = 100 * 1024 * 1024;
    public const DISK_FAIL_BYTES = 10 * 1024 * 1024;

    /** Config key of the read-only monitoring key. */
    public const KEY_CONFIG = 'health_key';

    /**
     * Run every check.
     *
     * @return array{status: string, databaseOk: bool, checks: list<array{name: string, status: string, message: string}>, background: ?array, pendingInvoices: ?int}
     */
    public static function report(?int $now = null): array {
        $now = $now ?? time();
        $checks = [];
        $background = null;
        $pending = null;

        $add = function (string $name, string $status, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $status, 'message' => $message];
        };

        // Without a database nothing else can be asked. isInitialized() does not create
        // the data folder or the database when they are missing; anything after it may.
        $initialized = false;
        try {
            // isInitialized() also says yes for a file that holds an installation but
            // cannot be opened (so setup is not offered on it); ask the database itself.
            $initialized = Database::isInitialized() && Database::getMigrationError() === null
                && Database::fetchOne('SELECT COUNT(*) AS n FROM config') !== null;
        } catch (Throwable $e) {
            $initialized = false;
        }
        if (!$initialized) {
            $add('database', self::FAIL, Database::getMigrationError() !== null
                ? 'The database could not be upgraded to this version.'
                : 'The database is missing or cannot be opened.');
            return self::finish($checks, null, null);
        }
        $add('database', self::PASS, 'The database opens.');

        if (!Config::isSetupComplete()) {
            $add('setup', self::FAIL, 'Setup has not been finished.');
            return self::finish($checks, null, null);
        }
        $add('setup', self::PASS, 'Setup is finished.');

        // The wallet lives in the database: a read-only database takes no payments.
        $dataDir = Database::getDataDir();
        $dbPath = Database::getDbPath();
        if (!is_writable($dataDir) || !is_writable($dbPath)) {
            $add('storage', self::FAIL, 'The data folder or the database is not writable by the web server.');
        } else {
            $add('storage', self::PASS, 'The data folder is writable.');
        }

        $free = function_exists('disk_free_space') ? @disk_free_space($dataDir) : false;
        if (is_float($free) || is_int($free)) {
            if ($free < self::DISK_FAIL_BYTES) {
                $add('disk', self::FAIL, 'The disk holding the data folder is almost full.');
            } elseif ($free < self::DISK_WARN_BYTES) {
                $add('disk', self::WARN, 'The disk holding the data folder is running out of space.');
            } else {
                $add('disk', self::PASS, 'There is enough free disk space.');
            }
        }

        // The same test the background runner uses for a store it can work on. Setup is
        // finished here, so no ready store means no payment can be taken: a failure, not a
        // warning (the dashboard's Settings say the same).
        $configured = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS n FROM stores
             WHERE mint_url IS NOT NULL AND mint_url != '' AND seed_phrase IS NOT NULL AND seed_phrase != ''
               AND wallet_account_id IS NOT NULL"
        )['n'] ?? 0);
        if ($configured === 0) {
            $add('stores', self::FAIL, 'No store is ready to take payments.');
        } else {
            $add('stores', self::PASS, 'At least one store is ready to take payments.');
        }

        // Background heartbeat, as recorded by BackgroundRunner::run().
        $lastRun = (int)Config::get('cron_last_run', '0');
        $health = Config::get('cron_task_health', []);
        $health = is_array($health) ? $health : [];
        $failing = [];
        $paymentPathDown = false;
        foreach ($health as $task => $record) {
            if (!is_array($record) || !isset($record['last_error'])) {
                continue;
            }
            $failures = (int)($record['failures'] ?? 1);
            $since = (int)($record['failing_since'] ?? $record['last_error']);
            $paymentPath = in_array($task, self::PAYMENT_PATH_TASKS, true);
            if ($paymentPath && $failures >= self::PAYMENT_PATH_FAILURES && $now - $since >= self::PAYMENT_PATH_FAILING_SECONDS) {
                $paymentPathDown = true;
            }
            $failing[] = [
                'task' => (string)$task,
                'failures' => $failures,
                'since' => $since,
                'paymentPath' => $paymentPath,
                'message' => mb_substr((string)($record['message'] ?? ''), 0, 300),
            ];
        }
        usort($failing, fn($a, $b) => [$b['paymentPath'], $a['since']] <=> [$a['paymentPath'], $b['since']]);
        $background = [
            'lastRun' => $lastRun ?: null,
            'secondsAgo' => $lastRun ? max(0, $now - $lastRun) : null,
            'failing' => $failing,
        ];

        if ($lastRun === 0) {
            $add('background', self::WARN, 'Background tasks have not run yet.');
        } elseif ($now - $lastRun > self::HEARTBEAT_STALE_SECONDS) {
            $add('background', self::WARN, 'Background tasks have not run for more than '
                . intdiv(self::HEARTBEAT_STALE_SECONDS, 60) . ' minutes.');
        } else {
            $add('background', self::PASS, 'Background tasks ran recently.');
        }

        if ($paymentPathDown) {
            $add('payments', self::FAIL, 'Checking for customer payments keeps failing, so paid orders may not be marked paid.');
        } elseif (array_filter($failing, fn($f) => $f['paymentPath'])) {
            $add('payments', self::WARN, 'Checking for customer payments failed recently.');
        } else {
            $add('payments', self::PASS, 'Payments are processed.');
        }
        if ($failing && !array_filter($failing, fn($f) => $f['paymentPath'])) {
            $add('maintenance', self::WARN, count($failing) . ' background maintenance task(s) failing.');
        }

        // Open invoices waiting for a payment: if the payment path is down these are the
        // orders affected. Uses idx_invoices_status.
        $pending = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS n FROM invoices WHERE status IN ('New', 'Processing') AND expiration_time > ?",
            [$now]
        )['n'] ?? 0);

        return self::finish($checks, $background, $pending);
    }

    private static function finish(array $checks, ?array $background, ?int $pending): array {
        $statuses = array_column($checks, 'status');
        $status = in_array(self::FAIL, $statuses, true) ? self::UNAVAILABLE
            : (in_array(self::WARN, $statuses, true) ? self::DEGRADED : self::OK);
        $databaseOk = ($checks[0]['name'] ?? '') === 'database' && $checks[0]['status'] === self::PASS;
        return ['status' => $status, 'databaseOk' => $databaseOk, 'checks' => $checks,
            'background' => $background, 'pendingInvoices' => $pending];
    }

    /** HTTP status code for an overall status. */
    public static function httpCode(string $status): int {
        return $status === self::UNAVAILABLE ? 503 : 200;
    }

    /** What an unauthenticated caller sees. */
    public static function publicView(array $report): array {
        return ['status' => (string)$report['status']];
    }

    /** What the operator or a key holder sees. */
    public static function detailedView(array $report, ?int $now = null): array {
        return [
            'status' => $report['status'],
            'checkedAt' => $now ?? time(),
            'version' => CASHUPAY_VERSION,
            'checks' => $report['checks'],
            'background' => $report['background'],
            'pendingInvoices' => $report['pendingInvoices'],
        ];
    }

    /**
     * Whether $provided is the monitoring key or the cron key. Both are compared in
     * constant time; an unset key never matches (an empty string is not a key).
     *
     * The cron key already lets its holder run the background tasks and read their
     * results, so accepting it here discloses nothing new. The monitoring key only reads.
     */
    public static function keyMatches(string $provided): bool {
        if ($provided === '' || strlen($provided) > 256) {
            return false;
        }
        $matched = false;
        foreach ([self::KEY_CONFIG, 'cron_key'] as $name) {
            $key = Config::get($name);
            if (is_string($key) && $key !== '' && hash_equals($key, $provided)) {
                $matched = true; // no early exit: both comparisons always run
            }
        }
        return $matched;
    }

    /** The monitoring key, or null when none has been created. */
    public static function getKey(): ?string {
        $key = Config::get(self::KEY_CONFIG);
        return is_string($key) && $key !== '' ? $key : null;
    }

    /** Create (or replace) the monitoring key. Replacing invalidates the old one. */
    public static function rotateKey(): string {
        $key = bin2hex(random_bytes(24));
        Config::set(self::KEY_CONFIG, $key);
        return $key;
    }

    public static function deleteKey(): void {
        Config::delete(self::KEY_CONFIG);
    }
}
