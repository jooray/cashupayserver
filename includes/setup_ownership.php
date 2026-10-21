<?php
/**
 * CashuPayServer - Installation ownership
 *
 * The problem: between "upload the files" and "open the URL", a fresh standalone install
 * is reachable by anyone. Whoever completes setup chooses the admin password, the mint
 * and the wallet seed — so a stranger who finds /setup.php first owns the merchant's
 * payment gateway, silently.
 *
 * CSRF does not help: the attacker's browser is handed its own session and token.
 *
 * The design has to work for a shop owner who has never heard of SSH, so requiring a
 * file read up front is not acceptable. Instead:
 *
 *  1. The first browser to *start* setup (submit the first step, a CSRF-checked POST)
 *     **claims** the installation. Nothing to read, nothing to type — the normal case
 *     has no extra step at all. A plain GET never claims: link-preview bots, uptime
 *     checkers and crawlers fetch pages, they do not submit forms, and a GET claim let
 *     any of them lock the merchant out (SPB-OWN-002).
 *  2. Any *other* browser is refused, and told plainly that setup was already started
 *     elsewhere and what to do about it.
 *  3. A one-time recovery code, written to a file in the data directory, lets the real
 *     owner take over if the claim went to the wrong browser — cleared cookies, a
 *     different device, or an actual attacker. The file name carries a random suffix
 *     (setup-token-<16 hex>.txt), so even a data directory that is wrongly web-served
 *     does not hand the code to whoever guesses the URL (SPB-UI-003). The code is only
 *     accepted in a POST body, rate-limited per client IP, and failures are logged
 *     (SPB-OWN-003). Taking over rotates the claim, and a session's verification is
 *     bound to the claim hash it was granted for, so the previous holder's still-open
 *     tab is locked out at once (SPB-OWN-001).
 *
 * Claim lifetime (OPUS-017): a claim on which no progress was made (no admin password
 * chosen yet) is released after IDLE_CLAIM_TTL without activity; once the admin password
 * exists the claim never ages out, so a stalled install cannot be taken over (and its
 * password overwritten) by the next visitor — the owner continues with their cookie or
 * the recovery code.
 *
 * So the friction lands on the attacker and on the rare recovery case, not on the
 * merchant. WordPress mode does not use any of this: `manage_options` already proves
 * ownership.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

class Setup {
    private const TOKEN_PREFIX = 'setup-token-';
    /** Pre-0.5.6 fixed name; migrated to a random name on first read. */
    private const LEGACY_TOKEN_FILE = 'setup-token.txt';
    private const CLAIM_COOKIE = 'cashupay_setup_claim';
    private const SESSION_KEY = 'setup_ownership_verified';

    /** A claim with no progress yet is released after this long without activity. */
    private const IDLE_CLAIM_TTL = 3 * 3600;
    /** The claim cookie outlives a stalled claim, since a claim with progress never expires. */
    private const COOKIE_TTL = 30 * 86400;
    /** How often activity refreshes the claim's "seen" time (avoids a write per request). */
    private const TOUCH_INTERVAL = 300;
    /** Recovery-code attempts per client IP per rate-limit window (60 s). */
    public const RECOVERY_ATTEMPTS = 10;

    /** Why the last verifyOwnershipToken() failed: 'invalid', 'rate_limited' or 'unavailable'. */
    public static ?string $lastRecoveryError = null;

    /** Explicit opt-out, for a provisioning script on a host nobody else reaches. */
    private static function optedOut(): bool {
        return defined('CASHUPAY_SETUP_TOKEN') && CASHUPAY_SETUP_TOKEN === '';
    }

    /**
     * May this request configure the installation?
     *
     * A pure check: it never claims. Use claim() from a deliberate, CSRF-checked POST.
     */
    public static function ownershipVerified(): bool {
        if (self::optedOut()) {
            return true;
        }
        $claim = self::activeClaim();
        if ($claim === null) {
            return false;
        }

        // The session flag holds the claim hash it was granted for: once the claim is
        // rotated (recovery code), an old session no longer matches.
        $bound = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($bound) && hash_equals((string)$claim['hash'], $bound)) {
            self::touch($claim);
            return true;
        }

        // A browser whose session expired but that still holds the claim cookie.
        $presented = $_COOKIE[self::CLAIM_COOKIE] ?? '';
        if (is_string($presented) && $presented !== ''
            && hash_equals((string)$claim['hash'], hash('sha256', $presented))) {
            $_SESSION[self::SESSION_KEY] = (string)$claim['hash'];
            self::touch($claim);
            return true;
        }

        return false;
    }

    /** Whether some browser currently holds the installation. */
    public static function isClaimed(): bool {
        return self::activeClaim() !== null;
    }

    /** True when someone else's browser holds the claim (used to word the message). */
    public static function claimedByAnother(): bool {
        return self::isClaimed() && !self::ownershipVerified();
    }

    /**
     * Claim the installation for this browser if nobody holds it.
     *
     * Call only when the visitor actively starts setup (the step-1 POST, after its CSRF
     * token validated) — never from a page render.
     */
    public static function claim(): bool {
        if (self::ownershipVerified()) {
            return true;
        }
        if (self::activeClaim() !== null) {
            return false;
        }
        self::assignClaim();
        // Make sure a recovery code exists from this moment on, so the real owner can
        // take over if this claim turned out to be someone else's browser.
        self::expectedToken();
        return true;
    }

    /**
     * Take over the installation with the recovery code from the data directory.
     *
     * Callers pass the POST body only: a code in the query string lands in access logs,
     * browser history and Referer headers.
     */
    public static function verifyOwnershipToken(string $provided): bool {
        self::$lastRecoveryError = null;
        $provided = trim($provided);
        if ($provided === '') {
            self::$lastRecoveryError = 'invalid';
            return false;
        }

        $ip = Security::getClientIp();
        if (!Security::checkRateLimit('setup_recovery', $ip, self::RECOVERY_ATTEMPTS)) {
            error_log("CashuPayServer: setup recovery code attempts rate-limited for {$ip}");
            self::$lastRecoveryError = 'rate_limited';
            return false;
        }

        $expected = self::expectedToken();
        if ($expected === null) {
            self::$lastRecoveryError = 'unavailable';
            return false;
        }
        // Generated codes are upper-case hex; accept them typed in lower case too.
        if (!hash_equals(strtoupper($expected), strtoupper($provided))) {
            error_log("CashuPayServer: wrong setup recovery code from {$ip}");
            self::$lastRecoveryError = 'invalid';
            return false;
        }

        // Re-claim for this browser. The previous holder's session is bound to the old
        // claim hash, so it is locked out from its next request on.
        error_log("CashuPayServer: setup ownership taken over with the recovery code from {$ip}");
        self::assignClaim();
        return true;
    }

    /**
     * The recovery code this installation expects.
     *
     * `CASHUPAY_SETUP_TOKEN` in config.local.php wins, so a provisioning script can set
     * one without touching the data directory. Otherwise a random code is written to a
     * randomly named file in the data directory.
     */
    public static function expectedToken(): ?string {
        if (defined('CASHUPAY_SETUP_TOKEN') && CASHUPAY_SETUP_TOKEN !== '') {
            return (string)CASHUPAY_SETUP_TOKEN;
        }

        $path = self::existingTokenFile();
        if ($path !== null) {
            $token = trim((string)@file_get_contents($path));
            if ($token !== '') {
                return $token;
            }
        }

        // An install upgraded mid-setup: move the code off the guessable name.
        $legacy = Database::getDataDir() . '/' . self::LEGACY_TOKEN_FILE;
        $token = is_file($legacy) ? trim((string)@file_get_contents($legacy)) : '';
        if ($token === '') {
            // Short and typeable: this gets read off a screen and typed by hand, and it
            // only has to survive the minutes-to-hours window before setup completes.
            $token = strtoupper(bin2hex(random_bytes(5)));
        }

        $path = $path ?? Database::getDataDir() . '/' . self::TOKEN_PREFIX . bin2hex(random_bytes(8)) . '.txt';
        if (@file_put_contents($path, $token . "\n", LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);
        @unlink($legacy);

        return $token;
    }

    /** The recovery-code file, if one has been written. */
    public static function tokenPath(): ?string {
        return self::existingTokenFile();
    }

    private static function existingTokenFile(): ?string {
        foreach (glob(Database::getDataDir() . '/' . self::TOKEN_PREFIX . '*.txt') ?: [] as $file) {
            if (preg_match('/^setup-token-[0-9a-f]{16}\.txt$/', basename($file)) && is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Where the operator finds the recovery code, in plain words.
     *
     * Deliberately not the absolute server path: this is shown to whoever is locked out,
     * which may be the attacker, and the exact file name is what keeps it unguessable.
     */
    public static function tokenLocationHint(): string {
        if (defined('CASHUPAY_SETUP_TOKEN') && CASHUPAY_SETUP_TOKEN !== '') {
            return 'The recovery code is the CASHUPAY_SETUP_TOKEN value in the file includes/config.local.php of your CashuPayServer folder.';
        }
        if (defined('CASHUPAY_DATA_DIR')) {
            return 'Open the data folder that was set up for CashuPayServer outside your website folder'
                . ' (its location is written in includes/config.local.php, after CASHUPAY_DATA_DIR).'
                . ' The code is in a file whose name starts with setup-token- and ends with .txt.';
        }
        return 'Open the folder named data inside the folder where you uploaded CashuPayServer'
            . ' (the same folder that contains setup.php). The code is in a file whose name starts'
            . ' with setup-token- and ends with .txt.';
    }

    /**
     * The data folder as the operator's file manager shows it: relative to the website
     * folder, or a plain description when it lives outside it. Never the absolute path,
     * which this page would otherwise print to any visitor.
     */
    public static function relativeDataDirForDisplay(): string {
        $dir = realpath(Database::getDataDir()) ?: Database::getDataDir();
        foreach ([defined('ABSPATH') ? (string)ABSPATH : '', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $root) {
            $root = $root !== '' ? (realpath($root) ?: '') : '';
            if ($root !== '' && str_starts_with($dir, rtrim($root, '/') . '/')) {
                return ltrim(substr($dir, strlen(rtrim($root, '/'))), '/');
            }
        }
        return 'a folder outside your website folder';
    }

    /** Ownership is settled once setup completes. */
    public static function clearOwnershipToken(): void {
        foreach (glob(Database::getDataDir() . '/' . self::TOKEN_PREFIX . '*.txt') ?: [] as $file) {
            @unlink($file);
        }
        @unlink(Database::getDataDir() . '/' . self::LEGACY_TOKEN_FILE);
        Config::set('setup_claim', null);
        unset($_SESSION[self::SESSION_KEY]);
        self::sendClaimCookie('', true);
    }

    /** The current claim, or null when there is none or an idle one was released. */
    private static function activeClaim(): ?array {
        $claim = Config::get('setup_claim');
        if (!is_array($claim) || !is_string($claim['hash'] ?? null) || $claim['hash'] === '') {
            return null;
        }
        // Progress made (the admin password exists): never auto-released (OPUS-017).
        if (Config::getAdminPasswordHash() !== null) {
            return $claim;
        }
        $lastActivity = max((int)($claim['at'] ?? 0), (int)($claim['seen'] ?? 0));
        if ($lastActivity + self::IDLE_CLAIM_TTL < time()) {
            return null;
        }
        return $claim;
    }

    private static function assignClaim(): void {
        // A session id the browser arrived with may have been planted (cookie tossing from
        // a sibling subdomain): the claim must not be granted to an id someone else knows
        // (N-4). The session data, CSRF token included, carries over to the new id.
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        self::resetWizardState();
        $secret = bin2hex(random_bytes(32));
        $hash = hash('sha256', $secret);
        Config::set('setup_claim', ['hash' => $hash, 'at' => time(), 'seen' => time()]);
        self::sendClaimCookie($secret);
        $_SESSION[self::SESSION_KEY] = $hash;
    }

    /**
     * Forget what a previous claimant left in the wizard (M-2, M-3).
     *
     * A claim is only ever (re)assigned before setup completes: on the first claim, after an
     * idle claim was released, or on a recovery-code takeover. Whatever an earlier holder
     * chose must not carry over to the new one: the pinned origin (checkout links, pairing
     * and cron URLs, the X-Cron-Key destination), the URL mode, and stores they started but
     * never finished (their mint would be adopted silently). A store with a wallet seed is
     * finished and is never touched here.
     */
    private static function resetWizardState(): void {
        Config::delete('base_url');
        Config::delete('url_mode');
        Database::delete('stores', "seed_phrase IS NULL OR seed_phrase = ''");
        unset($_SESSION['setup_created_stores'], $_SESSION['setup_store_id'],
            $_SESSION['mint_url_temp'], $_SESSION['mint_units']);
    }

    /** Record that the holder is still active, so an idle-claim timeout restarts. */
    private static function touch(array $claim): void {
        if ((int)($claim['seen'] ?? $claim['at'] ?? 0) + self::TOUCH_INTERVAL > time()) {
            return;
        }
        $claim['seen'] = time();
        Config::set('setup_claim', $claim);
    }

    private static function sendClaimCookie(string $secret, bool $clear = false): void {
        if (headers_sent()) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::CLAIM_COOKIE, $secret, [
            'expires' => $clear ? time() - 3600 : time() + self::COOKIE_TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
    }
}
