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

require_once __DIR__ . '/../includes/entrypoint_guard.php';
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

// Security headers (self-contained page, strict CSP).
Security::setSecurityHeaders();

// Permission descriptions for UI. Also the allow-list: a pairing link may ask for these
// and nothing else (DS41-09, OPUS-009). `*` and btcpay.server.* would let one click on a
// crafted link hand out a key that can do anything, shown to the operator as a bare
// star or an internal string they cannot be expected to judge.
$permissionDescriptions = [
    'btcpay.store.canviewinvoices' => 'View invoices',
    'btcpay.store.cancreateinvoice' => 'Create invoices',
    'btcpay.store.canmodifyinvoices' => 'Modify invoices',
    'btcpay.store.canmanageinvoicestatus' => 'Change an invoice\'s status (for example, cancel it)',
    'btcpay.store.webhooks.canmodifywebhooks' => 'Manage webhooks (how your shop hears about payments)',
    'btcpay.store.canviewstoresettings' => 'View store settings',
    'btcpay.store.canmodifystoresettings' => 'Full control of the store, including deleting it',
    'btcpay.store.cancreatenonapprovedpullpayments' => 'Create pull payments (refunds; not supported here, has no effect)',
];
// Other standard BTCPay store permissions. Shop plugins sometimes ask for them as
// optional extras; this server has no endpoint they would open.
foreach ([
    'btcpay.store.canviewpaymentrequests', 'btcpay.store.canmodifypaymentrequests',
    'btcpay.store.canviewpullpayments', 'btcpay.store.canmanagepullpayments',
    'btcpay.store.cancreatepullpayments', 'btcpay.store.canviewpayouts',
    'btcpay.store.canmanagepayouts', 'btcpay.store.canviewreports',
    'btcpay.store.canviewlightninginvoice', 'btcpay.store.cancreatelightninginvoice',
    'btcpay.store.canuselightningnode',
] as $unusedPermission) {
    $permissionDescriptions[$unusedPermission] = 'Not used by CashuPayServer (' . substr($unusedPermission, 13) . ', has no effect)';
}

// Parse request parameters
// Note: PHP's $_GET only keeps the last value for duplicate keys (e.g. permissions=a&permissions=b).
// BTCPay clients send permissions without [] brackets, so we parse the raw query string.
// Every value is checked for shape: `?redirect[]=x` used to reach string functions and
// end in a TypeError page instead of an explanation (DS41-23).
$requestProblems = [];
$queryString = $_SERVER['QUERY_STRING'] ?? '';
parse_str(preg_replace('/(^|&)permissions(%5B%5D|\[\])?=/i', '$1permissions[]=', (string)$queryString), $parsedQuery);
$rawPermissions = $parsedQuery['permissions'] ?? [];
if (!is_array($rawPermissions)) {
    $rawPermissions = [$rawPermissions];
}
$permissions = [];
foreach ($rawPermissions as $perm) {
    if (!is_string($perm) || $perm === '') {
        $requestProblems[] = 'a malformed permission';
        continue;
    }
    // A requested ":storeId" scope is dropped: the operator picks the store below, and
    // the key only ever works for that one.
    $base = explode(':', $perm, 2)[0];
    if (!isset($permissionDescriptions[$base])) {
        $requestProblems[] = 'a permission CashuPayServer never grants ("' . $perm . '")';
        continue;
    }
    $permissions[] = $base;
}
$permissions = array_values(array_unique($permissions));

$queryParam = static function (string $name) use (&$requestProblems): ?string {
    $value = $_GET[$name] ?? null;
    if ($value === null || is_string($value)) {
        return $value;
    }
    $requestProblems[] = 'a malformed "' . $name . '" value';
    return null;
};
$applicationName = trim((string)($queryParam('applicationName') ?? ''));
if ($applicationName === '') {
    $applicationName = 'Unknown Application';
}
$applicationName = mb_substr((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $applicationName), 0, 100);
if (Auth::isReservedKeyLabel($applicationName)) {
    // That label marks the dashboard's own key; a look-alike would be undeletable and
    // would pass for the dashboard in the key list (SPB-SEC-009).
    $requestProblems[] = 'an application name reserved for CashuPayServer itself';
}
$redirect = $queryParam('redirect');
if ($redirect === '') {
    $redirect = null;
}
$applicationIdentifier = $queryParam('applicationIdentifier');
$selectiveStores = $queryParam('selectiveStores') === 'true';
$strict = $queryParam('strict') !== 'false';
$requestProblems = array_values(array_unique($requestProblems));

// Initialize session
Auth::initSession();

// Handle form submissions
$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfValid = is_string($_POST['csrf_token'] ?? null) && Auth::validateCsrfToken($_POST['csrf_token']);

    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        if (!$csrfValid) {
            // A cross-site form could otherwise burn the operator's own lockout budget.
            $error = 'This page was open too long. Please try again.';
        } elseif (!is_string($password)) {
            $error = 'Invalid password';
        } else {
            $result = Auth::attemptLogin($password);
            if ($result === 'ok') {
                // Redirect to same page to show approval form
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            $error = $result === 'locked'
                ? 'Too many failed attempts. Please try again later.'
                : 'Invalid password';
        }
    } elseif ($action === 'approve' && Auth::isLoggedIn() && empty($requestProblems)) {
        // CSRF protection: this action mints an API key, so it must not be forgeable.
        // See FABLE-SECURITY-AUDIT (CRIT-5).
        if (!$csrfValid) {
            $error = 'Invalid security token. Please reload and try again.';
            $selectedStoreId = null;
        } else {
        // Get selected store and permissions
        $selectedStoreId = $_POST['store_id'] ?? null;
        if (!is_string($selectedStoreId) || !Config::isStoreConfigured($selectedStoreId)) {
            $selectedStoreId = null;
        }

        // Unchecking every box omits the field entirely, and falling back to the
        // requested list then granted exactly what the operator just declined. An
        // explicitly empty approval is a refusal, and each value must be one the
        // request actually asked for.
        $approvedRaw = $_POST['approved_permissions'] ?? null;
        if ($approvedRaw === null) {
            $approvedPermissions = [];
        } elseif (!is_array($approvedRaw)) {
            $approvedPermissions = [];
        } else {
            $approvedPermissions = array_values(array_intersect(
                array_filter($approvedRaw, 'is_string'),
                $permissions
            ));
        }

        // A request that asked for nothing legitimately pairs with no permissions; a
        // request that asked for some and got none is a refusal, not a full grant.
        if (!empty($permissions) && empty($approvedPermissions)) {
            $error = 'Select at least one permission to grant, or cancel the pairing.';
            $selectedStoreId = null;
        }

        // The API key is POSTed to `redirect` on success — only allow safe http(s)
        // destinations so a malicious pairing link cannot exfiltrate the key to any host
        // via a non-http scheme or an internal target.
        if ($redirect !== null && !Security::isSafePublicHttpUrl($redirect, true)) {
            $error = 'Invalid redirect URL';
            $selectedStoreId = null;
        }

        if (empty($selectedStoreId)) {
            $error = $error ?: 'Please select a store';
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
                    try {
                        Auth::deleteApiKey($existingKey['id']);
                    } catch (Throwable $e) {
                        error_log('CashuPayServer: re-pairing kept key ' . $existingKey['id'] . ': ' . $e->getMessage());
                    }
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
        } // end CSRF-valid branch
    } elseif ($action === 'deny') {
        // Token-checked like approve, and the destination must be a public http(s) URL
        // with no localhost exemption: otherwise any site could bounce a visitor through
        // this page to anywhere, including the merchant's own loopback (SPB-SEC-013).
        if (!$csrfValid) {
            $error = 'This page was open too long. Please try again.';
        } elseif ($redirect !== null && Security::isSafePublicHttpUrl($redirect)) {
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
        <title>Redirecting to <?php echo htmlspecialchars($appName); ?>...</title>
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
            <p>Redirecting to <?php echo htmlspecialchars($appName); ?>...</p>
        </div>
        <form id="redirect-form" method="POST" action="<?php echo htmlspecialchars($url); ?>">
            <input type="hidden" name="apiKey" value="<?php echo htmlspecialchars($data['apiKey']); ?>">
            <input type="hidden" name="userId" value="<?php echo htmlspecialchars($data['userId']); ?>">
            <input type="hidden" name="storeId" value="<?php echo htmlspecialchars($data['storeId']); ?>">
            <?php foreach ($data['permissions'] as $perm): ?>
            <input type="hidden" name="permissions[]" value="<?php echo htmlspecialchars($perm); ?>">
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
$csrfToken = Auth::generateCsrfToken();
$redirectHostLabel = $redirect !== null ? (string)parse_url($redirect, PHP_URL_HOST) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize <?php echo htmlspecialchars($applicationName); ?> - CashuPayServer</title>
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

        <?php if (!empty($requestProblems)): ?>
            <!-- Refused request -->
            <h1>This connection request can't be approved</h1>
            <p class="subtitle">
                <span class="app-name"><?php echo htmlspecialchars($applicationName); ?></span>
                sent a request CashuPayServer will not accept.
            </p>
            <div class="error">
                The link asks for:
                <ul style="margin: 0.5rem 0 0 1.25rem;">
                    <?php foreach ($requestProblems as $problem): ?>
                        <li><?php echo htmlspecialchars($problem); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p class="subtitle">
                Nothing was changed. If you were trying to connect your shop, contact the
                developer of the app or plugin; if you did not start this, you can simply
                close this page.
            </p>
            <a href="<?php echo Urls::admin(); ?>" class="btn btn-primary" style="text-decoration: none; text-align: center;">
                Go to Dashboard
            </a>

        <?php elseif (!Auth::isLoggedIn()): ?>
            <!-- Login Form -->
            <h1>Sign In</h1>
            <p class="subtitle">
                <span class="app-name"><?php echo htmlspecialchars($applicationName); ?></span>
                wants to connect to your CashuPayServer
            </p>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="form-group">
                    <label for="password">Admin Password</label>
                    <input type="password" id="password" name="password" required autofocus>
                </div>

                <button type="submit" class="btn btn-primary">Sign In</button>
            </form>

            <?php if ($redirect): ?>
                <form method="POST" style="margin-top: 0.5rem;">
                    <input type="hidden" name="action" value="deny">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <button type="submit" class="btn btn-secondary">Cancel</button>
                </form>
            <?php endif; ?>

        <?php elseif ($success && isset($generatedKey)): ?>
            <!-- Success - Show API Key -->
            <h1>Authorization Successful</h1>
            <p class="subtitle">API key created for <span class="app-name"><?php echo htmlspecialchars($applicationName); ?></span></p>

            <div class="warning">
                Copy this API key now - it won't be shown again!
            </div>

            <div class="api-key-display"><?php echo htmlspecialchars($generatedKey); ?></div>

            <a href="<?php echo Urls::admin(); ?>" class="btn btn-primary" style="text-decoration: none; text-align: center;">
                Go to Dashboard
            </a>

        <?php else: ?>
            <!-- Approval Form -->
            <h1>Authorize Application</h1>
            <p class="subtitle">
                <span class="app-name"><?php echo htmlspecialchars($applicationName); ?></span>
                is requesting access to your CashuPayServer
            </p>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (empty($stores)): ?>
                <div class="warning">
                    No stores found. Please create a store first in the admin dashboard.
                </div>
                <a href="<?php echo Urls::admin(); ?>" class="btn btn-primary" style="text-decoration: none; text-align: center;">
                    Go to Dashboard
                </a>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <div class="form-group">
                        <label for="store_id">Select Store</label>
                        <select id="store_id" name="store_id" required>
                            <option value="">-- Select a store --</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo htmlspecialchars($store['id']); ?>">
                                    <?php echo htmlspecialchars($store['name']); ?>
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
                                               value="<?php echo htmlspecialchars($perm); ?>" checked>
                                    <?php else: ?>
                                        <input type="hidden" name="approved_permissions[]"
                                               value="<?php echo htmlspecialchars($perm); ?>">
                                        <span style="color: #48bb78;">&#10003;</span>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($permissionDescriptions[$perm] ?? $perm); ?></span>
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

                    <?php if ($redirectHostLabel !== ''): ?>
                        <div class="warning">
                            The new API key will be sent to
                            <strong><?php echo htmlspecialchars($redirectHostLabel); ?></strong>.
                            Only continue if that is your shop.
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">Authorize</button>
                </form>

                <form method="POST" style="margin-top: 0;">
                    <input type="hidden" name="action" value="deny">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <button type="submit" class="btn btn-danger">Deny</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
