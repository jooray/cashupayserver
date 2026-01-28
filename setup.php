<?php
/**
 * CashuPayServer - Setup Wizard
 *
 * Multi-step setup wizard for initial configuration.
 *
 * Flow:
 * Step 1: Welcome/Requirements + Security Check (merged)
 * Step 2: Admin Password (skipped in WordPress mode)
 * Step 4: Create Store (name only)
 * Step 5: Connect Mint (URL → fetch keysets → select unit)
 * Step 6: Generate Seed for Store
 * Step 7: Complete
 *
 * Note: Step 3 was merged into Step 1. Internal step numbers are preserved
 * for backwards compatibility, but step 3 is no longer used.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/urls.php';

// Initialize session early - needed for storing temp data during setup
Auth::initSession();

// Get mode parameter
$mode = $_GET['mode'] ?? $_POST['mode'] ?? '';

// If already set up, redirect to admin (unless in add_store mode or finishing step 7)
$isStep7Post = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '7');
if (Database::isInitialized() && Config::isSetupComplete() && !$isStep7Post) {
    if ($mode !== 'add_store') {
        header('Location: ' . Urls::admin());
        exit;
    }
    // Require login for add_store mode
    if (!Auth::isLoggedIn()) {
        header('Location: ' . Urls::admin());
        exit;
    }
}

// Initialize database if needed
if (!Database::isInitialized()) {
    Database::initialize();
}

// Handle form submissions
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);

// For add_store mode, start at step 4 (store creation)
if ($mode === 'add_store' && !isset($_POST['step']) && !isset($_GET['step'])) {
    $step = 4;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX action for expiry testing
    if (isset($_POST['action']) && $_POST['action'] === 'test_mint_expiry') {
        header('Content-Type: application/json');
        require_once __DIR__ . '/includes/mint_helpers.php';
        $mintUrl = $_POST['mint_url'] ?? '';
        $unit = $_POST['unit'] ?? 'sat';

        if (empty($mintUrl)) {
            echo json_encode(['success' => false, 'error' => 'Mint URL required']);
        } else {
            echo json_encode(MintHelpers::testExpiry($mintUrl, $unit));
        }
        exit;
    }

    // Handle AJAX action for saving URL mode
    if (isset($_POST['action']) && $_POST['action'] === 'save_url_mode') {
        header('Content-Type: application/json');
        $mode = $_POST['mode'] ?? 'router';
        if (in_array($mode, ['direct', 'router'])) {
            Config::set('url_mode', $mode);
            echo json_encode(['success' => true, 'mode' => $mode]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid mode']);
        }
        exit;
    }

    try {
        switch ($step) {
            case 1: // Welcome + Security (merged step)
                $securityPassed = $_POST['security_acknowledged'] ?? false;
                if (!$securityPassed) {
                    throw new Exception('Please verify that your database is protected');
                }
                // Go to password step (standalone) or create store step (WordPress)
                $step = Urls::isWordPress() ? 4 : 2;
                break;

            case 2: // Password setup
                $password = $_POST['password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';

                if (strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters');
                }
                if ($password !== $confirm) {
                    throw new Exception('Passwords do not match');
                }

                Auth::setAdminPassword($password);
                // Go directly to step 4 (create store) - step 3 is merged into step 1
                $step = 4;
                break;

            case 4: // Create store
                $storeName = trim($_POST['store_name'] ?? 'My Store');

                // Check for duplicate store name
                $existingStore = Database::fetchOne(
                    "SELECT id FROM stores WHERE LOWER(name) = LOWER(?)",
                    [$storeName]
                );
                if ($existingStore) {
                    throw new Exception('A store with this name already exists.');
                }

                // Create store with just a name (mint and seed will be added in later steps)
                $storeId = Database::generateId('store');
                Database::insert('stores', [
                    'id' => $storeId,
                    'name' => $storeName,
                    'created_at' => Database::timestamp(),
                ]);

                $_SESSION['setup_store_id'] = $storeId;
                $step = 5;
                break;

            case 5: // Mint configuration
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please go back and create a store first.');
                }

                $mintUrl = rtrim($_POST['mint_url'] ?? '', '/');
                $mintUnit = $_POST['mint_unit'] ?? null;

                if (empty($mintUrl)) {
                    throw new Exception('Mint URL is required');
                }

                // Fetch available units from mint
                require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                try {
                    $units = \Cashu\Wallet::getSupportedUnits($mintUrl);
                    if (empty($units)) {
                        throw new Exception('Could not connect to mint or no keysets found');
                    }
                } catch (Exception $e) {
                    throw new Exception('Failed to connect to mint: ' . $e->getMessage());
                }

                // If no unit selected yet, show selection
                if ($mintUnit === null || $mintUnit === '') {
                    $_SESSION['mint_url_temp'] = $mintUrl;
                    $_SESSION['mint_units'] = array_keys($units);
                    // Stay on step 5 but show unit selector
                } else {
                    // Validate selected unit
                    if (!isset($units[$mintUnit])) {
                        throw new Exception("Mint does not support unit: {$mintUnit}");
                    }

                    // Update store with mint config
                    Config::updateStore($storeId, [
                        'mint_url' => $mintUrl,
                        'mint_unit' => $mintUnit,
                    ]);

                    unset($_SESSION['mint_url_temp'], $_SESSION['mint_units']);
                    $step = 6;
                }
                break;

            case 6: // Seed phrase for this store
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please restart setup.');
                }

                $action = $_POST['action'] ?? '';

                if ($action === 'generate') {
                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    $mnemonic = \Cashu\Mnemonic::generate();
                    $_SESSION['temp_seed'] = $mnemonic;
                } elseif ($action === 'confirm') {
                    if (!isset($_POST['seed_confirmed'])) {
                        throw new Exception('Please confirm you have saved your seed phrase');
                    }

                    // Determine if seed was generated or manually entered
                    $isGenerated = isset($_SESSION['temp_seed']);
                    $seed = $_SESSION['temp_seed'] ?? $_POST['existing_seed'] ?? '';

                    if (empty($seed)) {
                        throw new Exception('No seed phrase provided');
                    }

                    // Validate mnemonic
                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    if (!\Cashu\Mnemonic::validate($seed)) {
                        throw new Exception('Invalid seed phrase');
                    }

                    // Save seed to THIS store (not global config)
                    Config::updateStore($storeId, [
                        'seed_phrase' => $seed,
                    ]);

                    // If seed was manually entered (not freshly generated), restore wallet
                    // to recover counter position and avoid "token already spent" errors
                    if (!$isGenerated) {
                        require_once __DIR__ . '/includes/invoice.php';
                        try {
                            $wallet = Invoice::getWalletInstance($storeId);
                            $restoreResult = $wallet->restore();

                            // Persist restored counters to storage
                            $storage = $wallet->getStorage();
                            if ($storage && !empty($restoreResult['counters'])) {
                                foreach ($restoreResult['counters'] as $keysetId => $counter) {
                                    $storage->setCounter($keysetId, $counter);
                                }
                            }

                            // Count unspent vs spent proofs across all units
                            $unspentCount = 0;
                            $spentCount = 0;
                            foreach ($restoreResult['byUnit'] ?? [] as $unitData) {
                                $unspentCount += count($unitData['unspent'] ?? []);
                                $spentCount += count($unitData['spent'] ?? []);
                            }

                            // Store result in session for display
                            $_SESSION['restore_result'] = [
                                'success' => true,
                                'proofs_found' => count($restoreResult['proofs'] ?? []),
                                'proofs_unspent' => $unspentCount,
                                'proofs_spent' => $spentCount,
                                'counters' => $restoreResult['counters'] ?? [],
                            ];
                        } catch (Exception $e) {
                            // Log but don't fail setup - user can still proceed
                            error_log("Wallet restore during setup failed: " . $e->getMessage());
                            $_SESSION['restore_result'] = [
                                'success' => false,
                                'error' => $e->getMessage(),
                            ];
                        }
                    }

                    unset($_SESSION['temp_seed']);

                    // For add_store mode, redirect back to admin with success
                    if ($mode === 'add_store') {
                        $createdStoreId = $storeId;
                        unset($_SESSION['setup_store_id']);
                        header('Location: ' . Urls::admin() . '?store_created=' . urlencode($createdStoreId));
                        exit;
                    }

                    Config::set('setup_complete', true);
                    $step = 7;
                }
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Clear mint session data when navigating to step 5 via GET (e.g. "Change Mint" button)
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['mint_url_temp'], $_SESSION['mint_units']);
}

// Get session data for display
$mintUrlTemp = $_SESSION['mint_url_temp'] ?? null;
$mintUnits = $_SESSION['mint_units'] ?? [];
$tempSeed = $_SESSION['temp_seed'] ?? null;


/**
 * Get the scheme://host[:port] part of the current URL (no path)
 */
function getHttpOrigin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Get the HTTP URL path to the data directory (for security testing)
 * Returns the path relative to document root (e.g., /cashupayserver/data)
 */
function getDataDirHttpPath(): ?string {
    $dataDir = realpath(Database::getDataDir()) ?: Database::getDataDir();
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';

    if (empty($docRoot) || strpos($dataDir, $docRoot) !== 0) {
        return null; // Data is outside document root - no HTTP path
    }

    // Data is inside document root - compute the URL path
    $relativePath = substr($dataDir, strlen($docRoot));
    return str_replace('\\', '/', $relativePath); // Normalize for Windows
}

// Security tests are done client-side via JavaScript for better compatibility
// (PHP's built-in server can't make HTTP requests to itself)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CashuPayServer Setup</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #e2e8f0;
            line-height: 1.6;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        h1 {
            text-align: center;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            text-align: center;
            color: #a0aec0;
            margin-bottom: 2rem;
        }

        .steps {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
        }

        .step-dot.active {
            background: #f7931a;
        }

        .step-dot.completed {
            background: #48bb78;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        input[type="text"],
        input[type="password"],
        input[type="url"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #fff;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #f7931a;
        }

        textarea {
            font-family: monospace;
            resize: vertical;
        }

        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #f7931a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            text-decoration: none;
        }

        .btn:hover {
            background: #e8820a;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn:disabled {
            background: #555;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn:disabled:hover {
            background: #555;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn-group .btn {
            flex: 1;
        }

        .error {
            background: rgba(229, 62, 62, 0.2);
            border: 1px solid rgba(229, 62, 62, 0.5);
            color: #fc8181;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .success {
            background: rgba(72, 187, 120, 0.2);
            border: 1px solid rgba(72, 187, 120, 0.5);
            color: #68d391;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .seed-display {
            background: rgba(0, 0, 0, 0.3);
            padding: 1.5rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 1.1rem;
            word-spacing: 0.5rem;
            line-height: 2;
            margin-bottom: 1.5rem;
            user-select: all;
        }

        .warning {
            background: rgba(237, 137, 54, 0.2);
            border: 1px solid rgba(237, 137, 54, 0.5);
            color: #fbd38d;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.2rem;
        }

        .security-check {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }

        .security-check .status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .security-check .status.OK { background: #48bb78; }
        .security-check .status.WARN { background: #ed8936; }
        .security-check .status.FAIL { background: #e53e3e; }
        .security-check .status.INFO { background: #4299e1; }

        .api-key-display {
            background: rgba(0, 0, 0, 0.3);
            padding: 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.9rem;
            word-break: break-all;
            margin: 1rem 0;
        }

        .help-text {
            font-size: 0.875rem;
            color: #a0aec0;
            margin-top: 0.5rem;
        }

        /* Mint discovery filter row */
        .mint-filter-row {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .mint-filter-row input[type="text"] {
            flex: 2;
            min-width: 150px;
        }
        .mint-filter-row select {
            width: auto;
            flex: 0 0 auto;
            max-width: 120px;
        }

        /* Disclaimer label highlight */
        .disclaimer-label {
            transition: background 0.2s, box-shadow 0.2s;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            margin: -0.25rem -0.5rem;
        }
        .disclaimer-label.highlight {
            background: rgba(247, 147, 26, 0.2);
            animation: pulse-highlight 1s ease-in-out infinite;
        }
        @keyframes pulse-highlight {
            0%, 100% { box-shadow: 0 0 5px rgba(247, 147, 26, 0.3); }
            50% { box-shadow: 0 0 15px rgba(247, 147, 26, 0.7); }
        }

        @media (max-width: 640px) {
            .container {
                padding: 1rem;
            }

            .card {
                padding: 1.5rem;
            }

            .btn-group {
                flex-direction: column;
            }
        }

        /* Spinner overlay for restore */
        .spinner-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .spinner-overlay.active {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: #f7931a;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .spinner-text {
            color: #fff;
            margin-top: 1rem;
            font-size: 1.1rem;
        }

        /* Code blocks with proper overflow handling */
        pre {
            overflow-x: auto;
            max-width: 100%;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="logo">&#9889;</div>
            <?php if ($mode === 'add_store'): ?>
                <h1>Add New Store</h1>
                <?php
                // Map internal steps 4-6 to display steps 1-3
                $displayStep = $step - 3;
                $totalDisplaySteps = 3;
                ?>
                <p class="subtitle">Step <?= $displayStep ?> of <?= $totalDisplaySteps ?></p>

                <div class="steps">
                    <?php for ($i = 1; $i <= $totalDisplaySteps; $i++): ?>
                        <div class="step-dot <?= $i < $displayStep ? 'completed' : ($i === $displayStep ? 'active' : '') ?>"></div>
                    <?php endfor; ?>
                </div>
            <?php else: ?>
                <h1>CashuPayServer Setup</h1>
                <?php
                $isWpMode = Urls::isWordPress();
                // WordPress: 5 steps (skip password step 2)
                // Standalone: 6 steps
                $totalSteps = $isWpMode ? 5 : 6;

                // Map internal step numbers to display step numbers
                // Internal: 1(welcome+security), 2(password), 4(store), 5(mint), 6(seed), 7(complete)
                // Step 3 no longer exists (merged into 1)
                $stepMapping = $isWpMode
                    ? [1 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5]  // Skip step 2 (password)
                    : [1 => 1, 2 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6];
                $displayStep = $stepMapping[$step] ?? $step;
                ?>
                <p class="subtitle">Step <?= $displayStep ?> of <?= $totalSteps ?></p>

                <div class="steps">
                    <?php for ($i = 1; $i <= $totalSteps; $i++): ?>
                        <div class="step-dot <?= $i < $displayStep ? 'completed' : ($i === $displayStep ? 'active' : '') ?>"></div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <!-- Step 1: Welcome + Security Check (merged) -->
                <h2 style="margin-bottom: 1rem;">Welcome</h2>
                <p style="margin-bottom: 1.5rem;">
                    CashuPayServer is a Lightning payment gateway that uses Cashu ecash.
                    Let's get you set up in a few minutes.
                </p>

                <?php
                // Check PHP requirements silently - only show if something fails
                $checks = [
                    ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=')],
                    ['cURL extension', extension_loaded('curl')],
                    ['JSON extension', extension_loaded('json')],
                    ['PDO SQLite', extension_loaded('pdo_sqlite')],
                    ['GMP or BCMath', extension_loaded('gmp') || extension_loaded('bcmath')],
                ];
                $allPassed = true;
                $failedChecks = [];
                foreach ($checks as [$name, $passed]) {
                    if (!$passed) {
                        $allPassed = false;
                        $failedChecks[] = $name;
                    }
                }
                ?>

                <?php if (!$allPassed): ?>
                    <div class="error" style="margin-bottom: 1.5rem;">
                        <strong>Missing Requirements</strong>
                        <p style="margin-top: 0.5rem;">Please install the following before continuing:</p>
                        <ul style="margin: 0.5rem 0 0 1.25rem;">
                            <?php foreach ($failedChecks as $name): ?>
                                <li><?= htmlspecialchars($name) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <!-- Security Check Section -->
                    <h3 style="margin-bottom: 0.75rem;">Protecting Your Database</h3>
                    <p style="margin-bottom: 1rem; color: #a0aec0; font-size: 0.9rem;">
                        Your database stores ecash tokens with real monetary value. It's critical this file
                        cannot be downloaded via the web. If in doubt, verify manually or ask someone.
                    </p>

                    <?php
                    $isOutsideWebroot = Database::isDataDirOutsideWebroot();
                    $dataPath = getDataDirHttpPath();
                    $baseOrigin = getHttpOrigin();

                    // Build list of URL paths to test (same logic as PHP version, but for JS)
                    $testPaths = [];
                    if ($dataPath !== null) {
                        $testPaths[] = $dataPath . '/cashupay.sqlite';
                    }
                    $testPaths[] = '/data/cashupay.sqlite';
                    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
                    $appDir = dirname($scriptPath);
                    if ($appDir && $appDir !== '/' && $appDir !== '.') {
                        $testPaths[] = $appDir . '/data/cashupay.sqlite';
                    }
                    // Normalize and deduplicate
                    $testPaths = array_unique(array_map(function($p) {
                        return '/' . ltrim($p, '/');
                    }, $testPaths));
                    ?>

                    <!-- Recommended: Outside webroot (shown prominently, not collapsed) -->
                    <div style="background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <p style="margin-bottom: 0.75rem; font-weight: 500; color: #68d391;">
                            Recommended: Store data outside web root
                        </p>
                        <p style="margin-bottom: 0.75rem; color: #a0aec0; font-size: 0.9rem;">
                            For maximum security, store your database outside the web-accessible directory.
                            Even if server configuration is wrong, your data cannot be downloaded.
                        </p>

                        <p style="margin-bottom: 0.5rem; font-size: 0.9rem;"><strong>1. Create a directory outside your web root:</strong></p>
                        <pre style="background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.75rem; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;">mkdir -p /home/youruser/cashupay-data
chmod 750 /home/youruser/cashupay-data</pre>

                        <p style="margin-bottom: 0.5rem; font-size: 0.9rem;"><strong>2. Create <code>includes/config.local.php</code>:</strong></p>
                        <pre style="background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.75rem; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;">&lt;?php
define('CASHUPAY_DATA_DIR', '/home/youruser/cashupay-data');</pre>

                        <p style="margin-bottom: 0; font-size: 0.9rem;"><strong>3. Re-run the setup wizard</strong> to verify the new location.</p>
                    </div>

                    <p style="color: #a0aec0; font-size: 0.85rem; margin-bottom: 1rem;">
                        Current data location: <code><?= htmlspecialchars(Database::getDataDir()) ?></code>
                        <?php if ($isOutsideWebroot): ?>
                            <span style="color: #48bb78;">(outside web root)</span>
                        <?php endif; ?>
                    </p>

                    <!-- HTTP accessibility test results (populated by JavaScript) -->
                    <h4 style="margin-bottom: 0.5rem;">Security Test Results</h4>

                    <!-- Loading state -->
                    <div id="security-test-loading" class="security-check">
                        <div class="status INFO"></div>
                        <div style="flex: 1;">
                            <span>Testing database accessibility...</span>
                            <p style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;">
                                Checking if database can be downloaded via HTTP
                            </p>
                        </div>
                    </div>

                    <!-- Results (hidden initially, shown by JS) -->
                    <div id="security-test-result" style="display: none;">
                        <div class="security-check">
                            <div id="security-test-status" class="status OK"></div>
                            <div style="flex: 1;">
                                <span id="security-test-message"></span>
                                <p id="security-test-details" style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;"></p>
                                <div id="security-test-urls" style="font-size: 0.8rem; color: #718096; margin-top: 0.5rem; font-family: monospace;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Critical error (hidden initially) -->
                    <div id="security-test-critical" class="error" style="display: none; margin-top: 1rem;">
                        <strong>Security Issue!</strong> Your database file is accessible via the web.
                        You must fix this before continuing or your funds could be stolen.
                    </div>

                    <!-- Re-test button -->
                    <button type="button" id="security-retest-btn" class="btn btn-secondary" style="width: 100%; margin-top: 1rem;" onclick="runSecurityTest()">
                        Re-run Security Test
                    </button>

                    <?php if ($dataPath !== null):
                        $testUrl = $baseOrigin . $dataPath . '/cashupay.sqlite';
                    ?>
                    <details style="margin-top: 1rem;">
                        <summary style="cursor: pointer; color: #a0aec0;">Manual verification &amp; server configuration</summary>
                        <div style="margin-top: 0.75rem; padding: 1rem; background: rgba(0,0,0,0.2); border-radius: 8px;">
                            <p style="margin-bottom: 0.75rem;"><strong>How to verify manually:</strong></p>
                            <ol style="margin: 0 0 1rem 1.25rem; padding: 0; color: #a0aec0; font-size: 0.9rem;">
                                <li>Open this URL in your browser:<br>
                                    <a href="<?= htmlspecialchars($testUrl) ?>" target="_blank" rel="noopener" style="color: #63b3ed; word-break: break-all;"><?= htmlspecialchars($testUrl) ?></a>
                                </li>
                                <li>You should see an error page (403 Forbidden or 404 Not Found)</li>
                                <li>If the file downloads, your data is exposed!</li>
                            </ol>

                            <p style="margin-bottom: 0.5rem;"><strong>Apache / Shared Hosting:</strong></p>
                            <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 1rem;">
                                The <code>.htaccess</code> file should already protect the directory. If not working, contact your host.
                            </p>

                            <p style="margin-bottom: 0.5rem;"><strong>Nginx:</strong></p>
                            <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.5rem;">Add to your server config:</p>
                            <pre style="background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;">location <?= htmlspecialchars($dataPath) ?>/ {
    deny all;
    return 404;
}</pre>
                        </div>
                    </details>
                    <?php endif; ?>

                    <p style="margin-top: 1rem; color: #a0aec0; font-size: 0.85rem;">
                        Note: These automated checks are supplemental. You should verify manually that the database file cannot be downloaded.
                    </p>

                    <!-- URL Mode Detection (standalone only) -->
                    <?php if (!Urls::isWordPress()): ?>
                    <h4 style="margin-top: 1.5rem; margin-bottom: 0.5rem;">Server URL Detection</h4>
                    <div id="url-mode-detection">
                        <div id="url-mode-loading" class="security-check">
                            <div class="status INFO"></div>
                            <span>Detecting server URL configuration...</span>
                        </div>
                        <div id="url-mode-result" style="display: none;" class="security-check">
                            <div id="url-mode-status" class="status OK"></div>
                            <div style="flex: 1;">
                                <span id="url-mode-message"></span>
                                <p id="url-mode-details" style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;"></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="step" value="1">

                        <div class="checkbox-group" style="margin: 1.5rem 0;">
                            <input type="checkbox" id="security_acknowledged" name="security_acknowledged" required>
                            <label for="security_acknowledged">
                                I have verified that the database is not accessible from the web
                            </label>
                        </div>

                        <button type="submit" class="btn" style="width: 100%;">Continue</button>
                    </form>

                    <script>
                    (function() {
                        const baseOrigin = <?= json_encode($baseOrigin) ?>;
                        const testPaths = <?= json_encode(array_values($testPaths)) ?>;
                        const isOutsideWebroot = <?= json_encode($isOutsideWebroot) ?>;

                        async function runSecurityTest() {
                            const loadingEl = document.getElementById('security-test-loading');
                            const resultEl = document.getElementById('security-test-result');
                            const criticalEl = document.getElementById('security-test-critical');
                            const statusEl = document.getElementById('security-test-status');
                            const messageEl = document.getElementById('security-test-message');
                            const detailsEl = document.getElementById('security-test-details');
                            const urlsEl = document.getElementById('security-test-urls');

                            // Show loading
                            loadingEl.style.display = 'flex';
                            resultEl.style.display = 'none';
                            criticalEl.style.display = 'none';

                            const results = {};
                            let worstStatus = 'OK';
                            const exposedPaths = [];

                            // Test each path
                            for (const path of testPaths) {
                                const url = baseOrigin + path;
                                try {
                                    const response = await fetch(url, {
                                        method: 'HEAD',
                                        mode: 'same-origin',
                                        cache: 'no-store'
                                    });
                                    results[path] = response.status;

                                    if (response.status === 200) {
                                        worstStatus = 'FAIL';
                                        exposedPaths.push(path);
                                    }
                                } catch (e) {
                                    // Network error - could be CORS, blocked, etc.
                                    // This is actually good - means it's not accessible
                                    results[path] = 'blocked';
                                }
                            }

                            // Hide loading, show results
                            loadingEl.style.display = 'none';
                            resultEl.style.display = 'block';

                            // Update status indicator
                            statusEl.className = 'status ' + worstStatus;

                            // Build results HTML
                            let urlsHtml = '';
                            for (const [path, status] of Object.entries(results)) {
                                if (status === 200) {
                                    urlsHtml += '<div>' + escapeHtml(path) + ': <span style="color: #fc8181;">HTTP 200 (EXPOSED!)</span></div>';
                                } else if (status === 'blocked') {
                                    urlsHtml += '<div>' + escapeHtml(path) + ': <span style="color: #48bb78;">Blocked</span></div>';
                                } else {
                                    urlsHtml += '<div>' + escapeHtml(path) + ': HTTP ' + status + '</div>';
                                }
                            }
                            urlsEl.innerHTML = urlsHtml;

                            if (worstStatus === 'FAIL') {
                                messageEl.textContent = 'CRITICAL: Database accessible via HTTP!';
                                detailsEl.textContent = 'Exposed paths: ' + exposedPaths.join(', ') + '. Anyone can download your database!';
                                criticalEl.style.display = 'block';
                            } else if (isOutsideWebroot) {
                                messageEl.textContent = 'Data directory is outside document root';
                                detailsEl.textContent = 'Most secure configuration - data is not accessible via HTTP.';
                            } else {
                                messageEl.textContent = 'Data directory is protected';
                                detailsEl.textContent = 'All tested URL paths correctly return 403/404 or are blocked.';
                            }
                        }

                        function escapeHtml(text) {
                            const div = document.createElement('div');
                            div.textContent = text;
                            return div.innerHTML;
                        }

                        // Make function available globally for re-test button
                        window.runSecurityTest = runSecurityTest;

                        // Run test on page load
                        runSecurityTest();

                        // URL Mode Detection (standalone only)
                        <?php if (!Urls::isWordPress()): ?>
                        async function detectAndSaveUrlMode() {
                            const loadingEl = document.getElementById('url-mode-loading');
                            const resultEl = document.getElementById('url-mode-result');
                            const statusEl = document.getElementById('url-mode-status');
                            const messageEl = document.getElementById('url-mode-message');
                            const detailsEl = document.getElementById('url-mode-details');

                            const baseUrl = <?= json_encode(Urls::siteBase()) ?>;
                            const setupUrl = <?= json_encode(Urls::setup()) ?>;

                            // Test both URL patterns
                            const tests = {
                                direct: { url: baseUrl + '/api/v1/server/info', works: false },
                                router: { url: baseUrl + '/router.php/api/v1/server/info', works: false }
                            };

                            for (const [mode, test] of Object.entries(tests)) {
                                try {
                                    const response = await fetch(test.url, { method: 'GET', mode: 'same-origin' });
                                    // Accept 200 or 503 (setup not complete) as success - both mean routing works
                                    test.works = response.status === 200 || response.status === 503;
                                } catch (e) {
                                    test.works = false;
                                }
                            }

                            // Determine which mode to use (prefer direct)
                            let selectedMode = null;
                            if (tests.direct.works) {
                                selectedMode = 'direct';
                            } else if (tests.router.works) {
                                selectedMode = 'router';
                            }

                            // Save the detected mode
                            if (selectedMode) {
                                try {
                                    const formData = new FormData();
                                    formData.append('action', 'save_url_mode');
                                    formData.append('mode', selectedMode);
                                    await fetch(setupUrl, { method: 'POST', body: formData });
                                } catch (e) {
                                    console.error('Failed to save URL mode:', e);
                                }
                            }

                            // Update UI
                            loadingEl.style.display = 'none';
                            resultEl.style.display = 'flex';

                            if (selectedMode === 'direct') {
                                statusEl.className = 'status OK';
                                messageEl.textContent = 'Direct URLs working';
                                detailsEl.textContent = 'Clean URLs like /api/v1/... are supported.';
                            } else if (selectedMode === 'router') {
                                statusEl.className = 'status OK';
                                messageEl.textContent = 'Router.php URLs working';
                                detailsEl.textContent = 'Using /router.php/api/v1/... for compatibility.';
                            } else {
                                statusEl.className = 'status WARN';
                                messageEl.textContent = 'Could not detect working URL mode';
                                detailsEl.textContent = 'You may need to configure your server. Check settings after setup.';
                            }
                        }

                        // Run URL detection after page load
                        detectAndSaveUrlMode();
                        <?php endif; ?>
                    })();
                    </script>
                <?php endif; ?>

            <?php elseif ($step === 2): ?>
                <!-- Step 2: Admin Password -->
                <h2 style="margin-bottom: 1rem;">Admin Password</h2>
                <p style="margin-bottom: 1.5rem;">Create a password for the admin interface.</p>

                <form method="post">
                    <input type="hidden" name="step" value="2">

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <p class="help-text">Minimum 8 characters</p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Continue</button>
                </form>

            <?php elseif ($step === 4): ?>
                <!-- Step 4: Create Store (name only) -->
                <h2 style="margin-bottom: 1rem;">Create Store</h2>
                <p style="margin-bottom: 1.5rem;"><?= $mode === 'add_store' ? 'Create a new store. You\'ll configure its mint and wallet next.' : 'Create your first store. You\'ll configure its mint and wallet next.' ?></p>

                <form method="post">
                    <input type="hidden" name="step" value="4">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="store_name">Store Name</label>
                        <input type="text" id="store_name" name="store_name"
                               value="My Store" required>
                        <p class="help-text">This name will be shown on payment pages</p>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Create Store & Continue</button>
                </form>
                <?php if ($mode === 'add_store'): ?>
                    <a href="<?= htmlspecialchars(Urls::admin()) ?>" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem; text-align: center;">Cancel</a>
                <?php endif; ?>

            <?php elseif ($step === 5): ?>
                <!-- Step 5: Mint Configuration (with dynamic unit selection) -->
                <h2 style="margin-bottom: 1rem;">Connect Cashu Mint</h2>
                <p style="margin-bottom: 1.5rem;">
                    <?php if (!empty($mintUnits)): ?>
                        Select which currency unit to use with this mint.
                    <?php else: ?>
                        Enter the URL of the Cashu mint for this store.
                    <?php endif; ?>
                </p>

                <form method="post">
                    <input type="hidden" name="step" value="5">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <?php if (!empty($mintUnits)): ?>
                        <!-- Phase 2: Unit selection (shown after URL submitted) -->
                        <input type="hidden" name="mint_url" value="<?= htmlspecialchars($mintUrlTemp) ?>">

                        <div style="background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                            <p style="font-size: 0.9rem; color: #68d391;">Connected to mint:</p>
                            <code style="word-break: break-all;"><?= htmlspecialchars($mintUrlTemp) ?></code>
                        </div>

                        <div class="form-group">
                            <label for="mint_unit">Currency Unit</label>
                            <?php
                            // Sort units to put 'sat' first
                            $sortedUnits = $mintUnits;
                            usort($sortedUnits, function($a, $b) {
                                if ($a === 'sat') return -1;
                                if ($b === 'sat') return 1;
                                return strcmp($a, $b);
                            });
                            $hasSat = in_array('sat', $sortedUnits);
                            ?>
                            <select id="mint_unit" name="mint_unit" required onchange="testMintExpirySetup()">
                                <?php if (!$hasSat): ?>
                                    <option value="">Select unit...</option>
                                <?php endif; ?>
                                <?php foreach ($sortedUnits as $unit): ?>
                                    <?php
                                    $displayName = ($unit === 'sat') ? 'Bitcoin (sats)' : strtoupper($unit);
                                    $selected = ($unit === 'sat') ? ' selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($unit) ?>"<?= $selected ?>><?= htmlspecialchars($displayName) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="help-text">Available units from this mint. Choose the one that matches how you want to receive payments.</p>
                        </div>

                        <div id="expiry-test-status" style="display: none; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;"></div>

                        <div id="expiry-warning" class="warning" style="display: none;">
                            <strong>Short Invoice Expiry</strong>
                            <p id="expiry-warning-message" style="margin: 0.5rem 0;"></p>
                            <label class="checkbox-group" style="margin-top: 0.75rem;">
                                <input type="checkbox" id="expiry-acknowledged" name="expiry_acknowledged">
                                <span>I understand this may cause payment issues and want to proceed anyway</span>
                            </label>
                        </div>

                        <div class="btn-group">
                            <a href="?step=5<?= $mode === 'add_store' ? '&mode=add_store' : '' ?>" class="btn btn-secondary" style="text-align: center;">Change Mint</a>
                            <button type="submit" class="btn" id="continue-btn">Continue</button>
                        </div>
                    <?php else: ?>
                        <!-- Phase 1: Enter mint URL -->
                        <div class="form-group">
                            <label for="mint_url">Mint URL</label>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="url" id="mint_url" name="mint_url"
                                       placeholder="https://..."
                                       value="<?= htmlspecialchars($_POST['mint_url'] ?? '') ?>"
                                       required style="flex: 1;">
                                <button type="button" class="btn btn-secondary" onclick="openMintDiscovery()" style="white-space: nowrap;">Discover</button>
                            </div>
                        </div>

                        <button type="submit" class="btn" style="width: 100%;">Connect to Mint</button>
                    <?php endif; ?>
                </form>

            <?php elseif ($step === 6): ?>
                <!-- Step 6: Seed Phrase for this Store -->
                <h2 style="margin-bottom: 1rem;">Store Wallet Seed</h2>

                <?php if ($tempSeed): ?>
                    <div class="warning">
                        <strong>Important!</strong> This seed phrase is for <strong>backup and recovery only</strong>.
                        <ul style="margin: 0.5rem 0 0 1.25rem;">
                            <li>Write it down and store it in a safe place</li>
                            <li>Do NOT import this seed into another wallet you use regularly</li>
                            <li>Using the same seed in multiple active wallets causes coin loss</li>
                        </ul>
                    </div>

                    <div class="seed-display"><?= htmlspecialchars($tempSeed) ?></div>

                    <form method="post">
                        <input type="hidden" name="step" value="6">
                        <input type="hidden" name="action" value="confirm">
                        <?php if ($mode === 'add_store'): ?>
                            <input type="hidden" name="mode" value="add_store">
                        <?php endif; ?>

                        <div class="checkbox-group" style="margin-bottom: 1.5rem;">
                            <input type="checkbox" id="seed_confirmed" name="seed_confirmed" required>
                            <label for="seed_confirmed">I have written down my seed phrase and stored it safely</label>
                        </div>

                        <button type="submit" class="btn" style="width: 100%;"><?= $mode === 'add_store' ? 'Create Store' : 'Complete Setup' ?></button>
                    </form>
                <?php else: ?>
                    <p style="margin-bottom: 1.5rem;">
                        Generate a new seed phrase for this store's wallet, or restore from an existing seed.
                    </p>

                    <form method="post" style="margin-bottom: 1rem;">
                        <input type="hidden" name="step" value="6">
                        <input type="hidden" name="action" value="generate">
                        <?php if ($mode === 'add_store'): ?>
                            <input type="hidden" name="mode" value="add_store">
                        <?php endif; ?>
                        <button type="submit" class="btn" style="width: 100%;">Generate New Seed Phrase</button>
                    </form>

                    <details style="margin-top: 1.5rem;">
                        <summary style="cursor: pointer; color: #a0aec0;">Restore from existing seed phrase</summary>
                        <form method="post" style="margin-top: 1rem;">
                            <input type="hidden" name="step" value="6">
                            <input type="hidden" name="action" value="confirm">
                            <?php if ($mode === 'add_store'): ?>
                                <input type="hidden" name="mode" value="add_store">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="existing_seed">12-word seed phrase</label>
                                <textarea id="existing_seed" name="existing_seed" rows="3" placeholder="word1 word2 word3 ..."></textarea>
                            </div>

                            <div class="checkbox-group" style="margin-bottom: 1.5rem;">
                                <input type="checkbox" id="seed_confirmed2" name="seed_confirmed" required>
                                <label for="seed_confirmed2">I understand this will restore an existing wallet and that this seed phrase must not be used anywhere else (another store, instance, or wallet) as this will cause loss of funds</label>
                            </div>

                            <button type="submit" class="btn btn-secondary" style="width: 100%;"><?= $mode === 'add_store' ? 'Create Store with Existing Seed' : 'Use Existing Seed' ?></button>
                        </form>
                    </details>
                <?php endif; ?>

            <?php elseif ($step === 7): ?>
                <!-- Step 7: Complete -->
                <h2 style="margin-bottom: 1rem;">Setup Complete!</h2>

                <div class="success">
                    CashuPayServer is ready to accept payments.
                </div>

                <?php
                // Check for restore result from previous step
                $restoreResult = $_SESSION['restore_result'] ?? null;
                if ($restoreResult):
                    unset($_SESSION['restore_result']);
                    if ($restoreResult['success']):
                        $proofsFound = $restoreResult['proofs_found'] ?? 0;
                        $proofsUnspent = $restoreResult['proofs_unspent'] ?? 0;
                        $proofsSpent = $restoreResult['proofs_spent'] ?? 0;
                        $countersRestored = count($restoreResult['counters'] ?? []);
                        if ($proofsFound > 0 || $countersRestored > 0): ?>
                <div style="background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <p style="margin-bottom: 0.25rem; font-weight: 500; color: #68d391;">Wallet Restored</p>
                    <p style="color: #a0aec0; font-size: 0.9rem;">
                        <?php if ($proofsFound > 0): ?>
                            Found <?= $proofsFound ?> token(s) from previous use.
                            <?php if ($proofsUnspent > 0 || $proofsSpent > 0): ?>
                                <br><span style="font-size: 0.85rem;">
                                    <?= $proofsUnspent ?> unspent (available)<?php if ($proofsSpent > 0): ?>, <?= $proofsSpent ?> already spent<?php endif; ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            Counter position restored for <?= $countersRestored ?> keyset(s).
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif;
                    elseif (!empty($restoreResult['error'])): ?>
                <div class="warning" style="margin-bottom: 1.5rem;">
                    <p style="margin-bottom: 0.25rem; font-weight: 500;">Wallet Restore Warning</p>
                    <p style="font-size: 0.9rem;">
                        Could not restore wallet history: <?= htmlspecialchars($restoreResult['error']) ?>
                    </p>
                    <p style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.5rem;">
                        If this seed was used before, you may encounter "token already spent" errors.
                        You can re-run setup with the same seed to try again.
                    </p>
                </div>
                <?php endif;
                endif;
                ?>

                <?php
                $baseUrl = Urls::siteBase();
                ?>

                <?php if (Urls::isWordPress()): ?>
                <!-- WooCommerce BTCPay Integration (WordPress mode) - shown first in WP mode -->
                <?php
                if (!function_exists('is_plugin_active')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                require_once __DIR__ . '/btcpay-integration.php';
                $btcpayPluginActive = is_plugin_active('btcpay-greenfield-for-woocommerce/btcpay-greenfield-for-woocommerce.php');
                $storeId = $_SESSION['setup_store_id'] ?? null;
                $wooConfigured = false;

                if ($btcpayPluginActive && $storeId) {
                    // Get or create an API key for WooCommerce
                    $apiKey = Auth::getOrCreateInternalApiKey($storeId);
                    $configResult = null;

                    if (isset($_POST['configure_woocommerce']) && $apiKey) {
                        $configResult = cashupay_configure_btcpay_plugin($storeId, $apiKey);
                        $wooConfigured = $configResult['success'] ?? false;
                    }
                }
                ?>
                <div style="background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 0.75rem;">WooCommerce Integration</h3>

                    <?php if (!$btcpayPluginActive): ?>
                        <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.75rem;">
                            To accept payments in WooCommerce, install the
                            <strong>"BTCPay Server for WooCommerce"</strong> plugin:
                        </p>
                        <ol style="color: #a0aec0; font-size: 0.9rem; margin: 0 0 1rem 1.25rem; padding: 0;">
                            <li>Go to Plugins &rarr; Add New in WordPress</li>
                            <li>Search for "BTCPay Server for WooCommerce"</li>
                            <li>Install and activate the plugin</li>
                            <li>Return here to auto-configure it</li>
                        </ol>
                        <a href="<?= admin_url('plugin-install.php?s=btcpay+greenfield+woocommerce&tab=search&type=term') ?>" class="btn btn-secondary" style="display: inline-block;">
                            Install BTCPay Plugin
                        </a>
                    <?php elseif ($wooConfigured): ?>
                        <div class="success">
                            WooCommerce has been configured to use CashuPay for payments.
                        </div>
                    <?php elseif (isset($configResult) && !$configResult['success']): ?>
                        <div class="warning">
                            <strong>Cannot auto-configure:</strong>
                            <?= htmlspecialchars($configResult['message']) ?>
                        </div>
                        <p style="color: #a0aec0; font-size: 0.9rem; margin-top: 0.75rem;">
                            Disconnect your existing BTCPay Server via WooCommerce &rarr; Settings &rarr; Payments &rarr; BTCPay, then return here.
                        </p>
                        <a href="<?= admin_url('admin.php?page=wc-settings&tab=checkout&section=btcpay_greenfield') ?>" class="btn btn-secondary" style="display: inline-block; margin-top: 0.5rem;">
                            Go to BTCPay Settings
                        </a>
                    <?php else: ?>
                        <?php if (cashupay_is_real_btcpay_configured()): ?>
                            <div class="warning">
                                <strong>Existing BTCPay Server detected:</strong>
                                <code style="display: block; margin-top: 0.5rem; word-break: break-all;"><?= htmlspecialchars(get_option('btcpay_gf_url', '')) ?></code>
                            </div>
                            <p style="color: #a0aec0; font-size: 0.9rem; margin: 0.75rem 0;">
                                A real BTCPay Server is configured. To use CashuPay instead, disconnect it first via WooCommerce settings.
                            </p>
                            <div class="btn-group">
                                <a href="<?= admin_url('admin.php?page=wc-settings&tab=checkout&section=btcpay_greenfield') ?>" class="btn btn-secondary" style="text-align: center;">Go to BTCPay Settings</a>
                                <a href="<?= admin_url('admin.php?page=cashupay') ?>" class="btn" style="text-align: center;">Skip</a>
                            </div>
                        <?php else: ?>
                            <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 1rem;">
                                The BTCPay WooCommerce plugin is active. Click below to auto-configure it to use CashuPay.
                            </p>
                            <form method="post">
                                <input type="hidden" name="step" value="7">
                                <input type="hidden" name="configure_woocommerce" value="1">
                                <button type="submit" class="btn" style="width: 100%;">Configure WooCommerce</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Server URL (already detected in Step 1) -->
                <?php
                $serverUrl = Urls::server();
                $urlMode = Config::getUrlMode();
                $urlModeLabel = Urls::isWordPress() ? 'WordPress routing' : ($urlMode === 'direct' ? 'Direct URLs (clean)' : 'Router.php URLs (compatible)');
                ?>
                <div style="background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <p style="margin-bottom: 0.5rem; font-weight: 500;">Your Server URL</p>
                    <code id="detected-server-url" style="display: block; background: rgba(0,0,0,0.3); padding: 0.75rem; border-radius: 4px; font-size: 0.95rem; word-break: break-all; user-select: all;">
                        <?= htmlspecialchars($serverUrl) ?>
                    </code>
                    <p style="color: #a0aec0; font-size: 0.8rem; margin-top: 0.5rem;">
                        Enter this URL in your e-commerce plugin's BTCPay Server settings
                    </p>
                    <p style="color: #68d391; font-size: 0.8rem; margin-top: 0.25rem;"><?= htmlspecialchars($urlModeLabel) ?></p>
                </div>

                <h3 style="margin-bottom: 0.75rem;">Connect Your E-commerce</h3>

                <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <p style="margin-bottom: 0.75rem;"><strong>Option A: Automatic Pairing (Recommended)</strong></p>
                    <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.75rem;">
                        Most BTCPay plugins (WooCommerce, OpenCart, etc.) support automatic pairing:
                    </p>
                    <ol style="color: #a0aec0; font-size: 0.9rem; margin: 0 0 0.75rem 1.25rem; padding: 0;">
                        <li>In your e-commerce plugin settings, enter the Server URL shown above</li>
                        <li>Click "Connect" or "Pair with BTCPay"</li>
                        <li>You'll be redirected here to approve the connection</li>
                    </ol>
                    <?php
                    // Build pairing URL with proper permissions
                    $pairingParams = http_build_query([
                        'applicationName' => 'Test Connection',
                        'permissions[]' => 'btcpay.store.canviewinvoices',
                        'strict' => 'true'
                    ]) . '&permissions[]=btcpay.store.cancreateinvoice&permissions[]=btcpay.store.webhooks.canmodifywebhooks';
                    $pairingUrl = $serverUrl . '/api-keys/authorize?' . $pairingParams;
                    ?>
                    <a id="test-pairing-link" href="<?= htmlspecialchars($pairingUrl) ?>" class="btn btn-secondary" style="display: inline-block; font-size: 0.9rem; padding: 0.5rem 1rem;">
                        Test Pairing Flow
                    </a>
                </div>

                <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <p style="margin-bottom: 0.75rem;"><strong>Option B: Manual API Key</strong></p>
                    <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.5rem;">
                        If your plugin doesn't support automatic pairing:
                    </p>
                    <ol style="color: #a0aec0; font-size: 0.9rem; margin: 0 0 0 1.25rem; padding: 0;">
                        <li>Go to the Admin Dashboard below</li>
                        <li>Click on your store</li>
                        <li>Click "Create API Key"</li>
                        <li>Copy the key and paste it into your e-commerce plugin</li>
                    </ol>
                </div>

                <details style="margin-bottom: 1.5rem;">
                    <summary style="cursor: pointer; color: #a0aec0; font-size: 0.9rem;">URL Detection Details</summary>
                    <div style="margin-top: 0.75rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px; font-size: 0.85rem;">
                        <div style="font-family: monospace; color: #48bb78;">
                            <?php if (Urls::isWordPress()): ?>
                                wordpress: OK
                            <?php else: ?>
                                <?= $urlMode ?>: OK (detected in Step 1)
                            <?php endif; ?>
                        </div>
                    </div>
                </details>

                <a href="<?= Urls::isWordPress() ? admin_url('admin.php?page=cashupay') : Urls::admin() ?>" class="btn" style="width: 100%; text-align: center; display: block;">
                    Go to CashuPay Admin
                </a>
                <?php if (Urls::isWordPress()): ?>
                <a href="<?= admin_url() ?>" class="btn btn-secondary" style="width: 100%; text-align: center; display: block; margin-top: 0.5rem;">
                    Back to WordPress Dashboard
                </a>
                <?php endif; ?>

                <?php
                // Clear temporary session data
                // In WordPress mode, keep session if WooCommerce still needs to be configured
                if (!Urls::isWordPress() || (isset($wooConfigured) && $wooConfigured)) {
                    unset($_SESSION['setup_store_id'], $_SESSION['mint_units'], $_SESSION['mint_url_temp'], $_SESSION['temp_seed']);
                }
                ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Spinner overlay for restore -->
    <div class="spinner-overlay" id="spinnerOverlay">
        <div class="spinner"></div>
        <div class="spinner-text" id="spinnerText">Restoring wallet from seed...</div>
    </div>

    <!-- Mint Discovery Modal -->
    <div id="mint-discovery-modal" class="modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; justify-content: center; align-items: center;">
        <div class="card" style="max-width: 700px; width: 90%; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Discover Mints</h3>
                <button type="button" onclick="closeMintDiscovery()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #e2e8f0;">&times;</button>
            </div>

            <div style="background: rgba(237, 137, 54, 0.15); border: 1px solid rgba(237, 137, 54, 0.4); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <p style="font-size: 0.85rem; color: #fbd38d; margin: 0 0 0.75rem 0; line-height: 1.5;">
                    Audit data is provided by independent third parties to help assess a mint's reliability over time. However, these results are informational only and do not guarantee the safety, solvency, or trustworthiness of any mint. Always conduct your own research and ensure you trust the mint operator before using their services. To be sure, run your own mint, this is the Bitcoin way!
                </p>
                <label id="disclaimer-label" class="disclaimer-label" style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="mint-disclaimer-checkbox" onchange="onDisclaimerChange(this)" style="width: 18px; height: 18px;">
                    <span style="font-size: 0.85rem; color: #fbd38d;">I understand the above</span>
                </label>
            </div>

            <div id="mint-discovery-status" style="font-size: 0.85rem; color: #a0aec0; margin-bottom: 1rem;">
                Loading mints from Nostr...
            </div>

            <div class="mint-filter-row">
                <input type="text" id="mint-search" placeholder="Search mints..." onkeyup="filterMintList()">
                <select id="mint-unit-filter" onchange="filterMintList()">
                    <option value="">All units</option>
                    <option value="sat">SAT</option>
                    <option value="eur">EUR</option>
                    <option value="usd">USD</option>
                </select>
                <button type="button" class="btn btn-secondary" onclick="startMintDiscovery()" style="white-space: nowrap;">Refresh</button>
            </div>

            <div id="mint-discovery-list" style="flex: 1; overflow-y: auto; max-height: 400px;">
                <p style="color: #a0aec0; font-size: 0.9rem; text-align: center; padding: 2rem;">
                    Loading...
                </p>
            </div>

            <div id="mint-discovery-loading" style="display: none; text-align: center; padding: 2rem;">
                <div class="spinner"></div>
                <p style="margin-top: 1rem; color: #a0aec0;">Connecting to Nostr relays...</p>
            </div>
        </div>
    </div>

    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>mint-discovery.bundle.js"></script>
    <script>
    // Mint Discovery state
    var mintDiscoveryInstance = null;
    var discoveredMints = [];
    var disclaimerAcknowledged = false;

    function openMintDiscovery() {
        document.getElementById('mint-discovery-modal').style.display = 'flex';
        // Reset disclaimer checkbox state when opening
        var checkbox = document.getElementById('mint-disclaimer-checkbox');
        if (checkbox) {
            checkbox.checked = false;
            disclaimerAcknowledged = false;
        }
        // Setup hover listeners for disabled buttons
        setupDisabledButtonHover();
        // Auto-start discovery
        startMintDiscovery();
    }

    function onDisclaimerChange(checkbox) {
        disclaimerAcknowledged = checkbox.checked;
        // Remove highlight when checkbox is checked
        if (checkbox.checked) {
            highlightDisclaimer(false);
        }
        updateSelectButtons();
    }

    function highlightDisclaimer(show) {
        var label = document.getElementById('disclaimer-label');
        if (label) {
            if (show) {
                label.classList.add('highlight');
            } else {
                label.classList.remove('highlight');
            }
        }
    }

    function setupDisabledButtonHover() {
        var listEl = document.getElementById('mint-discovery-list');
        if (!listEl) return;

        listEl.addEventListener('mouseenter', function(e) {
            if (e.target.tagName === 'BUTTON' && e.target.disabled) {
                highlightDisclaimer(true);
            }
        }, true);

        listEl.addEventListener('mouseleave', function(e) {
            if (e.target.tagName === 'BUTTON') {
                highlightDisclaimer(false);
            }
        }, true);
    }

    function updateSelectButtons() {
        var buttons = document.querySelectorAll('#mint-discovery-list button');
        buttons.forEach(function(btn) {
            btn.disabled = !disclaimerAcknowledged;
            btn.style.opacity = disclaimerAcknowledged ? '1' : '0.5';
            btn.style.cursor = disclaimerAcknowledged ? 'pointer' : 'not-allowed';
        });
    }

    function closeMintDiscovery() {
        document.getElementById('mint-discovery-modal').style.display = 'none';
        if (mintDiscoveryInstance) {
            mintDiscoveryInstance.close();
            mintDiscoveryInstance = null;
        }
    }

    function startMintDiscovery() {
        var listEl = document.getElementById('mint-discovery-list');
        var loadingEl = document.getElementById('mint-discovery-loading');
        var statusEl = document.getElementById('mint-discovery-status');

        loadingEl.style.display = 'block';
        listEl.innerHTML = '';
        discoveredMints = [];
        statusEl.textContent = 'Connecting to Nostr relays...';

        if (typeof MintDiscovery === 'undefined') {
            statusEl.textContent = 'Error: MintDiscovery library not loaded';
            loadingEl.style.display = 'none';
            return;
        }

        mintDiscoveryInstance = MintDiscovery.create({
            httpTimeout: 8000,
            nostrTimeout: 15000
        });

        // Use streaming discovery for progressive updates
        mintDiscoveryInstance.discoverStreaming({
            onMint: function(mint) {
                // Update or add mint to the list
                var existingIndex = discoveredMints.findIndex(function(m) { return m.url === mint.url; });
                if (existingIndex >= 0) {
                    discoveredMints[existingIndex] = mint;
                } else {
                    discoveredMints.push(mint);
                }
                // Sort by reviewsCount desc, then averageRating desc
                discoveredMints.sort(function(a, b) {
                    var countDiff = (b.reviewsCount || 0) - (a.reviewsCount || 0);
                    if (countDiff !== 0) return countDiff;
                    return (b.averageRating || 0) - (a.averageRating || 0);
                });
                // Hide loading spinner once we have mints
                if (discoveredMints.length > 0) {
                    loadingEl.style.display = 'none';
                }
                statusEl.textContent = 'Found ' + discoveredMints.length + ' mints...';
                renderMintList();
            },
            onProgress: function(progress) {
                if (progress.phase === 'nostr' && progress.step === 'subscribing') {
                    statusEl.textContent = 'Subscribing to Nostr relays...';
                } else if (progress.phase === 'nostr' && progress.step === 'mint-info-complete') {
                    statusEl.textContent = 'Fetching reviews...';
                } else if (progress.phase === 'http') {
                    statusEl.textContent = 'Checking mint status (' + discoveredMints.length + ' mints)...';
                } else if (progress.phase === 'done') {
                    statusEl.textContent = 'Found ' + discoveredMints.length + ' mints';
                }
            },
            onComplete: function(mints) {
                discoveredMints = mints;
                loadingEl.style.display = 'none';
                statusEl.textContent = 'Found ' + mints.length + ' mints';
                renderMintList();
            }
        }).catch(function(error) {
            loadingEl.style.display = 'none';
            statusEl.textContent = 'Error: ' + error.message;
        });
    }

    function getUnitsFromInfo(info) {
        if (!info || !info.nuts || !info.nuts[4] || !info.nuts[4].methods) return [];
        var units = info.nuts[4].methods.map(function(m) { return m.unit; }).filter(Boolean);
        return units.filter(function(u, i, arr) { return arr.indexOf(u) === i; });
    }

    function renderStars(rating) {
        if (rating === null || rating === undefined) return '---';
        var full = Math.floor(rating);
        var html = '<span style="color: #FFC107;">';
        for (var i = 0; i < full; i++) html += '\u2605';
        for (var i = full; i < 5; i++) html += '\u2606';
        html += '</span> ' + rating.toFixed(1);
        return html;
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function filterMintList() {
        renderMintList();
    }

    function renderMintList() {
        var listEl = document.getElementById('mint-discovery-list');
        var filterUnit = document.getElementById('mint-unit-filter').value;
        var searchText = document.getElementById('mint-search').value.toLowerCase().trim();

        var filtered = discoveredMints.filter(function(m) {
            if (filterUnit) {
                var units = getUnitsFromInfo(m.info);
                if (units.indexOf(filterUnit) === -1) return false;
            }
            if (searchText) {
                var name = (m.info && m.info.name) ? m.info.name.toLowerCase() : '';
                var url = m.url.toLowerCase();
                if (name.indexOf(searchText) === -1 && url.indexOf(searchText) === -1) return false;
            }
            return true;
        });

        if (filtered.length === 0) {
            listEl.innerHTML = '<p style="color: #a0aec0; text-align: center; padding: 2rem;">No mints found matching your criteria</p>';
            return;
        }

        var html = filtered.map(function(m) {
            var name = (m.info && m.info.name) ? m.info.name : 'Unknown Mint';
            var isOnline = !m.error && m.info;
            var units = getUnitsFromInfo(m.info);

            return '<div style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">' +
                '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">' +
                    '<div style="font-size: 0.9rem;">' + renderStars(m.averageRating) +
                        ' <span style="color: #a0aec0; font-size: 0.8rem;">(' + (m.reviewsCount || 0) + ' reviews)</span></div>' +
                    '<span style="font-size: 0.8rem; color: ' + (isOnline ? '#48bb78' : '#e53e3e') + ';">' +
                        (isOnline ? '\u25CF Online' : '\u25CB Offline') + '</span>' +
                '</div>' +
                '<h4 style="margin: 0 0 0.25rem 0; font-size: 1rem;">' + escapeHtml(name) + '</h4>' +
                '<p style="font-size: 0.8rem; color: #a0aec0; margin: 0 0 0.5rem 0; word-break: break-all;">' + escapeHtml(m.url) + '</p>' +
                '<div style="font-size: 0.8rem; color: #a0aec0; margin-bottom: 0.75rem;">' +
                    (units.length > 0 ? units.map(function(u) { return u.toUpperCase(); }).join(' \u2022 ') : 'Unknown units') +
                '</div>' +
                '<button type="button" class="btn" style="width: 100%;" onclick="selectDiscoveredMint(\'' + escapeHtml(m.url) + '\')">Select</button>' +
            '</div>';
        }).join('');

        listEl.innerHTML = html;

        var statusEl = document.getElementById('mint-discovery-status');
        statusEl.textContent = 'Showing ' + filtered.length + ' of ' + discoveredMints.length + ' mints';

        // Update button states based on disclaimer checkbox
        updateSelectButtons();
    }

    function selectDiscoveredMint(url) {
        document.getElementById('mint_url').value = url;
        closeMintDiscovery();
    }

    // Expiry testing
    var expiryTestTimeout = null;

    function testMintExpirySetup() {
        var unitSelect = document.getElementById('mint_unit');
        var unit = unitSelect ? unitSelect.value : '';
        var mintUrlEl = document.getElementById('mint_url');

        // For setup.php, the mint URL is in a hidden input when unit selection is shown
        var mintUrl = '<?= htmlspecialchars($mintUrlTemp ?? '') ?>';

        if (!unit || !mintUrl) return;

        var statusEl = document.getElementById('expiry-test-status');
        var warningEl = document.getElementById('expiry-warning');
        var continueBtn = document.getElementById('continue-btn');

        statusEl.style.display = 'block';
        statusEl.style.background = 'rgba(247, 147, 26, 0.1)';
        statusEl.style.border = '1px solid rgba(247, 147, 26, 0.3)';
        statusEl.innerHTML = '<span style="color: #f7931a;">Testing invoice expiry...</span>';
        warningEl.style.display = 'none';

        // Clear any previous timeout
        if (expiryTestTimeout) clearTimeout(expiryTestTimeout);

        expiryTestTimeout = setTimeout(function() {
            var formData = new FormData();
            formData.append('action', 'test_mint_expiry');
            formData.append('mint_url', mintUrl);
            formData.append('unit', unit);
            <?php if ($mode === 'add_store'): ?>
            formData.append('mode', 'add_store');
            <?php endif; ?>

            fetch(<?= json_encode(Urls::setup()) ?>, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (result.success) {
                    if (result.warning) {
                        statusEl.style.display = 'none';
                        warningEl.style.display = 'block';
                        document.getElementById('expiry-warning-message').textContent = result.message;

                        // Check acknowledgment state for button
                        var ackCheckbox = document.getElementById('expiry-acknowledged');
                        ackCheckbox.onchange = function() {
                            continueBtn.disabled = !ackCheckbox.checked;
                        };
                        continueBtn.disabled = true;
                    } else {
                        var mins = Math.round(result.expiry_seconds / 60);
                        statusEl.style.background = 'rgba(72, 187, 120, 0.1)';
                        statusEl.style.border = '1px solid rgba(72, 187, 120, 0.3)';
                        statusEl.innerHTML = '<span style="color: #68d391;">Invoice expiry: ' + mins + ' minutes (OK)</span>';
                        continueBtn.disabled = false;
                    }
                } else {
                    statusEl.style.background = 'rgba(229, 62, 62, 0.1)';
                    statusEl.style.border = '1px solid rgba(229, 62, 62, 0.3)';
                    statusEl.innerHTML = '<span style="color: #fc8181;">Could not test expiry: ' + escapeHtml(result.error || 'Unknown error') + '</span>';
                    continueBtn.disabled = false;
                }
            })
            .catch(function(error) {
                statusEl.style.background = 'rgba(229, 62, 62, 0.1)';
                statusEl.style.border = '1px solid rgba(229, 62, 62, 0.3)';
                statusEl.innerHTML = '<span style="color: #fc8181;">Network error testing expiry</span>';
                continueBtn.disabled = false;
            });
        }, 300);
    }

    // Auto-trigger expiry test if a unit is already selected on page load
    var unitSelect = document.getElementById('mint_unit');
    if (unitSelect && unitSelect.value) {
        testMintExpirySetup();
    }

    // Show spinner when restoring from existing seed
    document.addEventListener('DOMContentLoaded', function() {
        var existingSeedForm = document.getElementById('existing_seed');
        if (existingSeedForm) {
            var form = existingSeedForm.closest('form');
            if (form) {
                form.addEventListener('submit', function() {
                    var spinner = document.getElementById('spinnerOverlay');
                    if (spinner) {
                        spinner.classList.add('active');
                    }
                });
            }
        }
    });
    </script>
</body>
</html>
