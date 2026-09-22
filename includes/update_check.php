<?php
/**
 * Tell the operator when a newer CashuPayServer exists.
 *
 * A self-hosted payment server that nobody tells about a security release keeps
 * running the vulnerable version until its operator happens to look. That is the
 * problem this solves, and it is the only thing it does: it never downloads,
 * installs or changes anything. For software custodying ecash, an autoupdate
 * channel would be a worse vulnerability than the one it closes.
 *
 * What travels: one HTTP GET for a static file, once a day, from the background
 * runner. No instance identifier, no version, no store count, no POST body, no
 * cookies, and a User-Agent that names the software but not which release it is.
 * The comparison happens here, on the operator's own machine; the server learns
 * only that somebody asked for a file. That is still a phone-home, which is why
 * `update_check_enabled` turns it off and the settings toggle says plainly what
 * it does.
 *
 * Nothing here is allowed to throw or to slow a page down. Checking happens on
 * the runner's schedule; the admin UI reads the last cached answer and nothing
 * else, so the day this endpoint is unreachable is a day the admin page is
 * exactly as fast as always and says nothing about it.
 */

class UpdateCheck
{
    /** Where the release manifest lives. Overridable so a fork points at its own. */
    public const DEFAULT_URL = 'https://cashupayserver.org/version.json';

    /** One check a day is enough to hear about a release, and quiet enough to ignore. */
    private const INTERVAL = 86400;

    /** Retry sooner than a full day after a failure, but not so soon it is a retry loop. */
    private const RETRY_INTERVAL = 3600;

    private const CACHE_KEY = 'update_check_state';

    /** The operator asked not to be told. */
    public static function enabled(): bool
    {
        return (bool)Config::get('update_check_enabled', true);
    }

    public static function endpoint(): string
    {
        return defined('CASHUPAY_UPDATE_URL') && is_string(CASHUPAY_UPDATE_URL) && CASHUPAY_UPDATE_URL !== ''
            ? CASHUPAY_UPDATE_URL
            : self::DEFAULT_URL;
    }

    /**
     * What the admin UI should say, from cache alone. Never fetches, never blocks.
     *
     * @return array{outdated: bool, current: string, latest: ?string, url: ?string,
     *               severity: string, unsupported: bool, notes: ?string, checked_at: ?int}
     */
    public static function status(): array
    {
        $blank = [
            'outdated' => false, 'current' => CASHUPAY_VERSION, 'latest' => null, 'url' => null,
            'severity' => 'normal', 'unsupported' => false, 'notes' => null, 'checked_at' => null,
        ];
        if (!self::enabled()) {
            return $blank;
        }
        $state = Config::get(self::CACHE_KEY);
        if (!is_array($state) || empty($state['latest'])) {
            return $blank;
        }

        $latest = (string)$state['latest'];
        $min = isset($state['min_supported']) ? (string)$state['min_supported'] : null;

        return [
            'outdated'    => version_compare(CASHUPAY_VERSION, $latest, '<'),
            'current'     => CASHUPAY_VERSION,
            'latest'      => $latest,
            'url'         => isset($state['url']) ? (string)$state['url'] : null,
            'severity'    => isset($state['severity']) ? (string)$state['severity'] : 'normal',
            // Not merely old: older than what the author still stands behind.
            'unsupported' => $min !== null && version_compare(CASHUPAY_VERSION, $min, '<'),
            'notes'       => isset($state['notes']) ? (string)$state['notes'] : null,
            'checked_at'  => isset($state['at']) ? (int)$state['at'] : null,
        ];
    }

    /**
     * The background runner's task. Returns a short string for the task health log.
     */
    public static function run(): string
    {
        if (!self::enabled()) {
            return 'disabled';
        }

        $state = Config::get(self::CACHE_KEY);
        $state = is_array($state) ? $state : [];
        $last = (int)($state['at'] ?? 0);
        $wait = empty($state['latest']) && $last > 0 ? self::RETRY_INTERVAL : self::INTERVAL;
        if ($last > time() - $wait) {
            return 'skipped';
        }

        $fetched = self::fetch(self::endpoint());
        if ($fetched === null) {
            // Remember the attempt so a dead endpoint is retried hourly, not every
            // minute, and keep whatever we last knew rather than forgetting it.
            $state['at'] = time();
            Config::set(self::CACHE_KEY, $state);
            return 'unreachable';
        }

        $fetched['at'] = time();
        Config::set(self::CACHE_KEY, $fetched);
        return version_compare(CASHUPAY_VERSION, $fetched['latest'], '<') ? 'update available' : 'current';
    }

    /**
     * Fetch and validate the manifest. Null on anything unexpected.
     *
     * The URL in the response becomes a link an operator is invited to click while
     * being told to upgrade, so a compromised or mistyped endpoint must not be able
     * to point them anywhere it likes. It has to be HTTPS, and on the host serving
     * the manifest or on the release host.
     */
    private static function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Names the software, never the release. What we are asking about is
            // public; which version is running here is not.
            CURLOPT_USERAGENT      => 'CashuPayServer',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close(): deprecated since PHP 8.5 and a no-op since 8.0.

        if (!is_string($body) || $code !== 200 || strlen($body) > 8192) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $latest = isset($data['version']) ? trim((string)$data['version']) : '';
        // A version string is compared, printed, and nothing else; keep it to the
        // shape version_compare() understands rather than trusting the endpoint.
        if (!preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]{1,20})?$/', $latest)) {
            return null;
        }

        $out = ['latest' => $latest];

        if (isset($data['url']) && self::acceptableLink((string)$data['url'], $url)) {
            $out['url'] = (string)$data['url'];
        }
        $severity = isset($data['severity']) ? strtolower(trim((string)$data['severity'])) : 'normal';
        $out['severity'] = in_array($severity, ['normal', 'important', 'security'], true) ? $severity : 'normal';

        if (isset($data['min_supported'])) {
            $min = trim((string)$data['min_supported']);
            if (preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]{1,20})?$/', $min)) {
                $out['min_supported'] = $min;
            }
        }
        if (isset($data['notes'])) {
            $out['notes'] = mb_substr(trim((string)$data['notes']), 0, 300);
        }
        if (isset($data['released'])) {
            $released = trim((string)$data['released']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
                $out['released'] = $released;
            }
        }

        return $out;
    }

    /** HTTPS, and either the manifest's own host or the release host. */
    private static function acceptableLink(string $link, string $manifestUrl): bool
    {
        $parts = parse_url($link);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $own = strtolower((string)(parse_url($manifestUrl)['host'] ?? ''));
        return $host === 'github.com' || ($own !== '' && $host === $own);
    }
}
