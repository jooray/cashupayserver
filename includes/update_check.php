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
 * What travels: one HTTP GET for a static file, every six hours, from the background
 * runner or (runIfDue()) an admin dashboard load. No instance identifier, no version, no store count, no POST body, no
 * cookies, and a User-Agent that names the software but not which release it is.
 * The comparison happens here, on the operator's own machine; the server learns
 * only that somebody asked for a file. That is still a phone-home, which is why
 * `update_check_enabled` turns it off and the settings toggle says plainly what
 * it does.
 *
 * Nothing here is allowed to throw or to slow a page down much. Checking happens on
 * the runner's schedule, or at most once per schedule slot from a dashboard load with a
 * 3-second cap (runIfDue); status() reads the last cached answer and nothing
 * else, so the day this endpoint is unreachable is a day the admin page is
 * exactly as fast as always and says nothing about it.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/safe_mode.php';

class UpdateCheck
{
    /** Where the release manifest lives. Overridable so a fork points at its own. */
    public const DEFAULT_URL = 'https://cashupayserver.org/version.json';

    /**
     * Every six hours. A release could wait a day, but the same request carries the
     * signed safety notice (SafeMode), and an install with a known hole should hear
     * about it the same morning, not tomorrow.
     */
    private const INTERVAL = 21600;

    /** Retry sooner than a full day after a failure, but not so soon it is a retry loop. */
    private const RETRY_INTERVAL = 3600;

    private const CACHE_KEY = 'update_check_state';

    /** Largest manifest accepted; room for release notes plus a signed shutdown notice. */
    private const MAX_BYTES = 16384;

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
     * Why this endpoint may not be used, in plain words; null when it is fine.
     *
     * The manifest carries the signed emergency notice and the update prompt. Over plain
     * HTTP anyone on the path can suppress or replace it (MINIMAX N2). A loopback address
     * is allowed so the test fixture can serve one without TLS.
     */
    public static function endpointProblem(): ?string
    {
        $url = self::endpoint();
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme === 'https') {
            return null;
        }
        if ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            return null;
        }
        return 'This update address does not start with https://, so update news and the emergency notice could be changed by anyone on the way. Ask whoever set up this server to use an https address.';
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
        if (!self::due($state)) {
            return 'skipped';
        }

        $fetched = self::fetch(self::endpoint());
        if ($fetched === null) {
            // Remember the attempt so a dead endpoint is retried hourly, not every
            // minute, and keep whatever we last knew rather than forgetting it.
            $state['at'] = time();
            $state['failed'] = true;
            Config::set(self::CACHE_KEY, $state);
            return 'unreachable';
        }

        $fetched['at'] = time();
        $fetched['ok_at'] = $fetched['at'];
        Config::set(self::CACHE_KEY, $fetched);
        return version_compare(CASHUPAY_VERSION, $fetched['latest'], '<') ? 'update available' : 'current';
    }

    /**
     * Run the check from an ordinary page load when it is due (SPB-OPS-005).
     *
     * The signed emergency notice rides on this request, and the background runner is
     * the only other caller — an install with no cron and no pinned server address never
     * runs it. So admin dashboard loads call this: same schedule (six hours, an hour
     * after a failure), same opt-out, a short timeout, and it never throws. The slot is
     * claimed before fetching, so concurrent page loads do not all fetch.
     */
    public static function runIfDue(): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $state = Config::get(self::CACHE_KEY);
            $state = is_array($state) ? $state : [];
            if (!self::due($state)) {
                return;
            }
            // Provisional: counts as a failed attempt unless the fetch below succeeds.
            $state['at'] = time();
            $state['failed'] = true;
            Config::set(self::CACHE_KEY, $state);

            $fetched = self::fetch(self::endpoint(), 3);
            if ($fetched === null) {
                return;
            }
            $fetched['at'] = time();
            $fetched['ok_at'] = $fetched['at'];
            Config::set(self::CACHE_KEY, $fetched);
        } catch (Throwable $e) {
            error_log('CashuPayServer: update check failed: ' . $e->getMessage());
        }
    }

    /**
     * `at` is the last attempt; `failed` says whether it failed. A failure is retried
     * after an hour even when an older answer is cached — keying the retry on "no
     * answer yet" made every failure after a success wait the full interval.
     */
    private static function due(array $state): bool
    {
        $last = (int)($state['at'] ?? 0);
        $wait = !empty($state['failed']) || empty($state['latest']) ? self::RETRY_INTERVAL : self::INTERVAL;
        return $last <= time() - $wait;
    }

    /**
     * Fetch and validate the manifest. Null on anything unexpected.
     *
     * The URL in the response becomes a link an operator is invited to click while
     * being told to upgrade, so a compromised or mistyped endpoint must not be able
     * to point them anywhere it likes. Only this project's GitHub releases qualify:
     * the manifest is not signed (only its safe_mode notice is), so its own host is
     * no more trustworthy than anything else it says.
     */
    private static function fetch(string $url, int $timeout = 8): ?array
    {
        if (self::endpointProblem() !== null) {
            error_log('CashuPayServer: update check skipped: ' . self::endpointProblem());
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            // Stop reading past the size limit instead of buffering whatever the
            // endpoint sends and checking afterwards.
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $body = null;
                    return 0; // aborts the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Names the software, never the release. What we are asking about is
            // public; which version is running here is not.
            CURLOPT_USERAGENT      => 'CashuPayServer',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ] + cashupay_curl_protocol_options());
        $body = '';
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close(): deprecated since PHP 8.5 and a no-op since 8.0.

        if (!is_string($body) || $body === '' || $code !== 200) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        // Verified against pinned keys, not trusted because of where it came from.
        SafeMode::ingest($data);

        $latest = isset($data['version']) ? trim((string)$data['version']) : '';
        // A version string is compared, printed, and nothing else; keep it to the
        // shape version_compare() understands rather than trusting the endpoint.
        if (!preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]{1,20})?$/', $latest)) {
            return null;
        }

        $out = ['latest' => $latest];

        if (isset($data['url']) && self::acceptableLink((string)$data['url'])) {
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

    /** This project's GitHub releases page or one release tag on it, nothing else. */
    private static function acceptableLink(string $link): bool
    {
        return cashupay_is_trusted_release_link($link, null);
    }
}
