<?php
/**
 * CashuPayServer - Installation ownership
 *
 * A fresh standalone install is reachable by anyone who can reach the server, and the
 * documented flow is "upload the files, then open the URL". Between those two moments,
 * whoever loads `setup.php` first chooses the admin password, the mint and the seed.
 *
 * CSRF does not help: the attacker's browser is handed its own session and token. What
 * is needed is proof of access to the *server*, so a one-time token is written to a file
 * inside the (HTTP-protected) data directory, and setup will not act without it. An
 * operator reads it over SFTP or a hosting file manager — no extra infrastructure.
 *
 * WordPress mode does not use this: `manage_options` already proves ownership.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Setup {
    private const TOKEN_FILE = 'setup-token.txt';

    /** Ownership already proved in this session. */
    public static function ownershipVerified(): bool {
        if (defined('CASHUPAY_SETUP_TOKEN') && CASHUPAY_SETUP_TOKEN === '') {
            // An explicit empty constant is a deliberate opt-out (e.g. an install driven
            // entirely by a provisioning script on a host nobody else can reach).
            return true;
        }
        return !empty($_SESSION['setup_ownership_verified']);
    }

    /**
     * Compare a submitted token with the expected one and remember the result.
     */
    public static function verifyOwnershipToken(string $provided): bool {
        $expected = self::expectedToken();
        if ($expected === null || $provided === '') {
            return false;
        }
        if (!hash_equals($expected, trim($provided))) {
            return false;
        }
        $_SESSION['setup_ownership_verified'] = true;
        return true;
    }

    /**
     * The token this installation expects.
     *
     * `CASHUPAY_SETUP_TOKEN` in config.local.php wins, so a provisioning script can set
     * one without touching the data directory. Otherwise a random token is generated on
     * first use and written to the data directory, which is already denied over HTTP.
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

        $token = bin2hex(random_bytes(16));
        if (@file_put_contents($path, $token . "\n", LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);

        return $token;
    }

    /** Where the operator will find the token. */
    public static function tokenPath(): string {
        return Database::getDataDir() . '/' . self::TOKEN_FILE;
    }

    /** Invalidate the token once the installation has an owner. */
    public static function clearOwnershipToken(): void {
        @unlink(self::tokenPath());
        $_SESSION['setup_ownership_verified'] = true;
    }
}
