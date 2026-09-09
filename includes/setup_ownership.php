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
 *  1. The first browser to open setup **claims** the installation automatically. Nothing
 *     to read, nothing to type — the normal case has no extra step at all.
 *  2. Any *other* browser is refused, and told plainly that setup was already started
 *     elsewhere and what to do about it.
 *  3. A one-time recovery code, written to a file in the (HTTP-protected) data directory,
 *     lets the real owner take over if the claim went to the wrong browser — cleared
 *     cookies, a different device, or an actual attacker.
 *
 * So the friction lands on the attacker and on the rare recovery case, not on the
 * merchant. WordPress mode does not use any of this: `manage_options` already proves
 * ownership.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Setup {
    private const TOKEN_FILE = 'setup-token.txt';
    private const CLAIM_COOKIE = 'cashupay_setup_claim';

    /** How long an unfinished claim holds the installation before anyone may re-claim. */
    private const CLAIM_TTL = 86400;

    /**
     * May this request configure the installation?
     *
     * Claims it for this browser when nobody has yet.
     */
    public static function ownershipVerified(): bool {
        if (defined('CASHUPAY_SETUP_TOKEN') && CASHUPAY_SETUP_TOKEN === '') {
            // Explicit opt-out, for a provisioning script on a host nobody else reaches.
            return true;
        }
        if (!empty($_SESSION['setup_ownership_verified'])) {
            return true;
        }

        $claim = Config::get('setup_claim');
        $now = time();

        // Nobody has claimed it, or an abandoned claim has aged out: claim it now.
        if (!is_array($claim) || ($claim['at'] ?? 0) + self::CLAIM_TTL < $now) {
            $secret = bin2hex(random_bytes(32));
            Config::set('setup_claim', ['hash' => hash('sha256', $secret), 'at' => $now]);
            self::sendClaimCookie($secret);
            $_SESSION['setup_ownership_verified'] = true;
            // Make sure a recovery code exists from this moment on, so the real owner
            // can take over if this claim turned out to be someone else's browser.
            self::expectedToken();
            return true;
        }

        // Claimed: only the browser holding the matching secret may continue.
        $presented = (string)($_COOKIE[self::CLAIM_COOKIE] ?? '');
        if ($presented !== '' && hash_equals((string)$claim['hash'], hash('sha256', $presented))) {
            $_SESSION['setup_ownership_verified'] = true;
            return true;
        }

        return false;
    }

    /** True when someone else's browser holds the claim (used to word the message). */
    public static function claimedByAnother(): bool {
        return is_array(Config::get('setup_claim')) && !self::ownershipVerified();
    }

    /**
     * Take over the installation with the recovery code from the data directory.
     */
    public static function verifyOwnershipToken(string $provided): bool {
        $expected = self::expectedToken();
        if ($expected === null || trim($provided) === '') {
            return false;
        }
        if (!hash_equals($expected, trim($provided))) {
            return false;
        }

        // Re-claim for this browser, so the previous holder is now the one locked out.
        $secret = bin2hex(random_bytes(32));
        Config::set('setup_claim', ['hash' => hash('sha256', $secret), 'at' => time()]);
        self::sendClaimCookie($secret);
        $_SESSION['setup_ownership_verified'] = true;

        return true;
    }

    /**
     * The recovery code this installation expects.
     *
     * `CASHUPAY_SETUP_TOKEN` in config.local.php wins, so a provisioning script can set
     * one without touching the data directory. Otherwise a random code is written to the
     * data directory, which is already denied over HTTP.
     */
    public static function expectedToken(): ?string {
        if (defined('CASHUPAY_SETUP_TOKEN') && CASHUPAY_SETUP_TOKEN !== '') {
            return (string)CASHUPAY_SETUP_TOKEN;
        }

        $path = self::tokenPath();
        if (is_file($path)) {
            $token = trim((string)@file_get_contents($path));
            if ($token !== '') {
                return $token;
            }
        }

        // Short and typeable: this gets read off a screen and typed by hand, and it only
        // has to survive the minutes-to-hours window before setup completes.
        $token = strtoupper(bin2hex(random_bytes(5)));
        if (@file_put_contents($path, $token . "\n", LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);

        return $token;
    }

    /** Where the operator will find the recovery code. */
    public static function tokenPath(): string {
        return Database::getDataDir() . '/' . self::TOKEN_FILE;
    }

    /** Path relative to the site root, which is what a file manager shows. */
    public static function tokenPathForDisplay(): string {
        $path = self::tokenPath();
        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $real = realpath($path) ?: $path;
        if ($root && str_starts_with($real, $root)) {
            return ltrim(substr($real, strlen($root)), '/');
        }
        return $path;
    }

    /** Ownership is settled once the installation has an admin password. */
    public static function clearOwnershipToken(): void {
        @unlink(self::tokenPath());
        Config::set('setup_claim', null);
        $_SESSION['setup_ownership_verified'] = true;
        self::sendClaimCookie('', true);
    }

    private static function sendClaimCookie(string $secret, bool $clear = false): void {
        if (headers_sent()) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::CLAIM_COOKIE, $secret, [
            'expires' => $clear ? time() - 3600 : time() + self::CLAIM_TTL,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
    }
}
