<?php
/**
 * CashuPayServer - API Key Authorization Flow
 *
 * BTCPay-compatible interactive API key authorization endpoint.
 * Used by WooCommerce and other e-commerce plugins for store-initiated pairing.
 *
 * URL: /api-keys/authorize
 *
 * Query Parameters:
 * - permissions[]     - Array of permission strings to request
 * - applicationName   - Name of the requesting application
 * - redirect          - URL to POST the API key to after approval
 * - applicationIdentifier - Optional app ID for reusing existing keys
 * - selectiveStores   - If true, user can choose which stores (default: false)
 * - strict            - If false, user can modify permissions (default: true)
 */

require_once __DIR__ . '/../includes/http_status.php';

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/urls.php';

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    header('Location: ' . Urls::setup());
    exit;
}

// Parse request parameters
// Note: PHP's $_GET only keeps the last value for duplicate keys (e.g. permissions=a&permissions=b).
// BTCPay clients send permissions without [] brackets, so we parse the raw query string.
$permissions = [];
$queryString = $_SERVER['QUERY_STRING'] ?? '';
parse_str(str_replace('permissions=', 'permissions[]=', $queryString), $parsedQuery);
if (!empty($parsedQuery['permissions'])) {
    $permissions = (array)$parsedQuery['permissions'];
} elseif (!empty($_GET['permissions'])) {
    $permissions = (array)$_GET['permissions'];
}
$applicationName = $_GET['applicationName'] ?? 'Unknown Application';
$rawRedirect = $_GET['redirect'] ?? null;
// Only accept http(s) URLs — $redirect is later used as a Location header,
// as an auto-submit <form action="...">, and is shown to the user as the
// final destination. javascript: / data: / non-URL values would XSS or
// phish the admin who just signed in to approve the pairing request.
$redirect = ($rawRedirect !== null && $rawRedirect !== '')
    ? Security::sanitizeUrl((string)$rawRedirect)
    : null;
$applicationIdentifier = $_GET['applicationIdentifier'] ?? null;
$selectiveStores = isset($_GET['selectiveStores']) && $_GET['selectiveStores'] === 'true';
$strict = !isset($_GET['strict']) || $_GET['strict'] !== 'false';

// Initialize session
Auth::initSession();

// Handle form submissions
$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF: every POST on this endpoint is state-changing (login mints a
    // session; approve mints an API key with caller-requested permissions;
    // deny redirects the user). Forms below carry the per-session token.
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!Auth::validateCsrfToken($submittedToken)) {
        cashupay_status(403);
        // Render via the same template as other failures so the user gets
        // a recoverable page (reload mints a new token).
        $error = 'Session expired or invalid request. Please try again.';
        $action = '';
    }

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIp = Security::getClientIp();

        // The attempt is reserved before the password check (atomic
        // check+increment), so a burst of parallel guesses cannot all slip
        // under the lockout threshold.
        $loginResult = Auth::attemptLogin($username, $password, $clientIp);
        if ($loginResult === 'locked') {
            cashupay_status(429);
            $error = 'Too many failed attempts. Please try again later.';
        } elseif ($loginResult === 'ok') {
            // Redirect back to the same endpoint to render the approval form.
            // Use only the path portion of REQUEST_URI (parse_url strips any
            // attacker-supplied //host or fragment) and rebuild the query
            // string from validated params — this keeps protocol-relative
            // bounces like //evil.com out of the Location header.
            $safePath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            if (!is_string($safePath) || $safePath === '' || $safePath[0] !== '/') {
                $safePath = '/api-keys/authorize';
            }
            $queryParts = ['applicationName=' . rawurlencode($applicationName)];
            if ($redirect !== null) {
                $queryParts[] = 'redirect=' . rawurlencode($redirect);
            }
            if ($applicationIdentifier !== null) {
                $queryParts[] = 'applicationIdentifier=' . rawurlencode((string)$applicationIdentifier);
            }
            $queryParts[] = 'selectiveStores=' . ($selectiveStores ? 'true' : 'false');
            $queryParts[] = 'strict=' . ($strict ? 'true' : 'false');
            foreach ($permissions as $perm) {
                $queryParts[] = 'permissions=' . rawurlencode((string)$perm);
            }
            header('Location: ' . $safePath . '?' . implode('&', $queryParts));
            exit;
        } else {
            // Already counted by attemptLogin's reservation.
            $error = 'Invalid username or password';
        }
    } elseif ($action === 'approve' && Auth::isAdmin()) {
        // Get selected store and permissions
        $selectedStoreId = $_POST['store_id'] ?? null;
        $approvedPermissions = $_POST['approved_permissions'] ?? $permissions;

        if (empty($selectedStoreId)) {
            $error = 'Please select a store';
        } else {
            $redirectHost = $redirect ? parse_url($redirect, PHP_URL_HOST) : null;

            // If same app is re-pairing, delete old key first (we can't return existing key since only hash is stored)
            if ($applicationIdentifier && $redirectHost) {
                $existingKey = Auth::findApiKeyByAppIdentifier(
                    $selectedStoreId,
                    $applicationIdentifier,
                    $redirectHost,
                    $approvedPermissions
                );
                if ($existingKey) {
                    Auth::deleteApiKey($existingKey['id']);
                }
            }

            // Create new API key using Auth class
            $keyResult = Auth::createApiKey(
                $selectedStoreId,
                $applicationName,
                $approvedPermissions,
                $applicationIdentifier,
                $redirectHost
            );

            $apiKey = $keyResult['key'];
            $keyPermissions = $keyResult['permissions'];

            // If redirect specified, POST the result
            if ($redirect) {
                $responseData = [
                    'apiKey' => $apiKey,
                    'userId' => 'admin',  // Static - CashuPayServer is single-user
                    'storeId' => $selectedStoreId,
                    'permissions' => array_map(function($perm) use ($selectedStoreId) {
                        return $perm . ':' . $selectedStoreId;
                    }, $keyPermissions),
                ];

                // Render auto-submit form for POST redirect
                renderPostRedirect($redirect, $responseData, $applicationName);
                exit;
            } else {
                // No redirect - show the key to user
                $success = true;
                $generatedKey = $apiKey;
            }
        }
    } elseif ($action === 'deny') {
        if ($redirect) {
            // Denials go back to the requester with ?error=access_denied —
            // through the same wait-out-maintenance page as approvals
            // (renderGetRedirect), not a blind Location header: the target
            // site can be behind WordPress's maintenance 503 right now, and
            // while a lost denial destroys no data, dumping the merchant on
            // the maintenance screen still reads as "the pairing broke".
            $separator = str_contains($redirect, '?') ? '&' : '?';
            renderGetRedirect($redirect . $separator . 'error=access_denied', $applicationName);
            exit;
        } else {
            header('Location: ' . Urls::admin());
            exit;
        }
    }
}

// Get stores for selection. Every store is listed: a store can be
// Lightning-only or on-chain-only (the wizard's "run without mints" answer
// leaves mint_url/seed_phrase NULL) and still take payments, so filtering on
// the mint columns here wrongly told merchants with such stores "No stores
// found" and blocked pairing entirely. A store with no payment rail at all
// is still selectable — pair first, add a rail after — but gets flagged in
// the chooser so the admin knows checkouts will fail until one is added.
$stores = [];
if (Auth::isLoggedIn()) {
    $stores = Database::fetchAll("SELECT id, name FROM stores ORDER BY name");
    foreach ($stores as &$storeRow) {
        $storeRow['hasPaymentRail'] = Config::storeHasPaymentRail($storeRow['id']);
    }
    unset($storeRow);
}

// Permission descriptions for UI
$permissionDescriptions = [
    'btcpay.store.canviewinvoices' => 'View invoices',
    'btcpay.store.cancreateinvoice' => 'Create invoices',
    'btcpay.store.canmodifyinvoices' => 'Modify invoices',
    'btcpay.store.webhooks.canmodifywebhooks' => 'Manage webhooks',
    'btcpay.store.canviewstoresettings' => 'View store settings',
    'btcpay.store.canmodifystoresettings' => 'Modify store settings',
    'btcpay.store.cancreatenonapprovedpullpayments' => 'Create pull payments',
];

/**
 * Render a GET redirect that waits out a 503 on the target before
 * navigating — the deny-path sibling of renderPostRedirect's guarded
 * auto-submit (see the comment in there for the why). The visible link is
 * the no-JS fallback and the manual override.
 */
function renderGetRedirect(string $url, string $appName): void {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Returning to <?= htmlspecialchars($appName) ?>...</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background: #0f0f23;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
            }
            .container {
                text-align: center;
                padding: 2rem;
            }
            .spinner {
                width: 40px;
                height: 40px;
                border: 3px solid rgba(255,255,255,0.2);
                border-top-color: #f7931a;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 1rem;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="spinner"></div>
            <p>Returning to <?= htmlspecialchars($appName) ?>...</p>
            <div id="redirect-waiting" style="display: none; background: rgba(246, 173, 85, 0.15); border: 1px solid rgba(246, 173, 85, 0.4); border-radius: 8px; padding: 0.75rem 1rem; margin-top: 1rem; font-size: 0.9rem; color: #f6ad55;">
                <?= htmlspecialchars($appName) ?>'s site is briefly unavailable (maintenance mode) &mdash;
                continuing automatically as soon as it's back, usually under a minute.
                The link below goes there right away.
            </div>
            <p style="margin-top: 1rem;">
                <a id="redirect-link" href="<?= htmlspecialchars($url) ?>" style="color: #f7931a;">
                    Continue to <?= htmlspecialchars($appName) ?>
                </a>
            </p>
        </div>
        <script>
        (function () {
            const link = document.getElementById('redirect-link');
            const note = document.getElementById('redirect-waiting');
            const probeUrl = link.href.split('?')[0];
            let manual = false;
            link.addEventListener('click', function () { manual = true; });
            const go = function () { if (!manual) { window.location.href = link.href; } };
            const probe = function () {
                if (manual) { return; }
                fetch(probeUrl, { cache: 'no-store' })
                    .then(function (res) {
                        if (res.status === 503) {
                            note.style.display = 'block';
                            setTimeout(probe, 5000);
                        } else {
                            go();
                        }
                    })
                    .catch(go);
            };
            probe();
        })();
        </script>
    </body>
    </html>
    <?php
}

/**
 * Render a POST redirect form
 */
function renderPostRedirect(string $url, array $data, string $appName): void {
    $jsonData = htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Redirecting to <?= htmlspecialchars($appName) ?>...</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background: #0f0f23;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
            }
            .container {
                text-align: center;
                padding: 2rem;
            }
            .spinner {
                width: 40px;
                height: 40px;
                border: 3px solid rgba(255,255,255,0.2);
                border-top-color: #f7931a;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 1rem;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="spinner"></div>
            <p>Redirecting to <?= htmlspecialchars($appName) ?>...</p>
            <div id="redirect-waiting" style="display: none; background: rgba(246, 173, 85, 0.15); border: 1px solid rgba(246, 173, 85, 0.4); border-radius: 8px; padding: 0.75rem 1rem; margin-top: 1rem; font-size: 0.9rem; color: #f6ad55;">
                <?= htmlspecialchars($appName) ?>'s site is briefly unavailable (maintenance mode) &mdash;
                continuing automatically as soon as it's back, usually under a minute.
                The button below goes there right away.
            </div>
            <form id="redirect-form" method="POST" action="<?= htmlspecialchars($url) ?>">
                <input type="hidden" name="apiKey" value="<?= htmlspecialchars($data['apiKey']) ?>">
                <input type="hidden" name="userId" value="<?= htmlspecialchars($data['userId']) ?>">
                <input type="hidden" name="storeId" value="<?= htmlspecialchars($data['storeId']) ?>">
                <?php foreach ($data['permissions'] as $perm): ?>
                <input type="hidden" name="permissions[]" value="<?= htmlspecialchars($perm) ?>">
                <?php endforeach; ?>
                <!-- Also the no-JS fallback: without scripting nothing auto-submits,
                     and this used to be a dead end with an invisible form. -->
                <button type="submit" style="margin-top: 1rem; background: none; border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; color: #fff; padding: 0.5rem 1rem; cursor: pointer;">
                    Continue to <?= htmlspecialchars($appName) ?>
                </button>
            </form>
        </div>
        <script>
        // The redirect target (for the WordPress plugin: admin-post.php) can
        // be behind WordPress's maintenance screen at this very moment —
        // auto-updates 503 the whole WP site for up to a minute. Submitting
        // into that swallows the freshly minted key and strands the merchant
        // on the maintenance screen. Same cure as the setup wizard's
        // return-to-WordPress handoff: probe the target's base URL first and
        // only submit once it answers. 503 is the ONLY status that waits;
        // anything else — including the failure a CROSS-ORIGIN probe throws
        // when the shop is on another origin (no CORS headers on its answer)
        // — falls through to the immediate submit this page always did, so
        // the guard may delay the merchant, never trap them. Clicking the
        // button is the manual override.
        (function () {
            const form = document.getElementById('redirect-form');
            const note = document.getElementById('redirect-waiting');
            // The action minus its query: for the WordPress plugin that is a
            // bare admin-post.php, a cheap no-op that still answers 503 in
            // maintenance mode. Probing the full URL would run the callback.
            const probeUrl = form.action.split('?')[0];
            let manual = false;
            form.addEventListener('submit', function () { manual = true; });
            const go = function () { if (!manual) { form.submit(); } };
            const probe = function () {
                if (manual) { return; }
                fetch(probeUrl, { cache: 'no-store' })
                    .then(function (res) {
                        if (res.status === 503) {
                            note.style.display = 'block';
                            setTimeout(probe, 5000);
                        } else {
                            go();
                        }
                    })
                    .catch(go);
            };
            probe();
        })();
        </script>
    </body>
    </html>
    <?php
}

$baseUrl = Config::getBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize <?= htmlspecialchars($applicationName) ?> - BareBits</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f0f23;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .container {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            width: 100%;
            max-width: 450px;
        }

        .logo {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        h1 {
            text-align: center;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            text-align: center;
            color: #a0aec0;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .app-name {
            color: #f7931a;
            font-weight: 600;
        }

        .error {
            background: rgba(229, 62, 62, 0.2);
            border: 1px solid #e53e3e;
            color: #feb2b2;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .success {
            background: rgba(72, 187, 120, 0.2);
            border: 1px solid #48bb78;
            color: #9ae6b4;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        input[type="password"],
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #fff;
            font-size: 1rem;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #f7931a;
        }

        .permissions-list {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .permissions-title {
            font-size: 0.85rem;
            color: #a0aec0;
            margin-bottom: 0.75rem;
        }

        .permission-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.9rem;
        }

        .permission-item:last-child {
            border-bottom: none;
        }

        .permission-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #f7931a;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 0.875rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 0.75rem;
        }

        .btn-primary {
            background: #f7931a;
            color: #fff;
        }

        .btn-primary:hover {
            background: #e8820a;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .btn-danger {
            background: rgba(229, 62, 62, 0.2);
            color: #feb2b2;
        }

        .btn-danger:hover {
            background: rgba(229, 62, 62, 0.3);
        }

        .api-key-display {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 1rem;
            word-break: break-all;
            font-family: monospace;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        .warning {
            background: rgba(237, 137, 54, 0.2);
            border: 1px solid #ed8936;
            color: #fbd38d;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }

        .redirect-info {
            text-align: center;
            color: #a0aec0;
            font-size: 0.8rem;
            margin-top: 1rem;
        }

        /* Dark dropdown options so the open <select> menu stays legible
           against the dark theme (white-on-white is the OS default). */
        select option {
            background-color: #1a1a2e;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">&#9889;</div>

        <?php if (!Auth::isLoggedIn()): ?>
            <!-- Login Form -->
            <h1>Sign In</h1>
            <p class="subtitle">
                <span class="app-name"><?= htmlspecialchars($applicationName) ?></span>
                wants to connect to your BareBits
            </p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="admin" required autofocus
                           autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary">Sign In</button>
            </form>

            <?php if ($redirect): ?>
                <form method="POST" style="margin-top: 0.5rem;">
                    <input type="hidden" name="action" value="deny">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-secondary">Cancel</button>
                </form>
            <?php endif; ?>

        <?php elseif ($success && isset($generatedKey)): ?>
            <!-- Success - Show API Key -->
            <h1>Authorization Successful</h1>
            <p class="subtitle">API key created for <span class="app-name"><?= htmlspecialchars($applicationName) ?></span></p>

            <div class="warning">
                Copy this API key now - it won't be shown again!
            </div>

            <div class="api-key-display"><?= htmlspecialchars($generatedKey) ?></div>

            <a href="<?= Urls::admin() ?>" class="btn btn-primary" style="text-decoration: none; text-align: center;">
                Go to Dashboard
            </a>

        <?php else: ?>
            <!-- Approval Form -->
            <h1>Authorize Application</h1>
            <p class="subtitle">
                <span class="app-name"><?= htmlspecialchars($applicationName) ?></span>
                is requesting access to your BareBits
            </p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (empty($stores)): ?>
                <div class="warning">
                    No stores found. Please create a store first in the admin dashboard.
                </div>
                <a href="<?= Urls::admin() ?>" class="btn btn-primary" style="text-decoration: none; text-align: center;">
                    Go to Dashboard
                </a>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">

                    <div class="form-group">
                        <label for="store_id">Select Store</label>
                        <select id="store_id" name="store_id" required>
                            <option value="">-- Select a store --</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?= htmlspecialchars($store['id']) ?>"
                                        data-has-rail="<?= $store['hasPaymentRail'] ? '1' : '0' ?>">
                                    <?= htmlspecialchars($store['name']) ?><?= $store['hasPaymentRail'] ? '' : ' (no payment methods yet)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="no-rail-warning" class="warning" style="display: none; margin-top: 0.75rem;">
                            This store has no payment methods configured yet, so
                            checkouts will fail until one is added. You can pair
                            now and add a Cashu mint, Lightning destination, or
                            on-chain wallet in the
                            <a href="<?= Urls::admin() ?>">dashboard</a> afterwards.
                        </div>
                    </div>
                    <script>
                    (function () {
                        const select = document.getElementById('store_id');
                        const warning = document.getElementById('no-rail-warning');
                        function syncWarning() {
                            const opt = select.options[select.selectedIndex];
                            const railless = opt && opt.getAttribute('data-has-rail') === '0';
                            warning.style.display = railless ? 'block' : 'none';
                        }
                        select.addEventListener('change', syncWarning);
                        syncWarning();
                    })();
                    </script>

                    <?php if (!empty($permissions)): ?>
                        <div class="permissions-list">
                            <div class="permissions-title">Requested Permissions</div>
                            <?php foreach ($permissions as $perm): ?>
                                <div class="permission-item">
                                    <?php if (!$strict): ?>
                                        <input type="checkbox" name="approved_permissions[]"
                                               value="<?= htmlspecialchars($perm) ?>" checked>
                                    <?php else: ?>
                                        <input type="hidden" name="approved_permissions[]"
                                               value="<?= htmlspecialchars($perm) ?>">
                                        <span style="color: #48bb78;">&#10003;</span>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($permissionDescriptions[$perm] ?? $perm) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="permissions-list">
                            <div class="permissions-title">Permissions</div>
                            <div class="permission-item">
                                <span style="color: #a0aec0;">No specific permissions requested</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">Authorize</button>
                </form>

                <form method="POST" style="margin-top: 0;">
                    <input type="hidden" name="action" value="deny">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
                    <button type="submit" class="btn btn-danger">Deny</button>
                </form>

                <?php if ($redirect): ?>
                    <div class="redirect-info">
                        After authorization, you'll be redirected to:<br>
                        <?= htmlspecialchars(parse_url($redirect, PHP_URL_HOST)) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
