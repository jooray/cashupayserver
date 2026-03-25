<?php
/**
 * CashuPayServer - Runtime health and self-check utilities
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/background.php';

class SystemHealth {
    /**
     * Basic PHP extension checks required by runtime paths.
     */
    public static function requiredExtensions(): array {
        return ['pdo_sqlite', 'curl', 'gmp', 'bcmath', 'mbstring', 'json'];
    }

    /**
     * Returns missing PHP extensions.
     */
    public static function missingExtensions(): array {
        $missing = [];
        foreach (self::requiredExtensions() as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        return $missing;
    }

    /**
     * Writable path checks used on startup and health endpoint.
     */
    public static function writableChecks(): array {
        $dataDir = Database::getDataDir();
        $dbPath = Database::getDbPath();

        return [
            [
                'path' => $dataDir,
                'exists' => is_dir($dataDir),
                'writable' => is_dir($dataDir) ? is_writable($dataDir) : false,
                'type' => 'dir',
            ],
            [
                'path' => dirname($dbPath),
                'exists' => is_dir(dirname($dbPath)),
                'writable' => is_dir(dirname($dbPath)) ? is_writable(dirname($dbPath)) : false,
                'type' => 'db-dir',
            ],
            [
                'path' => $dbPath,
                'exists' => file_exists($dbPath),
                'writable' => file_exists($dbPath) ? is_writable($dbPath) : is_writable(dirname($dbPath)),
                'type' => 'db-file',
            ],
        ];
    }

    /**
     * Collect critical warnings displayed in admin panel.
     */
    public static function collectWarnings(): array {
        $warnings = [];

        $missingExtensions = self::missingExtensions();
        if (!empty($missingExtensions)) {
            $warnings[] = [
                'code' => 'missing_extensions',
                'severity' => 'critical',
                'message' => 'Missing PHP extensions: ' . implode(', ', $missingExtensions),
            ];
        }

        foreach (self::writableChecks() as $check) {
            if (!$check['exists']) {
                $warnings[] = [
                    'code' => 'missing_path_' . $check['type'],
                    'severity' => 'critical',
                    'message' => 'Required path not found: ' . $check['path'],
                ];
                continue;
            }

            if (!$check['writable']) {
                $warnings[] = [
                    'code' => 'not_writable_' . $check['type'],
                    'severity' => 'critical',
                    'message' => 'Required path is not writable: ' . $check['path'],
                ];
            }
        }

        if (!Database::isDataDirOutsideWebroot()) {
            $warnings[] = [
                'code' => 'data_dir_inside_webroot',
                'severity' => 'warning',
                'message' => 'Data directory is inside web root. Consider moving it outside document root.',
            ];
        }

        $lastCronRun = (int)Config::get('last_cron_run', 0);
        if ($lastCronRun <= 0) {
            $warnings[] = [
                'code' => 'cron_never_ran',
                'severity' => 'warning',
                'message' => 'Cron has never run yet. Background jobs may be delayed.',
            ];
        } elseif ((time() - $lastCronRun) > 15 * 60) {
            $warnings[] = [
                'code' => 'cron_stale',
                'severity' => 'warning',
                'message' => 'Cron has not run for more than 15 minutes.',
            ];
        }

        $lastProofSync = (int)Config::get('last_proof_sync', 0);
        if ($lastProofSync > 0 && (time() - $lastProofSync) > 30 * 60) {
            $warnings[] = [
                'code' => 'proof_sync_stale',
                'severity' => 'warning',
                'message' => 'Proof sync is stale (>30 minutes).',
            ];
        }

        return $warnings;
    }

    /**
     * Build health endpoint payload.
     */
    public static function buildReport(): array {
        $warnings = self::collectWarnings();
        $missingExtensions = self::missingExtensions();
        $isHealthy = empty(array_filter($warnings, fn($w) => ($w['severity'] ?? '') === 'critical'));

        return [
            'ok' => $isHealthy,
            'timestamp' => time(),
            'setupComplete' => Config::isSetupComplete(),
            'databaseInitialized' => Database::isInitialized(),
            'phpVersion' => PHP_VERSION,
            'missingExtensions' => $missingExtensions,
            'writableChecks' => self::writableChecks(),
            'warnings' => $warnings,
            'lastCronRun' => (int)Config::get('last_cron_run', 0),
            'lastProofSync' => (int)Config::get('last_proof_sync', 0),
            'backgroundShouldSync' => Background::shouldSync(),
        ];
    }
}
