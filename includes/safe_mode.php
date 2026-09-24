<?php
/**
 * Signed emergency shutdown ("kill switch") for versions with a known, serious hole.
 *
 * The nuclear option, meant never to be used. If a release turns out to let strangers
 * take an operator's money, the maintainers publish a signed notice naming the affected
 * versions. An install on one of them writes a local flag and from then on every
 * CashuPayServer entry point — checkout, API, admin, setup, cron, receive — stops at the
 * top with a 503 and a plain explanation. Nothing is reachable, so nothing can be
 * exploited, including the admin: a hole that reaches the web interface must not be
 * able to switch the shutdown off.
 *
 * Getting out needs a person with file access, which the operator has and a web
 * attacker does not:
 * - upgrade to a version outside the notice's range (the normal way; the flag then no
 *   longer applies and is removed on the next request), or
 * - delete the flag file from the data folder. While the notice is still published the
 *   next update check writes it again, so this is only for a maintainer-withdrawn
 *   notice or an operator who has fixed the hole another way.
 * An expert can also define CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN in config.local.php.
 *
 * Rules, from "A signed kill switch" (juraj.bednar.io, 2026-09-18):
 * - Signed, not merely served: a Nostr event (NIP-01, BIP-340) from a key pinned here,
 *   verified here. Whoever controls the manifest host can withhold or replay a notice,
 *   but not forge one, and a replay only names versions that really were affected.
 * - Overridable by the operator (above), never by the maintainers' choice alone.
 * - On unless the operator turned the update check off; it rides on that request.
 *
 * Fail-open everywhere else: no manifest, no notice, a bad signature or an unknown key
 * change nothing. Funds are untouched: ecash stays in the database and at the mint, the
 * seed phrase still recovers it, and invoices paid during the shutdown are credited by
 * the normal late-payment recovery after the upgrade.
 *
 * Publishing a notice: docs/EMERGENCY-SHUTDOWN.md and scripts/emergency-shutdown.php.
 */

require_once __DIR__ . '/update_check.php';

class SafeMode
{
    /**
     * Keys allowed to sign a notice (x-only secp256k1, 64 hex, as in a Nostr pubkey);
     * one valid signature from any of them is enough. A fork that points
     * CASHUPAY_UPDATE_URL at its own manifest sets CASHUPAY_SAFE_MODE_PUBKEYS instead.
     */
    public const DEFAULT_PUBKEYS = [
        // npub18scydh6yusrkhe67f8uz9mnxxt5znae59ctvhxxpzefkf7xwnx4q0nalja
        '3c3046df44e4076be75e49f822ee6632e829f7342e16cb98c1165364f8ce99aa',
        // npub1m2mvvpjugwdehtaskrcl7ksvdqnnhnjur9v6g9v266nss504q7mqvlr8p9
        'dab6c6065c439b9bafb0b0f1ff5a0c68273bce5c1959a4158ad6a70851f507b6',
    ];

    /** NIP-78 application-specific data, with this d-tag. */
    public const EVENT_KIND = 30078;
    public const EVENT_D_TAG = 'cashupayserver-safe-mode';

    /** Lives in the data folder, next to the database. */
    public const FLAG_FILE = 'EMERGENCY-SHUTDOWN.json';

    public static function pubkeys(): array
    {
        $keys = defined('CASHUPAY_SAFE_MODE_PUBKEYS') && is_array(CASHUPAY_SAFE_MODE_PUBKEYS)
            ? CASHUPAY_SAFE_MODE_PUBKEYS
            : self::DEFAULT_PUBKEYS;
        return array_values(array_filter(
            array_map(fn($k) => strtolower(trim((string)$k)), $keys),
            fn($k) => (bool)preg_match('/^[0-9a-f]{64}$/', $k)
        ));
    }

    public static function flagPath(): string
    {
        require_once __DIR__ . '/database.php';
        return Database::getDataDir() . '/' . self::FLAG_FILE;
    }

    /**
     * The shutdown that applies to this install right now, or null.
     *
     * Reads one small local file and nothing else, so it is cheap enough for the top of
     * every request and works when the database or the network does not.
     */
    public static function shutdown(): ?array
    {
        if (defined('CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN') && CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN) {
            return null;
        }
        $path = self::flagPath();
        if (!is_file($path)) {
            return null;
        }
        $flag = json_decode((string)@file_get_contents($path), true);
        if (!is_array($flag) || !is_array($flag['ranges'] ?? null)) {
            // A flag we cannot read still means someone meant to stop this server.
            return ['id' => 'unreadable', 'reason' => '', 'url' => null, 'fixed' => null];
        }
        $range = self::matchingRange($flag['ranges'], CASHUPAY_VERSION);
        if ($range === null) {
            // Upgraded past the affected versions: the shutdown is over.
            @unlink($path);
            return null;
        }
        return [
            'id' => (string)($flag['id'] ?? ''),
            'reason' => (string)($flag['reason'] ?? ''),
            'url' => isset($flag['url']) ? (string)$flag['url'] : null,
            'fixed' => $range['fixed'] ?? null,
        ];
    }

    /**
     * Stop this request if the install is shut down. Called at the top of every entry
     * point (includes/entrypoint_guard.php). In WordPress it ends only CashuPay's own
     * request; the rest of the site keeps working.
     */
    public static function haltIfShutDown(): void
    {
        $shutdown = self::shutdown();
        if ($shutdown === null) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "CashuPayServer is shut down by an emergency notice; upgrade it.\n");
            exit(1);
        }
        http_response_code(503);
        header('Retry-After: 3600');
        header('Cache-Control: no-store');

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '') . (string)($_SERVER['PATH_INFO'] ?? '');
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($uri, '/api/') || str_contains($uri, '/v1/') || str_contains($accept, 'application/json')) {
            // Greenfield shape, so a shop plugin shows its own "payment method unavailable".
            header('Content-Type: application/json');
            exit(json_encode([
                'code' => 'service-unavailable',
                'message' => 'This payment server is shut down for safety until it is upgraded.',
            ]));
        }

        header('Content-Type: text/html; charset=utf-8');
        $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $upgrade = $shutdown['fixed'] !== null ? 'version ' . $shutdown['fixed'] . ' or later' : 'the latest version';
        $link = $shutdown['url'] ?? 'https://github.com/jooray/cashupayserver/releases';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Temporarily unavailable</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#222}'
            . 'h1{font-size:1.4rem}h2{font-size:1.1rem;margin-top:2rem}code{background:#f2f2f2;padding:0 .25rem}</style>'
            . '</head><body>'
            . '<h1>Payments here are temporarily unavailable</h1>'
            . '<p>Please try again later, or pay another way.</p>'
            . '<h2>If you run this payment server</h2>'
            . '<p>It has shut itself down. The CashuPayServer developers found a serious security problem in '
            . 'the version it runs (' . $h(CASHUPAY_VERSION) . ') and sent a signed warning, so it stopped '
            . 'before anyone could use the problem to take your money.'
            . ($shutdown['reason'] !== '' ? ' ' . $h($shutdown['reason']) : '') . '</p>'
            . '<p><strong>Your money is still there.</strong> Nothing was sent anywhere. Your seed phrase still '
            . 'recovers everything, and customers who paid will be credited after the upgrade.</p>'
            . '<p><strong>What to do:</strong> upload ' . $h($upgrade) . ' over this installation, the same way '
            . 'you installed it, keeping the <code>data</code> folder. Everything starts again by itself. '
            . '<a href="' . $h($link) . '" rel="noopener">How to upgrade</a></p>'
            . '</body></html>';
        exit;
    }

    /**
     * Take the notice out of a fetched manifest and, if it is genuine and names this
     * version, shut the install down. Called by UpdateCheck; never throws.
     */
    public static function ingest(array $manifest): void
    {
        if (!isset($manifest['safe_mode']) || !is_array($manifest['safe_mode'])) {
            return;
        }
        try {
            $notice = self::verify($manifest['safe_mode']);
            if ($notice === null || self::matchingRange($notice['ranges'], CASHUPAY_VERSION) === null) {
                return;
            }
            if (defined('CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN') && CASHUPAY_DISABLE_EMERGENCY_SHUTDOWN) {
                error_log("CashuPayServer: IGNORING emergency shutdown {$notice['id']} (disabled in config.local.php): {$notice['reason']}");
                return;
            }
            self::writeFlag($notice);
            error_log("CashuPayServer: EMERGENCY SHUTDOWN by signed notice {$notice['id']}: {$notice['reason']}");
        } catch (Throwable $e) {
            error_log('CashuPayServer: ignoring emergency-shutdown notice: ' . $e->getMessage());
        }
    }

    /** Write the flag atomically, so a half-written file never exists. */
    private static function writeFlag(array $notice): void
    {
        $path = self::flagPath();
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $body = json_encode($notice + ['received_at' => time()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("could not write {$path}");
        }
    }

    /**
     * Verify a signed notice event and extract what it says. Null when it is not a
     * valid notice from a pinned key.
     */
    public static function verify(array $event): ?array
    {
        $pubkeys = self::pubkeys();
        if ($pubkeys === []) {
            return null;
        }
        foreach (['id', 'pubkey', 'sig', 'content'] as $field) {
            if (!isset($event[$field]) || !is_string($event[$field])) {
                return null;
            }
        }
        $pubkey = strtolower($event['pubkey']);
        if (!in_array($pubkey, $pubkeys, true)
            || (int)($event['kind'] ?? 0) !== self::EVENT_KIND
            || !is_int($event['created_at'] ?? null)
            || !is_array($event['tags'] ?? null)
            || !in_array(['d', self::EVENT_D_TAG], $event['tags'], true)) {
            return null;
        }
        // A notice dated in the future is a clock problem or a forgery attempt; neither
        // should stop a shop.
        if ($event['created_at'] > time() + 3600) {
            return null;
        }

        $id = self::eventId($event);
        if (!hash_equals($id, strtolower($event['id']))) {
            return null;
        }
        require_once dirname(__DIR__) . '/cashu-wallet-php/CashuWallet.php';
        if (!\Cashu\Secp256k1::schnorrVerify($pubkey, hex2bin($id), strtolower($event['sig']))) {
            return null;
        }

        $content = json_decode($event['content'], true);
        if (!is_array($content) || !is_array($content['affected'] ?? null)) {
            return null;
        }
        $ranges = [];
        foreach ($content['affected'] as $range) {
            if (!is_array($range)) {
                continue;
            }
            $from = isset($range['from']) ? trim((string)$range['from']) : null;
            $fixed = isset($range['fixed']) ? trim((string)$range['fixed']) : null;
            if (($from !== null && !self::isVersion($from)) || ($fixed !== null && !self::isVersion($fixed))) {
                continue;
            }
            $ranges[] = ['from' => $from, 'fixed' => $fixed];
        }
        if ($ranges === []) {
            return null;
        }
        $url = isset($content['url']) ? (string)$content['url'] : null;
        if ($url !== null && !self::acceptableLink($url)) {
            $url = null;
        }

        return [
            'id' => $id,
            'created_at' => $event['created_at'],
            'ranges' => $ranges,
            'reason' => mb_substr(trim((string)($content['reason'] ?? '')), 0, 300),
            'url' => $url,
        ];
    }

    /** NIP-01 event id: sha256 of the canonical serialization. */
    public static function eventId(array $event): string
    {
        return hash('sha256', json_encode(
            [0, strtolower((string)$event['pubkey']), (int)$event['created_at'], (int)$event['kind'],
             $event['tags'], (string)$event['content']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /** First range that contains $version: from <= version < fixed (either end open). */
    public static function matchingRange(array $ranges, string $version): ?array
    {
        foreach ($ranges as $range) {
            $from = $range['from'] ?? null;
            $fixed = $range['fixed'] ?? null;
            if (($from === null || version_compare($version, $from, '>='))
                && ($fixed === null || version_compare($version, $fixed, '<'))) {
                return $range;
            }
        }
        return null;
    }

    private static function isVersion(string $v): bool
    {
        return (bool)preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]{1,20})?$/', $v);
    }

    /** Only links an operator can trust while being told to upgrade. */
    private static function acceptableLink(string $link): bool
    {
        $parts = parse_url($link);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        return $host === 'cashupayserver.org'
            || ($host === 'github.com' && str_starts_with((string)($parts['path'] ?? ''), '/jooray/cashupayserver/'));
    }
}
