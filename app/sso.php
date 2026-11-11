<?php
/**
 * CashuPayServer — SSO login-token handoff for managed installs.
 *
 * Lets the orchestrator that deployed this install (the GPL WordPress
 * companion plugin) log its operator into the BareBits admin without a
 * password prompt:
 *
 *   1. The orchestrator POSTs its SSO key (plaintext; only the SHA-256
 *      hash lives here, in user_config.php as CASHUPAY_SSO_KEY_HASH) and
 *      receives a login token.
 *   2. It sends the operator's browser to GET sso.php?token=… — the token
 *      is consumed, an admin session starts, and the browser is redirected
 *      into the admin.
 *
 * Token properties: 256-bit random, stored only as a hash, single use,
 * 60-second lifetime, one outstanding token at a time (a new mint replaces
 * the old). The endpoint is a 404 unless the deployment provisioned an SSO
 * key hash, so ordinary installs expose nothing. Minting requires the admin
 * account to exist (the wizard seeds it from CASHUPAY_ADMIN_PASSWORD_HASH),
 * and answers {"status":"pending"} before that.
 */

require_once __DIR__ . '/includes/http_status.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/managed.php';

const CASHUPAY_SSO_TOKEN_TTL_SECONDS = 60;

$keyHash = ManagedInstall::ssoKeyHash();
if ($keyHash === '') {
    cashupay_status(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $key = (string)($_SERVER['HTTP_X_SSO_KEY'] ?? ($_POST['sso_key'] ?? ''));
    if ($key === '' || !hash_equals($keyHash, hash('sha256', $key))) {
        cashupay_status(403);
        echo json_encode(['error' => 'Invalid SSO key']);
        exit;
    }

    if (!Database::isInitialized()
            || Database::fetchOne("SELECT id FROM users WHERE role = 'admin' LIMIT 1") === null) {
        echo json_encode(['status' => 'pending']);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    Config::set('sso_token_hash', hash('sha256', $token));
    Config::set('sso_token_expires', time() + CASHUPAY_SSO_TOKEN_TTL_SECONDS);
    echo json_encode(['status' => 'ready', 'token' => $token]);
    exit;
}

if ($method === 'GET') {
    header('Cache-Control: no-store');

    $token = (string)($_GET['token'] ?? '');
    $storedHash = (string)Config::get('sso_token_hash', '');
    $expires = (int)Config::get('sso_token_expires', 0);

    $matches = $token !== '' && $storedHash !== ''
        && hash_equals($storedHash, hash('sha256', $token));
    $expired = time() > $expires;

    // Consume on match (single use — consumed BEFORE the session starts, so
    // a redeemed URL can never be replayed) and on expiry. A MISMATCH does
    // not burn the outstanding token: the token is 256-bit random, so a
    // wrong guess proves nothing about the real one — while burning on
    // mismatch would let anyone who can reach this public endpoint deny the
    // operator SSO by hammering it with garbage.
    if ($matches || ($expired && $storedHash !== '')) {
        Config::set('sso_token_hash', '');
        Config::set('sso_token_expires', 0);
    }

    if (!$matches || $expired) {
        cashupay_status(403);
        header('Content-Type: text/plain');
        echo 'This sign-in link has expired. Go back and open BareBits again.';
        exit;
    }

    $admin = Database::fetchOne(
        "SELECT id, role FROM users WHERE role = 'admin' ORDER BY rowid ASC LIMIT 1"
    );
    if ($admin === null) {
        cashupay_status(403);
        header('Content-Type: text/plain');
        echo 'No admin account exists yet.';
        exit;
    }

    Auth::initSession();
    $_SESSION['user_id'] = $admin['id'];
    $_SESSION['user_role'] = $admin['role'];
    $_SESSION['login_time'] = time();
    session_regenerate_id(true);

    header('Location: ' . Urls::admin());
    exit;
}

cashupay_status(405);
header('Allow: GET, POST');
header('Content-Type: application/json');
echo json_encode(['error' => 'GET or POST required']);
