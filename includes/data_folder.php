<?php
/**
 * Data-folder work an operator does from the browser, with no server access:
 *
 *  - Start a fresh installation from a database backup the dashboard downloaded
 *    (UX-A4). Offered only before any wallet exists here.
 *  - Move a wallet's data folder out of the website (UX-A10), for an installation whose
 *    folder ended up downloadable (a host change, nginx ignoring .htaccess).
 *
 * Both replace the database file the whole application uses, so both are careful about
 * the order of steps: nothing goes live until it was checked, and the old copy is only
 * removed once the new one is the one every request opens.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/urls.php';

class DataFolder {
    /** Tables every CashuPayServer database has, whatever its version. */
    private const REQUIRED_TABLES = ['config', 'stores', 'invoices'];

    /** Compared row for row before a moved copy is trusted. */
    private const COUNTED_TABLES = [
        'config', 'stores', 'invoices', 'api_keys', 'webhooks', 'webhook_deliveries', 'store_mints',
        'transfers', 'payment_requests', 'cashu_proofs', 'cashu_counters', 'cashu_wallet_metadata',
        'cashu_pending_operations', 'cashu_mint_quotes',
    ];

    /**
     * Renamed in the old copy once the move is recorded. A request that opened the old
     * file before the move and is still waiting to write then fails, instead of storing
     * money in a file that is about to be deleted.
     */
    private const RETIRED_TABLES = ['cashu_proofs', 'cashu_counters', 'invoices', 'payment_requests'];

    /** How long the move keeps background work paused if it dies half-way. */
    private const LEASE_SECONDS = 600;

    /** Seconds a minting claim counts as in flight (Invoice::MINT_RETRY_COOLDOWN). */
    private const IN_FLIGHT_SECONDS = 120;

    public const BUSY_MESSAGE = 'A payment or withdrawal is being processed right now. Nothing was changed. Try again in a minute.';

    // =====================================================================
    // Start from a backup file (UX-A4)
    // =====================================================================

    /** The largest file PHP accepts in an upload here, in bytes (0: no limit known). */
    public static function uploadLimit(): int {
        $limits = array_filter([
            self::iniBytes((string)ini_get('upload_max_filesize')),
            self::iniBytes((string)ini_get('post_max_size')),
        ], fn($bytes) => $bytes > 0);
        return $limits ? min($limits) : 0;
    }

    /** A php.ini size ("2M", "512K", "1G") in bytes. */
    public static function iniBytes(string $value): int {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([kmg]?)/i', $value, $m)) {
            return 0;
        }
        $bytes = (int)$m[1];
        return match (strtolower($m[2])) {
            'g' => $bytes * 1073741824,
            'm' => $bytes * 1048576,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    public static function formatBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
        }
        return max(1, (int)ceil($bytes / 1024)) . ' KB';
    }

    /** A message for the hosting provider when PHP refuses a file this large. */
    public static function uploadLimitHelp(int $needBytes = 0): string {
        $mb = max(64, (int)ceil($needBytes / 1048576) * 2);
        return "Hello, I need to upload a file of up to {$mb} MB to a PHP application on my website. "
            . "Could you please raise the PHP settings upload_max_filesize and post_max_size to at least {$mb}M "
            . 'for my site? Thank you.';
    }

    /**
     * Why an upload did not arrive, in plain words, or null when it did. A request larger
     * than post_max_size arrives with no form fields at all, so check that first.
     *
     * @return array{error: string, hostHelp: ?string}|null
     */
    public static function uploadProblem(?array $file): ?array {
        $tooLarge = [
            'error' => 'The backup file is larger than this hosting accepts in an upload ('
                . self::formatBytes(self::uploadLimit()) . '). Your hosting provider can raise the limit: send them the message below, then try again.',
            'hostHelp' => self::uploadLimitHelp(max((int)($file['size'] ?? 0), (int)($_SERVER['CONTENT_LENGTH'] ?? 0))),
        ];
        if ($file === null || !isset($file['error'])) {
            return ['error' => 'Choose the backup file first.', 'hostHelp' => null];
        }
        return match ((int)$file['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $tooLarge,
            UPLOAD_ERR_NO_FILE => ['error' => 'Choose the backup file first.', 'hostHelp' => null],
            UPLOAD_ERR_PARTIAL => ['error' => 'The upload was interrupted. Try again.', 'hostHelp' => null],
            default => [
                'error' => 'This hosting could not receive the file (upload error ' . (int)$file['error'] . '). Send the message below to your hosting provider.',
                'hostHelp' => 'Hello, file uploads to the PHP application on my website fail with PHP upload error '
                    . (int)$file['error'] . ' (for example a missing or full temporary folder). Could you please check it? Thank you.',
            ],
        };
    }

    /** Whether a POST was dropped whole because it exceeded post_max_size. */
    public static function postTooLarge(): bool {
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = self::iniBytes((string)ini_get('post_max_size'));
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES)
            && $max > 0 && $length > $max;
    }

    /** Nothing here has a wallet yet, so a backup may replace the database. */
    public static function isFreshForRestore(): bool {
        if (Config::isSetupComplete()) {
            return false;
        }
        try {
            $row = Database::fetchOne("SELECT COUNT(*) AS n FROM stores WHERE seed_phrase IS NOT NULL AND seed_phrase != ''");
            return (int)($row['n'] ?? 0) === 0;
        } catch (Throwable $e) {
            return true; // no stores table yet
        }
    }

    /**
     * Check a file that should be a database backup. Never throws.
     *
     * @return array{ok: bool, error?: string, version?: int, stores?: int, wallets?: int,
     *               invoices?: int, hasPassword?: bool}
     */
    public static function inspectBackup(string $path): array {
        $notBackup = [
            'ok' => false,
            'error' => 'This file is not a CashuPayServer backup. Choose the file the dashboard downloaded: '
                . 'its name starts with "cashupay-backup-" and ends with ".sqlite".',
        ];
        $head = @file_get_contents($path, false, null, 0, 16);
        if ($head === false || strlen($head) < 16 || strncmp($head, "SQLite format 3\0", 16) !== 0) {
            return $notBackup;
        }
        try {
            $pdo = self::open($path);
            $check = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            if ($check !== ['ok']) {
                return ['ok' => false, 'error' => 'This backup file is damaged and cannot be used. '
                    . 'If you have an older backup file, try that one.'];
            }
            foreach (self::REQUIRED_TABLES as $table) {
                if (!self::tableExists($pdo, $table)) {
                    return $notBackup;
                }
            }
            $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version > Database::schemaVersion()) {
                return ['ok' => false, 'error' => 'This backup comes from a newer version of CashuPayServer than the one '
                    . 'installed here (' . CASHUPAY_VERSION . '). Update this installation to the latest version first, '
                    . 'then start from the backup again.'];
            }
            $setting = function (string $key) use ($pdo) {
                $stmt = $pdo->prepare('SELECT value FROM config WHERE key = ?');
                $stmt->execute([$key]);
                $value = $stmt->fetchColumn();
                if ($value === false) {
                    return null;
                }
                $decoded = json_decode((string)$value, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            };
            if ($setting('setup_complete') !== true) {
                return ['ok' => false, 'error' => 'This backup is of an installation whose setup was never finished, '
                    . 'so there is nothing in it to start from.'];
            }
            $hash = $setting('admin_password_hash');
            return [
                'ok' => true,
                'version' => $version,
                'stores' => (int)$pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn(),
                'wallets' => (int)$pdo->query("SELECT COUNT(*) FROM stores WHERE seed_phrase IS NOT NULL AND seed_phrase != ''")->fetchColumn(),
                'invoices' => (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
                'hasPassword' => is_string($hash) && $hash !== '',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'This backup file is damaged and cannot be used. '
                . 'If you have an older backup file, try that one.'];
        } finally {
            $pdo = null;
            self::removeSidecars($path);
        }
    }

    /**
     * Replace this fresh installation's database with a backup.
     *
     * The backup is checked and brought up to this version's schema on a staging copy
     * in the data folder; only then does it replace the live file. An old snapshot does
     * not know about payments and withdrawals made after it was taken, so every wallet
     * in it is re-checked with its mint before it may take a payment (see
     * forceWalletRecovery()). It keeps its own admin password, and every login made
     * before is ended.
     *
     * @param bool $uploaded $source is a PHP upload (moved, not copied)
     * @return array{ok: bool, error?: string, hostHelp?: ?string, stores?: int, wallets?: int, invoices?: int}
     */
    public static function restoreBackup(string $source, bool $uploaded = true): array {
        if (!self::isFreshForRestore()) {
            return ['ok' => false, 'error' => 'This installation already has a wallet, so it cannot be replaced by a backup.'];
        }
        $staging = Database::getDataDir() . '/restore-' . bin2hex(random_bytes(6)) . '.sqlite';
        $placed = $uploaded ? @move_uploaded_file($source, $staging) : @copy($source, $staging);
        if (!$placed) {
            return ['ok' => false, 'error' => 'The file could not be saved on this server. Try again; if it keeps failing, '
                . 'ask your hosting provider whether the website may write files.'];
        }
        @chmod($staging, 0600);
        try {
            $info = self::inspectBackup($staging);
            if (!$info['ok']) {
                return $info;
            }
            if (!$info['hasPassword'] && !Urls::isWordPress()) {
                return ['ok' => false, 'error' => 'This backup was made by the WordPress plugin, so it has no admin password '
                    . 'to log in with here. Install the CashuPay plugin in WordPress and start from the backup there.'];
            }
            // Choices the operator made in this setup that describe this server, not the old one.
            $here = ['base_url' => Config::get('base_url'), 'url_mode' => Config::get('url_mode')];

            $pdo = self::open($staging);
            try {
                Database::ensureCurrentSchema($pdo);
                \Cashu\WalletStorage::initializeSchema($pdo);
            } catch (Throwable $e) {
                error_log('CashuPayServer: a backup could not be brought up to date: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'This backup could not be updated to this version of CashuPayServer. '
                    . 'Nothing was changed. Try a newer backup file.'];
            }
            self::prepareRestoredDatabase($pdo, $here);
            // One self-contained file: no write-ahead log may travel separately.
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo = null;

            if (!self::activate($staging)) {
                return ['ok' => false, 'error' => 'The server was busy and nothing was changed. Wait a minute and try again.'];
            }
            error_log('CashuPayServer: installation started from a backup (' . $info['stores'] . ' stores, '
                . $info['invoices'] . ' invoices) from ' . Security::getClientIp());
            return ['ok' => true, 'stores' => $info['stores'], 'wallets' => $info['wallets'], 'invoices' => $info['invoices']];
        } finally {
            $pdo = null;
            if (is_file($staging)) {
                @unlink($staging);
            }
            self::removeSidecars($staging);
        }
    }

    /**
     * Make a restored snapshot safe to run.
     *
     * Its proof states and its deterministic counters are as old as the snapshot: money
     * may have been spent and counters used since. Every bound account is put back into
     * the seed-recovery state the wallet library uses for an imported seed
     * (restore_required, not ready, no scan started), and every proof it believed
     * unspent is parked UNKNOWN, so the scan re-checks each one with the mint. Until
     * that scan completes, Invoice::createInvoice() refuses to take a payment there.
     */
    public static function forceWalletRecovery(PDO $pdo): int {
        if (!self::tableExists($pdo, 'cashu_wallet_metadata')) {
            return 0;
        }
        $accounts = $pdo->exec(
            "UPDATE cashu_wallet_metadata
             SET ready = 0, init_reason = 'restore_required', ready_reason = NULL,
                 recovered_at = NULL, recovery_version = NULL, restore_status = NULL, restore_gap = NULL,
                 restore_started_at = NULL, restore_updated_at = NULL, restore_error = NULL"
        );
        if (self::tableExists($pdo, 'cashu_restore_progress')) {
            $pdo->exec('DELETE FROM cashu_restore_progress');
        }
        if (self::tableExists($pdo, 'cashu_proofs')) {
            $pdo->exec("UPDATE cashu_proofs SET state = 'UNKNOWN' WHERE state = 'UNSPENT'");
        }
        // Start the scans at once rather than after an old backoff.
        $pdo->exec("DELETE FROM config WHERE key LIKE 'wallet_recovery_error_%'");
        return (int)$accounts;
    }

    private static function prepareRestoredDatabase(PDO $pdo, array $here): void {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            self::forceWalletRecovery($pdo);
            // Leftovers of the old server's moment in time: a background run that held
            // the lease, a setup claim, a cached safety check of the old folder.
            $pdo->exec("DELETE FROM config WHERE key IN ('setup_claim', 'cron_lease_token', 'cron_running_task', 'data_exposure_check')");
            if (Urls::isWordPress()) {
                // WordPress logins confirm with the WordPress password; a standalone
                // password from the backup would silently take its place (Auth).
                $pdo->exec("DELETE FROM config WHERE key = 'admin_password_hash'");
            }
            $set = $pdo->prepare(
                'INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );
            $now = time();
            $set->execute(['cron_lease_until', '0', $now, $now]);
            // Every login of the old server (and of this setup) ends: the next one needs
            // the password stored in the backup.
            $set->execute(['admin_password_version', 'pv_' . bin2hex(random_bytes(12)), $now, $now]);
            $set->execute(['restored_from_backup_at', (string)$now, $now, $now]);
            foreach ($here as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $set->execute([$key, $value, $now, $now]);
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Put a checked staging file in place of the live database. */
    private static function activate(string $staging): bool {
        $db = Database::getDbPath();
        Database::forgetConnection();
        if (file_exists($db)) {
            // A -wal left beside the new file would be replayed into it. Fold it into the
            // old file and switch that to a single file; this needs every other
            // connection closed, which on a fresh install means a moment's patience.
            try {
                $live = self::open($db);
                $live->exec('PRAGMA busy_timeout = 5000');
                $live->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                $mode = strtolower((string)$live->query('PRAGMA journal_mode = DELETE')->fetchColumn());
                $live = null;
                if ($mode !== 'delete') {
                    return false;
                }
            } catch (Throwable $e) {
                return false;
            }
        }
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (file_exists($db . $suffix) && !@unlink($db . $suffix)) {
                return false;
            }
        }
        if (!@rename($staging, $db)) {
            return false;
        }
        Database::forgetConnection();
        Config::clearCache();
        return true;
    }

    // =====================================================================
    // Move the data folder out of the website (UX-A10)
    // =====================================================================

    /**
     * What the dashboard shows about the data folder.
     *
     * @return array{path: string, insideWebsite: bool, canMove: bool, target: ?string,
     *               reason: ?string, hostHelp: ?string}
     */
    public static function status(): array {
        $plan = self::movePlan();
        return [
            'path' => (string)(realpath(Database::getDataDir()) ?: Database::getDataDir()),
            'insideWebsite' => self::insideWebsite(),
            'canMove' => $plan['ok'],
            'target' => $plan['target'] ?? null,
            'reason' => $plan['reason'] ?? null,
            'hostHelp' => $plan['hostHelp'] ?? null,
        ];
    }

    /** The data folder is part of a folder the web server publishes. */
    public static function insideWebsite(): bool {
        $dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', realpath(Database::getDataDir()) ?: Database::getDataDir()), '/');
        $roots = [(string)($_SERVER['DOCUMENT_ROOT'] ?? '')];
        if (Urls::isWordPress() && defined('ABSPATH')) {
            $roots[] = ABSPATH;
            if (defined('WP_CONTENT_DIR')) {
                $roots[] = WP_CONTENT_DIR;
            }
        } else {
            $roots[] = dirname(__DIR__); // the app's own folder is served
        }
        foreach ($roots as $root) {
            $root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', realpath($root) ?: ''), '/');
            if ($root !== '' && str_starts_with($dir . '/', $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Where the folder would go, or why it cannot go there from here (with a message for
     * the hosting provider when they can make it possible).
     *
     * @return array{ok: bool, target?: string, fill?: bool, reason?: string, hostHelp?: ?string}
     */
    public static function movePlan(): array {
        $current = rtrim(realpath(Database::getDataDir()) ?: Database::getDataDir(), '/');
        if (Urls::isWordPress()) {
            return self::wordpressPlan($current);
        }

        $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if (defined('CASHUPAY_DATA_DIR')) {
            $suggested = $docRoot ? dirname($docRoot) . '/cashupay-data' : '/home/youruser/cashupay-data';
            return [
                'ok' => false,
                'reason' => 'The data folder is set in includes/config.local.php, so it cannot be moved from here.',
                'hostHelp' => "Hello, my website runs CashuPayServer, a PHP payment app. Its data folder {$current} can be reached from the web. "
                    . "Could you please move that folder, with every file in it, to {$suggested} (outside the public website), "
                    . "and change the CASHUPAY_DATA_DIR line in includes/config.local.php of the app to: "
                    . "define('CASHUPAY_DATA_DIR', '{$suggested}'); Please do both together. Thank you.",
            ];
        }
        if (!$docRoot) {
            return ['ok' => false, 'reason' => 'The server did not say where the website folder is, so a safe place for the data cannot be chosen.', 'hostHelp' => null];
        }
        $parent = dirname($docRoot);
        $target = $parent . '/cashupay-data';
        if (file_exists($target) && !self::isEmptyFolder($target)) {
            $target = $parent . '/cashupay-data-' . substr(hash('sha256', realpath(dirname(__DIR__)) ?: dirname(__DIR__)), 0, 8);
        }
        if ($target === $current || !self::insideWebsite()) {
            return ['ok' => false, 'reason' => 'The data folder is already outside the website.', 'hostHelp' => null];
        }
        $webData = dirname(__DIR__) . '/data';
        if (!is_writable(__DIR__) && !(is_dir($webData) && is_writable($webData))) {
            return [
                'ok' => false,
                'reason' => 'This app cannot write to its own folders to remember the new place.',
                'hostHelp' => 'Hello, the PHP application in ' . dirname(__DIR__) . ' on my website needs to write to its folder '
                    . dirname(__DIR__) . '/includes. Could you please make that folder writable by the account PHP runs as'
                    . self::phpUserHint() . '? Thank you.',
            ];
        }
        if (is_dir($target)) {
            if (!is_writable($target)) {
                return ['ok' => false, 'reason' => "The folder {$target} exists but this app cannot write to it.",
                    'hostHelp' => self::createFolderHelp($current, $target)];
            }
            return ['ok' => true, 'target' => $target, 'fill' => true];
        }
        if (file_exists($target) || !is_writable($parent)) {
            return ['ok' => false, 'reason' => 'Your hosting does not let this app create a folder outside the website.',
                'hostHelp' => self::createFolderHelp($current, $target)];
        }
        return ['ok' => true, 'target' => $target, 'fill' => false];
    }

    private static function wordpressPlan(string $current): array {
        if (function_exists('is_multisite') && is_multisite()) {
            return ['ok' => false, 'reason' => 'On a WordPress network the sites share one data folder, so it cannot be moved from here.', 'hostHelp' => null];
        }
        if (!defined('CASHUPAY_DATA_DIR_AUTO') || !function_exists('cashupay_own_data_dir')) {
            return ['ok' => false, 'reason' => 'The data folder is set by a CASHUPAY_DATA_DIR line in wp-config.php, so it cannot be moved from here.',
                'hostHelp' => "Hello, my WordPress site uses the CashuPay plugin. Its data folder {$current} can be reached from the web. "
                    . 'Could you please move that folder, with every file in it, outside the public website, and change the '
                    . 'CASHUPAY_DATA_DIR line in wp-config.php to the new place? Please do both together. Thank you.'];
        }
        $target = cashupay_own_data_dir();
        if ($target === $current) {
            return ['ok' => false, 'reason' => 'The data folder is already outside the website.', 'hostHelp' => null];
        }
        $wpConfigLine = "define('CASHUPAY_DATA_DIR', '{$target}');";
        $help = "Hello, my WordPress site uses the CashuPay plugin. Its data folder {$current} can be reached from the web. "
            . "Could you please move that folder, with every file in it, to {$target} (outside the public website), and add this line "
            . "to wp-config.php above the line \"That's all, stop editing!\": {$wpConfigLine} Please do both together. Thank you.";
        if (file_exists($target)) {
            return ['ok' => false, 'reason' => "A folder {$target} already exists, so this one is not moved there automatically.", 'hostHelp' => $help];
        }
        if (!is_writable(dirname($target))) {
            return ['ok' => false, 'reason' => 'Your hosting does not let WordPress create a folder outside the website.', 'hostHelp' => $help];
        }
        return ['ok' => true, 'target' => $target, 'fill' => false];
    }

    private static function createFolderHelp(string $current, string $target): string {
        return "Hello, my website runs CashuPayServer, a PHP payment app. It keeps its data in {$current}, inside the public "
            . "website, and I want to move it out of the website. Could you please create an empty folder {$target}, owned by "
            . 'the account the website\'s PHP runs as' . self::phpUserHint() . ', with permissions 700? Please leave it empty. Thank you.';
    }

    private static function phpUserHint(): string {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = @posix_getpwuid(posix_geteuid());
            if (is_array($user) && !empty($user['name'])) {
                return ' (' . $user['name'] . ')';
            }
        }
        return '';
    }

    /** Nothing in it but the protection files this app writes. */
    private static function isEmptyFolder(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (!in_array($name, ['.', '..', '.htaccess', 'index.php'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Move the data folder out of the website, with the wallet in it. The caller has
     * checked the admin password.
     *
     * Background work and every store's money-moving locks are taken first, and the old
     * database is held for writing while it is copied, so no payment can land in the
     * old file after the copy was made. The copy is checked (integrity, row counts per
     * table, the sum of the wallet's ecash) before the new place is recorded, and the
     * old files are deleted only after that.
     *
     * @return array{ok: bool, busy?: bool, path?: string, error?: string, hostHelp?: ?string}
     */
    public static function move(): array {
        $plan = self::movePlan();
        if (!$plan['ok']) {
            return ['ok' => false, 'error' => $plan['reason'], 'hostHelp' => $plan['hostHelp'] ?? null];
        }
        $target = $plan['target'];
        $old = rtrim(Database::getDataDir(), '/');
        $oldDb = Database::getDbPath();
        $busy = ['ok' => false, 'busy' => true, 'error' => self::BUSY_MESSAGE];
        // Asked now: afterwards nothing may open the database through the old location.
        $who = Security::getClientIp();

        @set_time_limit(0);
        $locks = [];
        $lease = null;
        $hold = null;
        $placed = null; // what to remove again if recording the new place fails
        $moved = false;
        try {
            // 1. Pause everything that moves money.
            foreach (Database::fetchAll('SELECT id FROM stores') as $store) {
                foreach (['funds_', 'receive_'] as $prefix) {
                    $lock = cashupay_acquire_lock($prefix . $store['id']);
                    if (!$lock) {
                        return $busy;
                    }
                    $locks[] = $lock;
                }
            }
            $lease = self::takeLease();
            if ($lease === null) {
                return $busy;
            }

            // 2. Hold the database for writing: other requests may read, nobody writes.
            $hold = self::open($oldDb);
            $hold->exec('PRAGMA busy_timeout = 3000');
            try {
                $hold->exec('BEGIN IMMEDIATE');
            } catch (Throwable $e) {
                $hold = null;
                return $busy;
            }
            if (self::paymentInFlight($hold)) {
                return $busy;
            }
            $counts = self::fingerprint($hold);

            // 3. Copy, consistently (VACUUM INTO reads one snapshot, sidecars included).
            if ($plan['fill']) {
                $stage = $target;
                $copy = $target . '/.moving-' . bin2hex(random_bytes(4)) . '.sqlite';
                $placed = $copy;
            } else {
                $stage = dirname($target) . '/.cashupay-moving-' . bin2hex(random_bytes(4));
                if (!@mkdir($stage, 0700)) {
                    return ['ok' => false, 'error' => 'Your hosting does not let this app create a folder outside the website.',
                        'hostHelp' => self::createFolderHelp($old, $target)];
                }
                $placed = $stage;
                $copy = $stage . '/cashupay.sqlite';
            }
            $reader = self::open($oldDb);
            $reader->exec('VACUUM INTO ' . $reader->quote($copy));
            $reader = null;
            @chmod($copy, 0600);

            // 4. Check the copy before trusting it.
            $check = self::open($copy);
            $ok = $check->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) === ['ok']
                && self::fingerprint($check) === $counts;
            if ($ok) {
                // The copy carries this move's hold on background work; the new place
                // starts free.
                $check->exec("UPDATE config SET value = '0' WHERE key = 'cron_lease_until'");
                // The safety check cached for the old place says nothing about the new one.
                $check->exec("DELETE FROM config WHERE key = 'data_exposure_check'");
            }
            $check = null;
            self::removeSidecars($copy);
            if (!$ok) {
                error_log('CashuPayServer: data folder move aborted, the copy did not match the original');
                @unlink($copy);
                return ['ok' => false, 'error' => 'The copy of your data did not match the original, so nothing was moved. '
                    . 'Your data is unchanged. Try again later.'];
            }
            Database::protectDataDirectory($stage);
            self::copySessions($old, $stage);

            // 5. Put it in place.
            if ($plan['fill']) {
                if (!@rename($copy, $target . '/cashupay.sqlite')) {
                    @unlink($copy);
                    return ['ok' => false, 'error' => 'Moving the data folder did not work on this hosting. Your data is unchanged.'];
                }
                $placed = $target . '/cashupay.sqlite';
            } else {
                if (!@rename($stage, $target)) {
                    return ['ok' => false, 'error' => 'Moving the data folder did not work on this hosting. Your data is unchanged.'];
                }
                $placed = $target;
            }

            // 6. Record the new place. From here on every new request opens it.
            if (!self::recordLocation($target)) {
                error_log("CashuPayServer: data folder copied to {$target} but the new place could not be recorded");
                return ['ok' => false, 'error' => 'This app could not record the new place of the data folder, so nothing was moved. '
                    . 'Your data is unchanged.'];
            }
            $moved = true;
            $placed = null;

            // 7. Retire the old copy: a request still holding it fails instead of writing
            // into a file nobody will read again.
            foreach (self::RETIRED_TABLES as $table) {
                if (self::tableExists($hold, $table)) {
                    $hold->exec("ALTER TABLE {$table} RENAME TO moved_away_{$table}");
                }
            }
            $hold->prepare(
                'INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            )->execute(['data_folder_moved_to', $target, time(), time()]);
            $hold->exec('COMMIT');
            $hold = null;
        } catch (Throwable $e) {
            error_log('CashuPayServer: moving the data folder failed: ' . $e->getMessage());
            if ($moved) {
                // Recorded already: the new place is live. Only the clean-up is left.
                $hold = null;
            } else {
                return ['ok' => false, 'error' => 'Moving the data folder did not work on this hosting. Your data is unchanged.'];
            }
        } finally {
            if ($hold !== null) {
                try { $hold->exec('ROLLBACK'); } catch (Throwable $ignored) {}
                $hold = null;
            }
            if (!$moved) {
                if ($placed !== null) {
                    self::removeTree($placed);
                }
                if ($lease !== null) {
                    self::releaseLease($lease);
                }
            }
            foreach ($locks as $lock) {
                cashupay_release_lock($lock);
            }
        }

        // 8. The old files were what could be downloaded: remove them.
        Database::forgetConnection();
        Config::clearCache();
        $leftovers = array_merge(
            [$oldDb, $oldDb . '-wal', $oldDb . '-shm', $oldDb . '-journal'],
            glob($old . '/backup-*.sqlite') ?: [],
            glob($old . '/setup-token*.txt') ?: [],
            glob($old . '/restore-*.sqlite') ?: [],
            glob($old . '/.*.lock') ?: [],
            glob($old . '/sessions/*') ?: []
        );
        foreach ($leftovers as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (function_exists('delete_transient')) {
            delete_transient('cashupay_data_exposure');
        }
        error_log("CashuPayServer: data folder moved from {$old} to {$target} from {$who}");
        return ['ok' => true, 'path' => $target];
    }

    private static function recordLocation(string $target): bool {
        if (Urls::isWordPress()) {
            // The plugin picks its own folder next to WordPress as soon as it exists.
            return function_exists('cashupay_resolve_data_dir') && cashupay_resolve_data_dir()[0] === $target;
        }
        return Database::recordMovedLocation($target);
    }

    /** Something that changes the wallet is under way right now. */
    private static function paymentInFlight(PDO $pdo): bool {
        $since = time() - self::IN_FLIGHT_SECONDS;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE status = 'Processing' AND processing_since >= ?");
        $stmt->execute([$since]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
        if (self::tableExists($pdo, 'payment_requests')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_requests WHERE status = 'receiving' AND receiving_since >= ?");
            $stmt->execute([$since]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    /** Row counts per table plus the wallet's ecash per state: equal means the same data. */
    private static function fingerprint(PDO $pdo): array {
        $result = ['user_version' => (int)$pdo->query('PRAGMA user_version')->fetchColumn()];
        foreach (self::COUNTED_TABLES as $table) {
            if (self::tableExists($pdo, $table)) {
                $result[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            }
        }
        if (self::tableExists($pdo, 'cashu_proofs')) {
            foreach ($pdo->query('SELECT state, SUM(amount) AS total FROM cashu_proofs GROUP BY state ORDER BY state') as $row) {
                $result['ecash_' . $row['state']] = (int)$row['total'];
            }
        }
        if (self::tableExists($pdo, 'stores')) {
            $seeds = $pdo->query('SELECT id, seed_phrase FROM stores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $result['seeds'] = hash('sha256', json_encode($seeds));
        }
        return $result;
    }

    /** The background runner's lease (BackgroundRunner), taken so no run starts mid-move. */
    private static function takeLease(): ?string {
        $token = 'move-' . bin2hex(random_bytes(12));
        $pdo = Database::getInstance();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $row = Database::fetchOne("SELECT value FROM config WHERE key = 'cron_lease_until'");
            if ($row !== null && (int)$row['value'] > time()) {
                $pdo->exec('COMMIT');
                return null;
            }
            $now = time();
            $set = $pdo->prepare(
                'INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );
            $set->execute(['cron_lease_until', (string)($now + self::LEASE_SECONDS), $now, $now]);
            $set->execute(['cron_lease_token', $token, $now, $now]);
            $pdo->exec('COMMIT');
            Config::clearCache();
            return $token;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            throw $e;
        }
    }

    private static function releaseLease(string $token): void {
        try {
            Database::query(
                "UPDATE config SET value = '0' WHERE key = 'cron_lease_until'
                 AND (SELECT value FROM config WHERE key = 'cron_lease_token') = ?",
                [$token]
            );
            Config::clearCache();
        } catch (Throwable $e) {
            error_log('CashuPayServer: could not release the background lease after a failed move: ' . $e->getMessage());
        }
    }

    /** Logged-in browsers stay logged in after the move. */
    private static function copySessions(string $from, string $to): void {
        if (!is_dir($from . '/sessions')) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @mkdir($to . '/sessions', 0700);
        foreach (glob($from . '/sessions/*') ?: [] as $file) {
            if (is_file($file)) {
                @copy($file, $to . '/sessions/' . basename($file));
                @chmod($to . '/sessions/' . basename($file), 0600);
            }
        }
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private static function open(string $path): PDO {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // An uploaded file's schema may carry triggers or views; never run them on our behalf.
        $pdo->exec('PRAGMA trusted_schema = OFF');
        return $pdo;
    }

    private static function tableExists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private static function removeSidecars(string $path): void {
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($path . $suffix)) {
                @unlink($path . $suffix);
            }
        }
    }

    private static function removeTree(string $path): void {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
