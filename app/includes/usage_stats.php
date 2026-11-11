<?php
/**
 * Anonymous usage statistics — the opt-in report an install can send to
 * BareBits so we know how many installs exist and which features they use.
 *
 * Nothing is sent unless the operator chose a level, first on the wizard's
 * `analytics` screen and later in Settings → Anonymous usage statistics. The
 * operator picks how much to share; every level's exact payload is shown
 * before the choice (previewJson) and the report is built by the SAME code
 * that renders the preview, so what is shown is what is sent.
 *
 * Levels (each includes the ones below it):
 *   1  Count me   — install id, version/build/channel, PHP version, OS family,
 *                   install kind (standalone/docker/desktop/wordpress), web server.
 *   2  Features   — store count, which payment rails are enabled and how many
 *                   stores use each, auto-update on/off, product and API-key
 *                   counts, whether the WordPress plugin is connected.
 *   3  Volume     — last-30-day invoice counts and settled sats, by rail.
 *
 * Never included, at any level: deployment_id (so a report can never be
 * joined to a fee payment), base URL, store names, mint URLs or any other
 * mint information, Lightning addresses, email addresses, auto-cashout or
 * notification settings, customer or invoice details. The install id is a
 * random UUID minted the moment the operator opts in; it is not derived from
 * anything on the install. The country shown on BareBits' side is derived
 * from the connection's IP address by the endpoint, which then discards the
 * IP — the report itself carries no location.
 *
 * Cadence: one report right after opting in, then weekly from cron. The
 * endpoint keeps only the most recent report per install id. Opting out
 * stops sending; it deletes nothing server-side.
 *
 * Wire format (schema_version 1): a single JSON object per report, shaped so
 * a generic event store (PostHog / Umami / a warehouse table) could ingest
 * it as one event with flat snake_case properties:
 *   { "schema_version": 1, "event": "usage_report", "client": "barebits-payserver",
 *     "install_id": "<uuid v4>", "sent_at": "<ISO-8601 UTC>", "level": 1|2|3,
 *     "properties": { ... } }
 * The receiving side is scripts/analytics-endpoint/report.php, which enforces
 * the same per-level key allowlist (ALLOWED_PROPERTIES below) and rejects
 * anything else, so a future client bug cannot widen what is collected.
 *
 * Endpoint: CASHUPAY_USAGE_STATS_ENDPOINT (env/constant), default
 * https://getbarebits.com/analytics/report.php. Test rigs set it to
 * `disabled`; as a backstop the fixtures' `.updater_disabled` sentinel also
 * disables it when no endpoint is named (same rule as includes/fee_branch.php).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/safe_http.php';
require_once __DIR__ . '/updater.php';
require_once __DIR__ . '/desktop.php';
require_once __DIR__ . '/managed.php';
require_once __DIR__ . '/setup_flow.php';
require_once __DIR__ . '/store_ln_addresses.php';
require_once __DIR__ . '/swap/config.php';

if (!defined('CASHUPAY_USAGE_STATS_ENDPOINT')) {
    $usEnv = getenv('CASHUPAY_USAGE_STATS_ENDPOINT');
    define('CASHUPAY_USAGE_STATS_ENDPOINT',
        ($usEnv !== false && $usEnv !== '') ? $usEnv : 'https://getbarebits.com/analytics/report.php');
    unset($usEnv);
}

final class UsageStats {
    public const SCHEMA_VERSION = 1;
    public const EVENT          = 'usage_report';
    public const CLIENT_MARKER  = 'barebits-payserver';

    public const LEVEL_COUNT    = 1;
    public const LEVEL_FEATURES = 2;
    public const LEVEL_VOLUME   = 3;
    public const LEVELS         = [self::LEVEL_COUNT, self::LEVEL_FEATURES, self::LEVEL_VOLUME];

    public const CONFIG_ENABLED         = 'usage_stats_enabled';
    public const CONFIG_LEVEL           = 'usage_stats_level';
    public const CONFIG_INSTALL_ID      = 'usage_stats_install_id';
    public const CONFIG_LAST_SENT_AT    = 'usage_stats_last_sent_at';
    public const CONFIG_LAST_ATTEMPT_AT = 'usage_stats_last_attempt_at';
    public const CONFIG_LAST_ERROR      = 'usage_stats_last_error';

    /** Weekly cadence; a failed attempt is retried after an hour. */
    public const SEND_INTERVAL_SEC  = 7 * 86400;
    public const RETRY_INTERVAL_SEC = 3600;
    public const VOLUME_WINDOW_SEC  = 30 * 86400;

    public const DEFAULT_TIMEOUT_SEC         = 8;
    public const DEFAULT_CONNECT_TIMEOUT_SEC = 4;

    /** Shown in a preview before an install id exists (minted at opt-in). */
    public const INSTALL_ID_PLACEHOLDER = '(random id, generated when you enable)';

    /**
     * The settled_rail values a 30-day volume breakdown reports on, in the
     * order they appear. Fixed so every level-3 report carries the same keys
     * (zeros included) and a column store never sees a ragged schema.
     */
    public const VOLUME_RAILS = ['mint', 'lnaddress', 'nwc', 'noffer', 'strike', 'swap', 'onchain'];

    /** Rails a store can have enabled, in report order. */
    public const FEATURE_RAILS = ['mint', 'lnaddress', 'nwc', 'noffer', 'strike', 'onchain', 'swaps'];

    /**
     * The complete property allowlist per level — the contract between this
     * client, the preview, and the endpoint. Keep the three in lockstep:
     * scripts/analytics-endpoint/report.php rejects a report carrying a key
     * that is not in its copy of this list.
     */
    public const ALLOWED_PROPERTIES = [
        self::LEVEL_COUNT => [
            'version', 'build_sha', 'channel', 'php_version', 'os_family',
            'install_kind', 'web_server',
        ],
        self::LEVEL_FEATURES => [
            'store_count', 'rails_enabled',
            'stores_with_mint', 'stores_with_lnaddress', 'stores_with_nwc',
            'stores_with_noffer', 'stores_with_strike', 'stores_with_onchain',
            'stores_with_swaps',
            'auto_update_enabled', 'products_count', 'api_keys_count',
            'wordpress_connected',
        ],
        self::LEVEL_VOLUME => [
            'invoices_30d_created', 'invoices_30d_settled', 'invoices_30d_expired',
            'invoices_30d_settled_sats',
            // + settled_30d_<rail>_count / settled_30d_<rail>_sats for VOLUME_RAILS,
            // appended by allowedPropertiesForLevel().
        ],
    ];

    // ------------------------------------------------------------------
    // Opt-in state
    // ------------------------------------------------------------------

    /** Has the operator answered the question at all (either way)? */
    public static function isAnswered(): bool {
        return Config::get(self::CONFIG_ENABLED, null) !== null;
    }

    public static function isEnabled(): bool {
        return Config::get(self::CONFIG_ENABLED, false) === true;
    }

    /** The chosen level, or null when none is stored. */
    public static function level(): ?int {
        $v = Config::get(self::CONFIG_LEVEL, null);
        if ($v === null) {
            return null;
        }
        $n = (int)$v;
        return in_array($n, self::LEVELS, true) ? $n : null;
    }

    public static function isValidLevel($level): bool {
        return is_int($level) && in_array($level, self::LEVELS, true);
    }

    /**
     * Record the operator's choice. Enabling requires a level and mints the
     * install id on first opt-in (a later re-enable keeps the same id, so the
     * endpoint's one-row-per-install view stays accurate). Disabling leaves
     * the level and id in place: the admin card shows the previous choice
     * and a re-enable does not create a second install on BareBits' side.
     */
    public static function setChoice(bool $enabled, ?int $level = null): void {
        if ($enabled) {
            if (!self::isValidLevel($level)) {
                throw new \InvalidArgumentException('Choose how much to share (level 1, 2 or 3).');
            }
            Config::set(self::CONFIG_LEVEL, $level);
            self::ensureInstallId();
        } elseif ($level !== null && self::isValidLevel($level)) {
            Config::set(self::CONFIG_LEVEL, $level);
        }
        Config::set(self::CONFIG_ENABLED, $enabled);
    }

    public static function installId(): ?string {
        $v = Config::get(self::CONFIG_INSTALL_ID, null);
        return (is_string($v) && self::isUuid($v)) ? $v : null;
    }

    private static function ensureInstallId(): string {
        $existing = self::installId();
        if ($existing !== null) {
            return $existing;
        }
        $id = self::uuidV4();
        Config::set(self::CONFIG_INSTALL_ID, $id);
        return $id;
    }

    public static function isUuid(string $v): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v) === 1;
    }

    private static function uuidV4(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-'
            . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    // ------------------------------------------------------------------
    // Endpoint / kill switch
    // ------------------------------------------------------------------

    public static function endpointUrl(): string {
        return trim((string)CASHUPAY_USAGE_STATS_ENDPOINT);
    }

    /**
     * True when no report may leave this install regardless of the opt-in:
     * the endpoint is switched off, or this is a test-fixture install (the
     * fixtures' sentinel file) that never named an endpoint explicitly.
     */
    public static function isDisabled(): bool {
        $url = strtolower(self::endpointUrl());
        if (in_array($url, ['', 'disabled', 'off', '0', 'none'], true)) {
            return true;
        }
        $env = getenv('CASHUPAY_USAGE_STATS_ENDPOINT');
        if (($env === false || $env === '') && self::testFixtureSentinelPresent()) {
            return true;
        }
        return false;
    }

    private static function testFixtureSentinelPresent(): bool {
        $dataDir = defined('CASHUPAY_DATA_DIR')
            ? (string)CASHUPAY_DATA_DIR
            : (string)(getenv('CASHUPAY_DATA_DIR') ?: '');
        return $dataDir !== '' && is_file($dataDir . '/.updater_disabled');
    }

    // ------------------------------------------------------------------
    // Payload
    // ------------------------------------------------------------------

    /** @return string[] every property key a report at $level may carry */
    public static function allowedPropertiesForLevel(int $level): array {
        $keys = [];
        foreach (self::LEVELS as $l) {
            if ($l > $level) {
                break;
            }
            $keys = array_merge($keys, self::ALLOWED_PROPERTIES[$l]);
            if ($l === self::LEVEL_VOLUME) {
                foreach (self::VOLUME_RAILS as $rail) {
                    $keys[] = "settled_30d_{$rail}_count";
                    $keys[] = "settled_30d_{$rail}_sats";
                }
            }
        }
        return $keys;
    }

    /**
     * The exact report for $level. $installId null → the stored id, or the
     * preview placeholder when none exists yet. Deterministic for a given
     * database state and $now so tests (and the preview) can pin it.
     */
    public static function buildPayload(int $level, ?int $now = null, ?string $installId = null): array {
        if (!self::isValidLevel($level)) {
            throw new \InvalidArgumentException("invalid usage-statistics level: {$level}");
        }
        $now = $now ?? time();
        $props = self::countProperties();
        if ($level >= self::LEVEL_FEATURES) {
            $props += self::featureProperties();
        }
        if ($level >= self::LEVEL_VOLUME) {
            $props += self::volumeProperties($now);
        }
        // Belt and braces: the allowlist is the contract, so drop anything a
        // future edit adds to a builder without adding it to the list.
        $allowed = array_flip(self::allowedPropertiesForLevel($level));
        $props = array_intersect_key($props, $allowed);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event'          => self::EVENT,
            'client'         => self::CLIENT_MARKER,
            'install_id'     => $installId ?? self::installId() ?? self::INSTALL_ID_PLACEHOLDER,
            'sent_at'        => gmdate('Y-m-d\TH:i:s\Z', $now),
            'level'          => $level,
            'properties'     => $props,
        ];
    }

    /** Pretty JSON of buildPayload(), for the wizard / admin previews. */
    public static function previewJson(int $level, ?int $now = null): string {
        return (string)json_encode(
            self::buildPayload($level, $now),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    private static function countProperties(): array {
        $build = Updater::getLocalBuildInfo();
        $sha = (string)($build['COMMIT_SHA'] ?? '');
        return [
            'version'      => CASHUPAY_VERSION,
            'build_sha'    => preg_match('/^[0-9a-f]{12,64}$/i', $sha) ? strtolower(substr($sha, 0, 12)) : null,
            'channel'      => Updater::getChannel(),
            'php_version'  => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'os_family'    => PHP_OS_FAMILY,
            'install_kind' => self::installKind(),
            'web_server'   => self::webServer($_SERVER['SERVER_SOFTWARE'] ?? null),
        ];
    }

    /** standalone | docker | desktop | wordpress */
    public static function installKind(): string {
        if (Desktop::isWindowsDesktop()) {
            return 'desktop';
        }
        if (ManagedInstall::isManaged()) {
            return 'wordpress';
        }
        $env = getenv('CASHUPAY_DOCKER');
        if (($env !== false && $env !== '' && $env !== '0') || is_file('/.dockerenv')) {
            return 'docker';
        }
        return 'standalone';
    }

    /**
     * Coarse web-server family from SERVER_SOFTWARE. A fixed enum rather than
     * the raw header: the raw value carries version strings and module lists
     * that are both fingerprint-y and useless for counting.
     */
    public static function webServer(?string $serverSoftware): string {
        $s = strtolower((string)$serverSoftware);
        if ($s === '') {
            return 'unknown';
        }
        if (str_contains($s, 'apache')) return 'apache';
        if (str_contains($s, 'nginx')) return 'nginx';
        if (str_contains($s, 'litespeed')) return 'litespeed';
        if (str_contains($s, 'caddy')) return 'caddy';
        if (str_contains($s, 'microsoft-iis') || str_contains($s, 'iis')) return 'iis';
        if (str_contains($s, 'development server')) return 'php-dev-server';
        return 'other';
    }

    private static function featureProperties(): array {
        $stores = Database::fetchAll(
            "SELECT id, mint_url, onchain_address_mode, onchain_xpub, onchain_static_address,
                    onchain_strike_enabled, swaps_enabled
               FROM stores"
        );
        $counts = array_fill_keys(self::FEATURE_RAILS, 0);
        foreach ($stores as $s) {
            $sid = (string)$s['id'];
            if (trim((string)($s['mint_url'] ?? '')) !== '') {
                $counts['mint']++;
            }
            $types = [];
            foreach (StoreLnAddresses::listForStore($sid) as $row) {
                $types[(string)($row['type'] ?? StoreLnAddresses::TYPE_LNADDRESS)] = true;
            }
            foreach (['lnaddress', 'nwc', 'noffer', 'strike'] as $t) {
                if (isset($types[$t])) {
                    $counts[$t]++;
                }
            }
            // Strike on-chain minting is a Strike rail too, even without a
            // Strike Lightning destination.
            if (!isset($types['strike']) && (int)($s['onchain_strike_enabled'] ?? 0) === 1) {
                $counts['strike']++;
            }
            $hasXpub = trim((string)($s['onchain_xpub'] ?? '')) !== '';
            $hasStatic = trim((string)($s['onchain_static_address'] ?? '')) !== '';
            $mode = ($s['onchain_address_mode'] ?? 'xpub') === 'static' ? 'static' : 'xpub';
            if ($mode === 'static' ? $hasStatic : $hasXpub) {
                $counts['onchain']++;
            }
            if (SwapsConfig::isEnabledForStore($sid)) {
                $counts['swaps']++;
            }
        }
        $enabled = [];
        foreach (self::FEATURE_RAILS as $rail) {
            if ($counts[$rail] > 0) {
                $enabled[] = $rail;
            }
        }
        $wpPaired = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM api_keys WHERE application_identifier = ?",
            ['barebits-wordpress']
        )['c'] ?? 0);

        return [
            'store_count'           => count($stores),
            'rails_enabled'         => $enabled,
            'stores_with_mint'      => $counts['mint'],
            'stores_with_lnaddress' => $counts['lnaddress'],
            'stores_with_nwc'       => $counts['nwc'],
            'stores_with_noffer'    => $counts['noffer'],
            'stores_with_strike'    => $counts['strike'],
            'stores_with_onchain'   => $counts['onchain'],
            'stores_with_swaps'     => $counts['swaps'],
            'auto_update_enabled'   => Updater::isAutoUpdateEnabled(),
            'products_count'        => (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM products")['c'] ?? 0),
            'api_keys_count'        => (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM api_keys")['c'] ?? 0),
            'wordpress_connected'   => ManagedInstall::isManaged() || $wpPaired > 0,
        ];
    }

    private static function volumeProperties(int $now): array {
        $cutoff = $now - self::VOLUME_WINDOW_SEC;
        $created = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM invoices WHERE created_at >= ?", [$cutoff]
        );
        $expired = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM invoices WHERE created_at >= ? AND status = 'Expired'", [$cutoff]
        );
        $settledRows = Database::fetchAll(
            "SELECT settled_rail, COUNT(*) AS n, COALESCE(SUM(amount_sats), 0) AS sats
               FROM invoices
              WHERE status = 'Settled' AND paid_at IS NOT NULL AND paid_at >= ?
              GROUP BY settled_rail",
            [$cutoff]
        );
        $byRail = [];
        $settledCount = 0;
        $settledSats = 0;
        foreach ($settledRows as $r) {
            $rail = (string)($r['settled_rail'] ?? '');
            $byRail[$rail] = ['count' => (int)$r['n'], 'sats' => (int)$r['sats']];
            $settledCount += (int)$r['n'];
            $settledSats += (int)$r['sats'];
        }
        $props = [
            'invoices_30d_created'      => (int)($created['c'] ?? 0),
            'invoices_30d_settled'      => $settledCount,
            'invoices_30d_expired'      => (int)($expired['c'] ?? 0),
            'invoices_30d_settled_sats' => $settledSats,
        ];
        foreach (self::VOLUME_RAILS as $rail) {
            $props["settled_30d_{$rail}_count"] = $byRail[$rail]['count'] ?? 0;
            $props["settled_30d_{$rail}_sats"]  = $byRail[$rail]['sats'] ?? 0;
        }
        return $props;
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * Build and send the report for the stored level now. Returns true on an
     * accepted report. Never throws; the outcome is recorded in config
     * (last sent / last error) for the admin card. A no-op (false) when the
     * opt-in is off or the endpoint is disabled.
     */
    public static function sendNow(?int $now = null, array $opts = []): bool {
        if (!self::isEnabled() || self::isDisabled()) {
            return false;
        }
        $level = self::level();
        if ($level === null) {
            Config::set(self::CONFIG_LAST_ERROR, 'no level chosen');
            return false;
        }
        $now = $now ?? time();
        $installId = self::ensureInstallId();
        $payload = self::buildPayload($level, $now, $installId);
        Config::set(self::CONFIG_LAST_ATTEMPT_AT, $now);

        $resp = SafeHttp::request(self::endpointUrl(), [
            'method'         => 'POST',
            'headers'        => ['Content-Type: application/json', 'Accept: application/json'],
            'body'           => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
            'timeout'        => (int)($opts['timeout'] ?? self::DEFAULT_TIMEOUT_SEC),
            'connectTimeout' => (int)($opts['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_SEC),
            'userAgent'      => 'cashupayserver-usage-stats',
            // Test rigs point the endpoint at 127.0.0.1; production is https.
            'allowPrivate'   => SafeHttp::privateEndpointsAllowed(),
            'maxBytes'       => 4096,
        ]);
        try {
            self::parseResponse((int)$resp['status'], (string)$resp['body'], (string)($resp['error'] ?? ''));
        } catch (\RuntimeException $e) {
            Config::set(self::CONFIG_LAST_ERROR, $e->getMessage());
            error_log('[usage-stats] report not accepted: ' . $e->getMessage());
            return false;
        }
        Config::set(self::CONFIG_LAST_SENT_AT, $now);
        Config::delete(self::CONFIG_LAST_ERROR);
        return true;
    }

    /** Accept only an explicit {"ok":true}; everything else is a diagnosable error. */
    public static function parseResponse(int $status, string $body, string $error = ''): void {
        if ($status === 0) {
            throw new \RuntimeException('endpoint unreachable: ' . ($error !== '' ? $error : 'no response'));
        }
        if ($status === 429) {
            throw new \RuntimeException('endpoint rate-limited this address');
        }
        $decoded = json_decode($body, true);
        if ($status !== 200) {
            $why = is_array($decoded) && is_string($decoded['error'] ?? null)
                ? ' (' . preg_replace('/[^a-z0-9_:. -]/i', '', substr($decoded['error'], 0, 80)) . ')'
                : '';
            throw new \RuntimeException("endpoint returned HTTP {$status}{$why}");
        }
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
            throw new \RuntimeException('endpoint returned an unparseable body');
        }
    }

    /**
     * Cron hook: send when the weekly interval has elapsed (or an hour after
     * a failed attempt). Returns a short status word for the cron summary.
     */
    public static function cronTick(?int $now = null): string {
        if (self::isDisabled()) {
            return 'disabled';
        }
        if (!self::isEnabled()) {
            return 'off';
        }
        $now = $now ?? time();
        if (!self::isDue($now)) {
            return 'not_due';
        }
        if (self::sendNow($now)) {
            return 'sent';
        }
        return 'error: ' . (string)Config::get(self::CONFIG_LAST_ERROR, 'unknown');
    }

    public static function isDue(int $now): bool {
        $lastSent = (int)Config::get(self::CONFIG_LAST_SENT_AT, 0);
        if ($lastSent > 0 && $lastSent > $now - self::SEND_INTERVAL_SEC) {
            return false;
        }
        $lastAttempt = (int)Config::get(self::CONFIG_LAST_ATTEMPT_AT, 0);
        if ($lastAttempt > 0 && $lastAttempt > $now - self::RETRY_INTERVAL_SEC) {
            return false;
        }
        return true;
    }

    /** State for the admin card / API. Previews are included for all levels. */
    public static function status(bool $withPreviews = true, ?int $now = null): array {
        $out = [
            'enabled'         => self::isEnabled(),
            'answered'        => self::isAnswered(),
            'level'           => self::level(),
            'install_id'      => self::installId(),
            'last_sent_at'    => Config::get(self::CONFIG_LAST_SENT_AT, null),
            'last_attempt_at' => Config::get(self::CONFIG_LAST_ATTEMPT_AT, null),
            'last_error'      => Config::get(self::CONFIG_LAST_ERROR, null),
            'disabled'        => self::isDisabled(),
            'endpoint'        => self::isDisabled() ? null : self::endpointUrl(),
            'interval_days'   => (int)(self::SEND_INTERVAL_SEC / 86400),
        ];
        if ($withPreviews) {
            $previews = [];
            foreach (self::LEVELS as $l) {
                $previews[(string)$l] = self::buildPayload($l, $now);
            }
            $out['previews'] = $previews;
        }
        return $out;
    }

    /**
     * Operator-facing copy for the three levels, shared by the wizard screen
     * and the admin card so the two never describe a level differently.
     *
     * @return array<int, array{title: string, summary: string, items: string[]}>
     */
    public static function levelDescriptions(): array {
        return [
            self::LEVEL_COUNT => [
                'title'   => 'Count me',
                'summary' => 'Just that this install exists.',
                'items'   => [
                    'A random install identifier (generated when you enable this; not linked to your deployment ID, fee payments, or anything else).',
                    'BareBits version, build and update channel.',
                    'PHP version, operating-system family, web server family, and whether this is a standalone, Docker, Windows desktop or WordPress install.',
                ],
            ],
            self::LEVEL_FEATURES => [
                'title'   => 'Features',
                'summary' => 'Everything in Count me, plus which features are in use.',
                'items'   => [
                    'Number of stores, and which payment rails are enabled (how many stores use each).',
                    'Whether automatic updates are on; number of products and API keys; whether the WordPress plugin is connected.',
                    'No mint addresses, Lightning addresses, store names, auto-cashout or notification settings.',
                ],
            ],
            self::LEVEL_VOLUME => [
                'title'   => 'Volume',
                'summary' => 'Everything in Features, plus 30-day totals.',
                'items'   => [
                    'How many invoices were created, settled and expired in the last 30 days.',
                    'Settled amounts in sats, totalled per payment rail.',
                    'Totals only — never individual invoices, customers, or addresses.',
                ],
            ],
        ];
    }
}
