<?php
/**
 * CashuPayServer — One-time provisioning handshake for orchestrated installs.
 *
 * An external installer (the GPL WordPress companion plugin's "install
 * BareBits alongside WordPress" flow is the canonical caller) deploys this
 * application, generates a random token, and writes ONLY the token's SHA-256
 * hash into user_config.php as CASHUPAY_PROVISION_TOKEN_HASH. After the
 * operator finishes the setup wizard, the installer POSTs the plaintext token
 * here and receives, exactly once, the credentials it needs to wire up an
 * e-commerce integration:
 *
 *   - storeId  — the store the wizard created
 *   - apiKey   — the store's internal (full-permission) API key, the same one
 *                the admin UI hands to e-commerce plugins
 *   - cronKey  — for driving cron.php from the orchestrator's scheduler
 *
 * Design constraints:
 *   - POST only; the token travels in the body or an X-PROVISION-TOKEN
 *     header, never a logged query string.
 *   - The endpoint is a 404 unless the deployment opted in by defining the
 *     hash constant, so ordinary installs expose nothing.
 *   - Single use, with a delivery-failure grace: the first successful
 *     collection stamps provision_consumed_at in config. For a short window
 *     after that stamp (CASHUPAY_PROVISION_GRACE_SECONDS) the same token may
 *     re-collect the SAME credentials — the stamp records when the server
 *     SENT the response, not that the installer received it, and a response
 *     lost to a timeout must not orphan the install forever. After the
 *     window every attempt gets 410. Consumption is recorded in the
 *     database rather than by rewriting user_config.php — this endpoint
 *     never modifies deployment files.
 *   - Before the wizard completes it answers {"status":"pending"} so the
 *     installer can poll instead of guessing when the operator is done.
 */

require_once __DIR__ . '/includes/http_status.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!defined('CASHUPAY_PROVISION_TOKEN_HASH') || CASHUPAY_PROVISION_TOKEN_HASH === '') {
    cashupay_status(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cashupay_status(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST required']);
    exit;
}

$token = (string)($_SERVER['HTTP_X_PROVISION_TOKEN'] ?? ($_POST['token'] ?? ''));
$expected = strtolower((string)CASHUPAY_PROVISION_TOKEN_HASH);
if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $expected)
        || !hash_equals($expected, hash('sha256', $token))) {
    cashupay_status(403);
    echo json_encode(['error' => 'Invalid provisioning token']);
    exit;
}

// The wizard hasn't finished (or hasn't even created the database yet):
// tell the installer to come back rather than minting anything early.
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    echo json_encode(['status' => 'pending']);
    exit;
}

// Re-collection grace: the consumed stamp records when the server SENT the
// credentials, not that the installer received them. A response lost to a
// timeout would otherwise orphan the install permanently (the token is
// worthless, yet the credentials were never delivered), so the same token
// may repeat the collection briefly; the repeat hands out the SAME
// credentials — nothing is re-minted.
const CASHUPAY_PROVISION_GRACE_SECONDS = 600;

// The whole check-then-mint-then-stamp sequence must be one write
// transaction: two concurrent collections would otherwise both pass the
// consumed check and race the lazy cron-key seed, leaving one caller holding
// a cron key that was immediately overwritten.
Database::beginImmediate();
try {
    $consumedAt = (int)Config::get('provision_consumed_at', 0);
    if ($consumedAt > 0 && time() - $consumedAt > CASHUPAY_PROVISION_GRACE_SECONDS) {
        Database::rollback();
        cashupay_status(410);
        echo json_encode(['error' => 'Provisioning credentials were already collected']);
        exit;
    }

    // The wizard's first-run flow creates exactly one store; on the off chance
    // an operator added more before the installer collected, the original
    // (first created) store is the one the orchestrator provisioned for.
    $store = Database::fetchOne("SELECT id FROM stores ORDER BY rowid ASC LIMIT 1");
    if (!$store) {
        Database::rollback();
        echo json_encode(['status' => 'pending']);
        exit;
    }

    $apiKey = Auth::getOrCreateInternalApiKey($store['id']);
    if (!$apiKey) {
        Database::rollback();
        cashupay_status(500);
        echo json_encode(['error' => 'Could not resolve the store API key']);
        exit;
    }

    // Seed the cron key if the wizard never rendered the cron screen
    // (provisioned installs skip it via CASHUPAY_EXTERNAL_CRON) — same lazy
    // seeding the cron screen and admin Settings use.
    $cronKey = Config::get('cron_key');
    if (!$cronKey) {
        $cronKey = bin2hex(random_bytes(32));
        Config::set('cron_key', $cronKey);
    }

    // First collection stamps the clock; a grace re-collection keeps the
    // original stamp so the window measures from the FIRST delivery and can
    // never be extended by polling.
    if ($consumedAt === 0) {
        Config::set('provision_consumed_at', time());
    }

    Database::commit();
} catch (Throwable $e) {
    Database::rollback();
    throw $e;
}

echo json_encode([
    'status' => 'ready',
    'storeId' => $store['id'],
    'apiKey' => $apiKey,
    'cronKey' => $cronKey,
]);
