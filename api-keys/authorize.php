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
$redirect = $_GET['redirect'] ?? null;
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

    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        $clientIp = Security::getClientIp();

        if (Security::isLockedOut($clientIp)) {
            $error = 'Too many failed attempts. Please try again later.';
        } elseif (Auth::login($password)) {
            Security::clearLoginAttempts($clientIp);
            // Redirect to same page to show approval form
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            Security::recordFailedLogin($clientIp);
            $error = 'Invalid password';
        }
    } elseif ($action === 'approve' && Auth::isLoggedIn()) {
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
            // Redirect with error
            $separator = str_contains($redirect, '?') ? '&' : '?';
            header('Location: ' . $redirect . $separator . 'error=access_denied');
            exit;
        } else {
            header('Location: ' . Urls::admin());
            exit;
        }
    }
}

// Get stores for selection (only fully configured stores)
$stores = [];
if (Auth::isLoggedIn()) {
    $stores = Database::fetchAll(
        "SELECT id, name FROM stores
         WHERE mint_url IS NOT NULL AND mint_url != ''
           AND seed_phrase IS NOT NULL AND seed_phrase != ''
         ORDER BY name"
    );
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
        </div>
        <form id="redirect-form" method="POST" action="<?= htmlspecialchars($url) ?>">
            <input type="hidden" name="apiKey" value="<?= htmlspecialchars($data['apiKey']) ?>">
            <input type="hidden" name="userId" value="<?= htmlspecialchars($data['userId']) ?>">
            <input type="hidden" name="storeId" value="<?= htmlspecialchars($data['storeId']) ?>">
            <?php foreach ($data['permissions'] as $perm): ?>
            <input type="hidden" name="permissions[]" value="<?= htmlspecialchars($perm) ?>">
            <?php endforeach; ?>
        </form>
        <script>
            document.getElementById('redirect-form').submit();
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
    <title>Authorize <?= htmlspecialchars($applicationName) ?> - CashuPayServer</title>
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
                wants to connect to your CashuPayServer
            </p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label for="password">Admin Password</label>
                    <input type="password" id="password" name="password" required autofocus>
                </div>

                <button type="submit" class="btn btn-primary">Sign In</button>
            </form>

            <?php if ($redirect): ?>
                <form method="POST" style="margin-top: 0.5rem;">
                    <input type="hidden" name="action" value="deny">
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
                is requesting access to your CashuPayServer
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

                    <div class="form-group">
                        <label for="store_id">Select Store</label>
                        <select id="store_id" name="store_id" required>
                            <option value="">-- Select a store --</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?= htmlspecialchars($store['id']) ?>">
                                    <?= htmlspecialchars($store['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

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
