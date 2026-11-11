<?php
/**
 * Managed-install detection and provisioned values.
 *
 * A "managed install" is one deployed by an external orchestrator that
 * embeds BareBits behind exactly one shop and operates it from the shop
 * platform's own admin — the GPL WordPress companion plugin's "install
 * BareBits alongside WordPress" flow is the canonical (currently only)
 * example, but the mechanism is deliberately platform-agnostic: the
 * orchestrator declares everything through user_config.php constants
 * (data, not code), and nothing here knows or calls any platform API.
 *
 * The declaration shapes the product for the single-shop case:
 *   - the admin UI runs single-store (no store selector / add-store) and
 *     hides the sections the shop platform owns (Products, Customers) and
 *     the account/user management (login is automatic via SSO tokens),
 *   - the payment page's payer email capture defaults OFF (the shop owns
 *     customer emails),
 *   - payer-facing redirects prefer the shop's front page,
 *   - the setup wizard skips the cron screen (the orchestrator pings
 *     cron.php) and the password screen (the admin account is pre-seeded
 *     from a hash the orchestrator wrote).
 *
 * Mirrors the Desktop class convention: constants win, the env var of the
 * same name is accepted, "0"/empty means off — that also makes it the test
 * hook.
 */

final class ManagedInstall {
    /** Is this deployment declared as a managed (single-shop) install? */
    public static function isManaged(): bool {
        if (defined('CASHUPAY_MANAGED_INSTALL')) {
            return (bool)CASHUPAY_MANAGED_INSTALL;
        }
        $env = getenv('CASHUPAY_MANAGED_INSTALL');
        return $env !== false && $env !== '' && $env !== '0';
    }

    /**
     * The shop's public front page, for payer-facing redirects ("Return to
     * Shop", admin-created invoices). '' when not provisioned. http(s) only —
     * the value ends up in Location headers and hrefs.
     */
    public static function shopUrl(): string {
        $url = defined('CASHUPAY_SHOP_URL') ? (string)CASHUPAY_SHOP_URL
            : (string)(getenv('CASHUPAY_SHOP_URL') ?: '');
        $url = trim($url);
        return preg_match('#^https?://#i', $url) ? rtrim($url, '/') : '';
    }

    /**
     * Password hash the orchestrator provisioned for the admin account
     * (a PHP password_hash() string). '' when not provisioned. The wizard
     * seeds the `admin` user from it and drops its password screen; the
     * plaintext lives only on the orchestrator's side.
     */
    public static function adminPasswordHash(): string {
        $hash = defined('CASHUPAY_ADMIN_PASSWORD_HASH') ? (string)CASHUPAY_ADMIN_PASSWORD_HASH
            : (string)(getenv('CASHUPAY_ADMIN_PASSWORD_HASH') ?: '');
        // Sanity: a password_hash() string, not a stray plaintext.
        return (strlen($hash) >= 30 && $hash[0] === '$') ? $hash : '';
    }

    /**
     * SHA-256 hex hash of the orchestrator's SSO key. Arms sso.php: holders
     * of the plaintext key can mint short-lived single-use admin login
     * tokens, which is how "open BareBits from the shop admin" logs the
     * operator in without a password prompt. '' when not provisioned.
     */
    public static function ssoKeyHash(): string {
        $hash = defined('CASHUPAY_SSO_KEY_HASH') ? (string)CASHUPAY_SSO_KEY_HASH
            : (string)(getenv('CASHUPAY_SSO_KEY_HASH') ?: '');
        $hash = strtolower(trim($hash));
        return preg_match('/^[0-9a-f]{64}$/', $hash) ? $hash : '';
    }

    /**
     * URL of the orchestrator's "the wizard is finished" endpoint. When
     * provisioned, the wizard's completion screen renders a single primary
     * "return to the shop admin" button pointing here (target="_top", so it
     * breaks out of an embedding iframe) instead of the manual connect-your-
     * e-commerce instructions — the orchestrator collects credentials through
     * the provisioning handshake and finishes the wiring itself. Pure data:
     * nothing on this side ever calls the URL; the operator's browser
     * navigates to it. '' when not provisioned. http(s) only — the value
     * ends up in an href.
     */
    public static function returnUrl(): string {
        $url = defined('CASHUPAY_MANAGED_RETURN_URL') ? (string)CASHUPAY_MANAGED_RETURN_URL
            : (string)(getenv('CASHUPAY_MANAGED_RETURN_URL') ?: '');
        $url = trim($url);
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * URL template for the shop-side "retry an expired invoice" endpoint;
     * '{invoiceId}' is replaced with the raw invoice id. '' when not
     * provisioned. The payment page's expired screen renders a retry button
     * from it for invoices that carry an e-commerce orderId.
     */
    public static function retryUrlTemplate(): string {
        $tpl = defined('CASHUPAY_RETRY_URL_TEMPLATE') ? (string)CASHUPAY_RETRY_URL_TEMPLATE
            : (string)(getenv('CASHUPAY_RETRY_URL_TEMPLATE') ?: '');
        $tpl = trim($tpl);
        return preg_match('#^https?://#i', $tpl) ? $tpl : '';
    }

    /**
     * Whether the wizard may skip its password screen: a hash is provisioned
     * AND an admin account exists to sign in with. Usually that account is
     * the one seedAdminIfProvisioned just created; when the users table
     * already held a merchant-created admin the seed no-ops, but skipping is
     * still right — the merchant knows their own credentials, and the
     * password screen would refuse to run anyway (its re-entry guard rejects
     * any run with an existing admin). The one case this must catch is a
     * hash provisioned over a users table with NO admin row (e.g. only
     * lesser-role rows survived a partial restore, so the seed no-oped):
     * skipping then would complete a wizard nobody can ever log into.
     * Stable for the duration of a wizard run: nothing but the (absent)
     * password screen writes users mid-run.
     */
    public static function adminSeededFromProvisionedHash(): bool {
        if (self::adminPasswordHash() === '') {
            return false;
        }
        return Database::fetchOne(
            "SELECT id FROM users WHERE role = ? LIMIT 1", [Auth::ROLE_ADMIN]
        ) !== null;
    }

    /**
     * Seed the provisioned admin account if it doesn't exist yet. Called
     * from the wizard's bootstrap; idempotent, a no-op unless a hash is
     * provisioned and the users table is still empty (a merchant-created
     * account is never touched).
     */
    public static function seedAdminIfProvisioned(): void {
        $hash = self::adminPasswordHash();
        if ($hash === '') {
            return;
        }
        $existing = Database::fetchOne("SELECT id FROM users LIMIT 1");
        if ($existing !== null) {
            return;
        }
        Database::insert('users', [
            'id'            => Database::generateId('user'),
            'username'      => 'admin',
            'password_hash' => $hash,
            'role'          => Auth::ROLE_ADMIN,
            'created_at'    => Database::timestamp(),
        ]);
    }
}
