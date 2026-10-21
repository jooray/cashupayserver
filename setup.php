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

require_once __DIR__ . '/includes/entrypoint_guard.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/urls.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/setup_ownership.php';

// Initialize session early - needed for storing temp data during setup
Auth::initSession();
$csrfToken = Auth::generateCsrfToken();
$isWordPressSetup = Urls::isWordPress();

// Get mode parameter
$mode = $_GET['mode'] ?? $_POST['mode'] ?? '';
$mode = is_string($mode) ? $mode : '';

// A completed installation is administration, not setup: it needs a real session.
// The step the request claims must not change that — `step=7` used to skip this gate
// entirely, which let an anonymous POST render the page that prints the cron key.
$setupComplete = Database::isInitialized() && Config::isSetupComplete();
if ($setupComplete && !Auth::isLoggedIn()) {
    header('Location: ' . Urls::admin());
    exit;
}
if ($setupComplete && $mode !== 'add_store' && ($_POST['step'] ?? '') !== '7') {
    header('Location: ' . Urls::admin());
    exit;
}
// add_store adds a store to a finished installation. Before setup is complete there is
// nothing to add to, so the normal wizard runs (and its password step is not skipped).
if (!$setupComplete && $mode === 'add_store') {
    $mode = '';
}

// Initialize database if needed
if (!Database::isInitialized()) {
    Database::initialize();
}

// Until setup completes, this page is reachable by anyone who can reach the server, and
// CSRF only proves the visitor owns the session they were just handed. The first browser
// to *start* setup (the step-1 POST) claims the installation; a plain GET claims nothing,
// so a link-preview bot or crawler cannot lock the merchant out. Any other browser has to
// present the recovery code from the data directory — in a POST body only, never the URL.
$setupTokenRequired = !$setupComplete && !$isWordPressSetup && !Setup::ownershipVerified();
$recoveryError = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$postCsrfValid = $isPost && Auth::validateCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');
if ($setupTokenRequired && $postCsrfValid && is_string($_POST['setup_token'] ?? null)
    && trim($_POST['setup_token']) !== '') {
    if (Setup::verifyOwnershipToken($_POST['setup_token'])) {
        $setupTokenRequired = false;
    } else {
        $recoveryError = Setup::$lastRecoveryError === 'rate_limited'
            ? 'Too many attempts. Wait a minute, then type the recovery code again.'
            : 'That recovery code is not right. Check the file again and type the code exactly as it appears.';
    }
}
if ($setupTokenRequired && $postCsrfValid && ($_POST['step'] ?? '') === '1' && !Setup::isClaimed()) {
    // The visitor pressed "Continue" on the first step: that is the deliberate act that
    // claims an unclaimed installation.
    $setupTokenRequired = !Setup::claim();
}
// Shown only when another browser actually holds the claim; an unclaimed install shows
// the normal first step.
$claimedElsewhere = $setupTokenRequired && Setup::isClaimed();

/** Read a scalar POST/GET field; an array-shaped value (`x[]=`) counts as absent. */
function setupField(string $name, string $default = ''): string {
    $value = $_POST[$name] ?? $_GET[$name] ?? $default;
    return is_string($value) ? $value : $default;
}

// Handle form submissions. The wizard remembers where it is, so a reload, bookmark or new
// tab resumes instead of restarting at step 1 (SPB-UI-005).
$stepKey = $mode === 'add_store' ? 'setup_step_add_store' : 'setup_step';
$requestedStep = $_POST['step'] ?? $_GET['step'] ?? null;
if (is_string($requestedStep) && ctype_digit($requestedStep)) {
    $step = (int)$requestedStep;
} elseif (isset($_SESSION[$stepKey]) && is_int($_SESSION[$stepKey])) {
    $step = $_SESSION[$stepKey];
} else {
    // For add_store mode, start at step 4 (store creation)
    $step = $mode === 'add_store' ? 4 : 1;
}
if (!in_array($step, [1, 2, 4, 5, 6, 7], true)) {
    $step = $mode === 'add_store' ? 4 : 1;
}
// Why the requested step was replaced, shown instead of running the replacement step's
// handler with fields that were posted for another one.
$stepRefusal = null;
// A finished installation (and add_store) only runs the store steps. Step 2 sets the admin
// password with no current-password check and no session revocation; after setup the
// password changes only through the dashboard (Auth::changeAdminPassword) (C4/H-1).
if (($mode === 'add_store' || $setupComplete) && $step < 4) {
    $step = 4;
    $stepRefusal = 'That step is only part of the first setup. To change the admin password,'
        . ' open the dashboard and go to Settings.';
}
// Standalone setup must not reach the store steps (and above all must not finish) without
// an admin password: the result would be an installation nobody can log in to, with the
// recovery code already deleted (L-6).
if (!$setupComplete && !$isWordPressSetup && $step >= 4 && Config::getAdminPasswordHash() === null) {
    $step = 2;
    $stepRefusal = 'Choose an admin password first. Without one, nobody could log in to this server after setup.';
}
// A visitor who does not hold the installation only ever sees the first step.
if ($setupTokenRequired) {
    $step = 1;
    $stepRefusal = null;
}
// Set when the address the browser reports for this server is on another host than the
// one the request came to; step 1 then asks for an explicit confirmation (M-2).
$baseUrlOtherHost = null;

$error = null;
$success = null;

// "Move my data to a safe place": the fix for a downloadable database that needs no
// server knowledge. Anyone but a browser another visitor's claim locks out may use it
// on a fresh install; it only ever makes the data less reachable.
if ($isPost && $postCsrfValid && !$claimedElsewhere && setupField('action') === 'relocate_data') {
    if (Security::checkRateLimit('setup_relocate', Security::getClientIp(), 5)) {
        session_write_close(); // the session file moves too; write it before copying
        $moved = Database::relocateOutsideWebroot();
        if (!$moved['ok']) {
            Auth::initSession();
            $_SESSION['setup_relocate_error'] = $moved['error'];
        }
    }
    header('Location: ' . Urls::setup());
    exit;
}
$relocateError = $_SESSION['setup_relocate_error'] ?? null;
unset($_SESSION['setup_relocate_error']);

if ($isPost) {
    if (!$postCsrfValid) {
        http_response_code(403);
        $error = 'Your setup session expired. Reload this page and try again.';
    }

    if ($error === null && $setupTokenRequired) {
        http_response_code(403);
        $error = $recoveryError ?? ($claimedElsewhere
            ? 'Enter the recovery code to continue setting up this server.'
            : 'Please start from the first step.');
    }

    if ($error !== null) {
        // Render the current setup step with the validation error.
    // Handle AJAX action for expiry testing
    } elseif (setupField('action') === 'test_mint_expiry') {
        header('Content-Type: application/json');
        require_once __DIR__ . '/includes/mint_helpers.php';
        $mintUrl = trim(setupField('mint_url'));
        $unit = setupField('unit', 'sat');

        // This makes the server fetch an address the visitor typed: throttle it and
        // refuse addresses inside the server's own network (SPB-SSRF-001).
        if (!Security::checkRateLimit('setup_mint_test', Security::getClientIp(), 10)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many tests in a row. Wait a minute and try again.']);
        } elseif ($mintUrl === '') {
            echo json_encode(['success' => false, 'error' => 'Mint URL required']);
        } else {
            try {
                $mintUrl = setupCheckMintUrl($mintUrl);
                $unit = Config::validUnit($unit);
                echo json_encode(MintHelpers::testExpiry($mintUrl, $unit));
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        exit;
    }

    // Handle AJAX action for saving URL mode
    if ($error === null && setupField('action') === 'save_url_mode') {
        header('Content-Type: application/json');
        $mode = setupField('mode', 'router');
        if (in_array($mode, ['direct', 'router'])) {
            Config::set('url_mode', $mode);
            echo json_encode(['success' => true, 'mode' => $mode]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid mode']);
        }
        exit;
    }

    if ($error === null && $stepRefusal !== null) {
        $error = $stepRefusal;
    }

    if ($error === null) try {
        switch ($step) {
            case 1: // Welcome + Security (merged step)
                // The server checks for itself whether the database can be downloaded.
                // A proven exposure blocks setup; an inconclusive check (the server cannot
                // reach its own address, e.g. behind some proxies) falls back to the
                // operator's confirmation.
                $probe = setupProbeDataExposure();
                if ($probe['status'] === 'exposed') {
                    throw new Exception('Your database can be downloaded from the internet ('
                        . implode(', ', $probe['exposed'])
                        . '). Anyone could take the money it holds. Fix this before continuing; the steps are below.');
                }
                $securityPassed = $_POST['security_acknowledged'] ?? false;
                if (!$securityPassed && !in_array($probe['status'], ['protected', 'outside'], true)) {
                    throw new Exception('Please verify that your database is protected');
                }
                // Pin the canonical origin now, while a verified operator is here. Without
                // it the base URL is derived from HTTP_HOST, and background self-requests
                // that carry the internal key have no trusted destination.
                $pinBaseUrl = null;
                if (!Config::get('base_url')) {
                    $detectedBaseUrl = trim(setupField('detected_base_url'));
                    if ($detectedBaseUrl !== '' && Security::isSafeAppBaseUrl($detectedBaseUrl)) {
                        // An address on another host would receive the background key and
                        // every payment link. Ask first, as the dashboard's save_base_url does.
                        $requestHost = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
                        $pinnedHost = strtolower((string)parse_url($detectedBaseUrl, PHP_URL_HOST));
                        if ($requestHost !== '' && $pinnedHost !== $requestHost && setupField('confirm_other_host') !== '1') {
                            $baseUrlOtherHost = ['pinned' => $pinnedHost, 'request' => $requestHost];
                            throw new Exception("Your browser shows this server as {$pinnedHost}, but the server"
                                . " received the request for {$requestHost}. Payment links and background tasks"
                                . " will use {$pinnedHost}. If that is really the address of this server (for"
                                . ' example behind a proxy), tick the box below and press the button again.');
                        }
                        $pinBaseUrl = rtrim($detectedBaseUrl, '/');
                    }
                }
                $detectedUrlMode = setupField('detected_url_mode');
                if (in_array($detectedUrlMode, ['direct', 'router'], true)) {
                    Config::set('url_mode', $detectedUrlMode);
                }
                if ($pinBaseUrl !== null) {
                    Config::set('base_url', $pinBaseUrl);
                }
                // Go to password step (standalone) or create store step (WordPress)
                $step = Urls::isWordPress() ? 4 : 2;
                break;

            case 2: // Password setup
                // After setup the password changes only through the dashboard, which checks
                // the current one and ends every other session (C4/H-1).
                if ($setupComplete && Config::getAdminPasswordHash() !== null) {
                    throw new Exception('The admin password is already set. To change it, open the dashboard and go to Settings.');
                }
                $password = setupField('password');
                $confirm = setupField('confirm_password');

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
                $storeName = trim(setupField('store_name', 'My Store'));
                if ($storeName === '') {
                    throw new Exception('Please give your store a name.');
                }

                // Check for duplicate store name
                $existingStore = Database::fetchOne(
                    "SELECT id, mint_url, seed_phrase FROM stores WHERE LOWER(name) = LOWER(?)",
                    [$storeName]
                );
                if ($existingStore) {
                    // The operator's own half-finished store (a reload or a new tab): continue
                    // it rather than dead-ending on "already exists" (SPB-UI-005). Only a store
                    // this session started: one left by an earlier setup claimant comes with
                    // that claimant's mint (M-3); a new claim deletes those anyway. A store
                    // with a wallet seed is finished and is never taken over from here.
                    $createdHere = in_array($existingStore['id'], $_SESSION['setup_created_stores'] ?? [], true)
                        || ($_SESSION['setup_store_id'] ?? null) === $existingStore['id'];
                    $unfinished = empty($existingStore['seed_phrase']);
                    if ($unfinished && $createdHere) {
                        $_SESSION['setup_store_id'] = $existingStore['id'];
                        $step = empty($existingStore['mint_url']) ? 5 : 6;
                        break;
                    }
                    throw new Exception('A store with this name already exists. Please choose a different name.');
                }

                // Create store with just a name (mint and seed will be added in later steps)
                $storeId = Database::generateId('store');
                Database::insert('stores', [
                    'id' => $storeId,
                    'name' => $storeName,
                    'wallet_account_id' => Database::generateWalletAccountId(),
                    'created_at' => Database::timestamp(),
                ]);

                $_SESSION['setup_store_id'] = $storeId;
                $_SESSION['setup_created_stores'][] = $storeId;
                $step = 5;
                break;

            case 5: // Mint configuration
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please go back and create a store first.');
                }

                $mintUrl = rtrim(trim(setupField('mint_url')), '/');
                $mintUnit = setupField('mint_unit');

                if (empty($mintUrl)) {
                    throw new Exception('Mint URL is required');
                }
                // Reject non-http(s) schemes (file://, etc.) and addresses inside this
                // server's own network: the server is about to fetch it (SPB-SSRF-001).
                if (!Security::checkRateLimit('setup_mint_connect', Security::getClientIp(), 10)) {
                    throw new Exception('Too many attempts in a row. Wait a minute and try again.');
                }
                $mintUrl = setupCheckMintUrl($mintUrl);

                // Fetch available units from mint
                require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                try {
                    $units = \Cashu\Wallet::getSupportedUnits($mintUrl);
                } catch (Exception $e) {
                    throw new Exception('Failed to connect to mint: ' . $e->getMessage());
                }
                // A unit name comes from the mint (a stranger) and ends up in admin pages:
                // only plain short codes are listed or stored (SPB-UI-001).
                $units = array_filter($units, fn($u) => is_string($u) && preg_match('/^[a-z0-9]{1,16}$/i', $u),
                    ARRAY_FILTER_USE_KEY);
                $units = array_combine(array_map('strtolower', array_keys($units)), array_values($units));
                if (empty($units)) {
                    throw new Exception('Could not connect to mint or no usable currency units found');
                }

                // If no unit selected yet, show selection
                if ($mintUnit === '') {
                    $_SESSION['mint_url_temp'] = $mintUrl;
                    $_SESSION['mint_units'] = array_keys($units);
                    // Stay on step 5 but show unit selector
                } else {
                    // Validate selected unit
                    $mintUnit = Config::validUnit($mintUnit);
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

                $action = setupField('action');

                if ($action === 'generate') {
                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    $mnemonic = \Cashu\Mnemonic::generate();
                    $_SESSION['temp_seed'] = $mnemonic;
                } elseif ($action === 'confirm') {
                    // Never finish a standalone setup nobody could log in to (L-6).
                    if (!$isWordPressSetup && $mode !== 'add_store' && Config::getAdminPasswordHash() === null) {
                        throw new Exception('Choose an admin password first. Without one, nobody could log in to this server after setup.');
                    }
                    if (!isset($_POST['seed_confirmed'])) {
                        throw new Exception('Please confirm you have saved your seed phrase');
                    }

                    // Determine if seed was generated or manually entered
                    $isGenerated = isset($_SESSION['temp_seed']);
                    $seed = $_SESSION['temp_seed'] ?? setupField('existing_seed');
                    $seed = trim((string)$seed);

                    if (empty($seed)) {
                        throw new Exception('No seed phrase provided');
                    }

                    // Validate mnemonic
                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    if (!\Cashu\Mnemonic::validate($seed)) {
                        throw new Exception('Invalid seed phrase');
                    }

                    // Two stores on one recovery phrase hand out the same payment secrets
                    // and lose money (OPUS-018 / DS41-13). Refuse before anything is written.
                    $seedOwner = Config::storeUsingSeed($seed, $storeId);
                    if ($seedOwner !== null) {
                        throw new Exception('This recovery phrase is already used by your store "'
                            . $seedOwner['name'] . '". Each store needs its own phrase, because two stores'
                            . ' sharing one lose payments. Generate a new phrase for this store instead.');
                    }

                    // Save seed to THIS store (not global config)
                    Config::updateStore($storeId, [
                        'seed_phrase' => $seed,
                    ]);

                    require_once __DIR__ . '/includes/invoice.php';
                    try {
                        $wallet = Invoice::initializeWalletForStore($storeId, !$isGenerated);
                        if (!$isGenerated) {
                            // Bounded steps: without GMP the full scan outlasts any request.
                            // Whatever is left finishes in the background, and the store hands
                            // out no invoices it could not collect until it has.
                            $recovery = Invoice::advanceStoreRecovery($storeId, 20.0);
                            if ($recovery['error'] !== null) {
                                throw new Exception($recovery['error']);
                            }
                            $_SESSION['restore_result'] = [
                                'success' => true,
                                'complete' => $recovery['ready'],
                                'proofs_found' => $recovery['recovered']['proofs'],
                                'proofs_unspent' => $recovery['recovered']['unspent'],
                                'proofs_spent' => $recovery['recovered']['spent'],
                            ];
                        }
                    } catch (Throwable $e) {
                        error_log("Wallet initialization during setup failed: " . $e->getMessage());
                        if (!$isGenerated) {
                            $_SESSION['restore_result'] = [
                                'success' => false,
                                'error' => $e->getMessage(),
                            ];
                        }
                        throw new Exception(
                            $isGenerated
                                ? 'Could not initialize the new wallet: ' . $e->getMessage()
                                : 'Wallet restore must complete before setup can continue: ' . $e->getMessage()
                        );
                    }

                    unset($_SESSION['temp_seed']);

                    // For add_store mode, redirect back to admin with success
                    if ($mode === 'add_store') {
                        $createdStoreId = $storeId;
                        unset($_SESSION['setup_store_id'], $_SESSION[$stepKey]);
                        header('Location: ' . Urls::admin() . '?store_created=' . urlencode($createdStoreId));
                        exit;
                    }

                    Config::set('setup_complete', true);
                    Setup::clearOwnershipToken();
                    // Generate a cron key so background processing can be scheduled and the
                    // cron endpoint isn't left open. See FABLE-CASHUPAYSERVER-AUDIT (C-CRON-1).
                    if (!Config::get('cron_key')) {
                        Config::set('cron_key', bin2hex(random_bytes(16)));
                    }
                    $step = 7;
                }
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // Remember how far the wizard got, so a reload resumes here.
    if ($error === null && !$setupTokenRequired) {
        if ($step === 7) {
            unset($_SESSION[$stepKey]);
        } else {
            $_SESSION[$stepKey] = $step;
        }
    }
}

if (!$isPost && $error === null && $stepRefusal !== null) {
    $error = $stepRefusal;
}

// Clear mint session data when navigating to step 5 via GET (e.g. "Change Mint" button)
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['step'])) {
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

/**
 * Check a mint address before the server fetches it (SPB-SSRF-001).
 *
 * Normalizes it like every other mint-URL writer and refuses addresses inside the
 * server's own network (loopback, private, link-local, cloud metadata), which a visitor
 * could otherwise use to make this server probe its neighbours.
 */
function setupCheckMintUrl(string $mintUrl): string {
    require_once __DIR__ . '/includes/mint_helpers.php';
    try {
        // Shared gate (includes/mint_helpers.php); CASHUPAY_ALLOW_PRIVATE_MINTS or the
        // allow_private_mints setting opt in to a mint on the same machine or LAN.
        $mintUrl = MintHelpers::assertSafeMintUrl($mintUrl);
    } catch (InvalidArgumentException $e) {
        throw new Exception($e->getMessage());
    }
    return Config::normalizeMintUrl($mintUrl);
}

/**
 * The URL paths under which the web server might serve the data directory.
 *
 * Two mappings: filesystem path minus DOCUMENT_ROOT, and the URL space this script is
 * served from. Under an nginx/Apache `alias` or a ~userdir layout the first names the
 * wrong URL (or none at all, so the data looked "outside"), while the second is where the
 * data folder next to setup.php really is (N-1). The second is only added when the data
 * directory sits inside this script's folder: a relocated data directory has no URL there.
 *
 * @return string[] URL paths without a trailing slash, deduplicated
 */
function setupDataDirUrlPaths(): array {
    $paths = [];
    $docPath = getDataDirHttpPath();
    if ($docPath !== null) {
        $paths[] = $docPath;
    }
    $dataDir = str_replace('\\', '/', realpath(Database::getDataDir()) ?: Database::getDataDir());
    $scriptFile = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $appDir = $scriptFile !== '' ? str_replace('\\', '/', realpath(dirname($scriptFile)) ?: '') : '';
    if ($appDir !== '' && str_starts_with($dataDir, rtrim($appDir, '/') . '/')) {
        $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $paths[] = ($scriptDir === '.' ? '' : rtrim($scriptDir, '/')) . '/' . substr($dataDir, strlen(rtrim($appDir, '/')) + 1);
    }
    return array_values(array_unique(array_map(fn($p) => '/' . trim($p, '/'), $paths)));
}

/**
 * Ask this web server, from the server itself, whether the database files can be
 * downloaded: a ranged GET (some servers answer HEAD from a rule a real GET would not
 * hit) for the database and its -wal/-shm sidecars, the same probe as the dashboard's,
 * under every URL the data directory might have (setupDataDirUrlPaths()).
 *
 * Every connection is pinned to this server's own address (SERVER_ADDR:SERVER_PORT) with
 * a wildcard CONNECT_TO entry, so the Host header a visitor sends only names the virtual
 * host, never a destination. A host-keyed entry missed whenever libcurl rewrote the host
 * (numeric IPv4 forms such as 2130706433 or 127.1) and curl then connected wherever the
 * Host pointed (C9); the primary IP/port check afterwards catches any other miss.
 *
 * @return array{status: string, exposed: string[]} status is 'outside' (data has no URL),
 *         'protected' (every file under every URL refused), 'exposed' or 'inconclusive'.
 */
function setupProbeDataExposure(): array {
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    $urlPaths = setupDataDirUrlPaths();
    if ($urlPaths === []) {
        return $result = ['status' => 'outside', 'exposed' => []];
    }
    $inconclusive = ['status' => 'inconclusive', 'exposed' => []];
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);
    // PHP's built-in server handles one request at a time and cannot answer itself.
    if (PHP_SAPI === 'cli-server' || !function_exists('curl_init')
        || !preg_match('/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+)(?::(\d{1,5}))?$/', $host)
        || !filter_var($serverAddr, FILTER_VALIDATE_IP) || $serverPort <= 0
        || !Security::checkRateLimit('setup_exposure_probe', Security::getClientIp(), 10)) {
        return $result = $inconclusive;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    // Empty host and port match every URL, whatever form libcurl gives the host.
    $connectTo = '::' . (str_contains($serverAddr, ':') ? '[' . $serverAddr . ']' : $serverAddr) . ':' . $serverPort;
    $serverAddrBin = inet_pton($serverAddr);

    require_once __DIR__ . '/includes/http.php';
    $exposed = [];
    $refused = 0;
    $total = 0;
    foreach ($urlPaths as $dataPath) {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $dataPath)));
        foreach (['cashupay.sqlite', 'cashupay.sqlite-wal', 'cashupay.sqlite-shm'] as $name) {
            $total++;
            $body = '';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $scheme . '://' . $host . $encodedPath . '/' . $name,
                CURLOPT_CONNECT_TO => [$connectTo],
                CURLOPT_RANGE => '0-15',
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                // Read a few bytes and hang up, even if the server ignores the Range header.
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                    $body .= $chunk;
                    return strlen($body) >= 16 ? -1 : strlen($chunk);
                },
            ] + cashupay_curl_protocol_options());
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            $primaryPort = (int)curl_getinfo($ch, CURLINFO_PRIMARY_PORT);
            if ($code === 0) {
                continue; // no answer at all: this file was not checked
            }
            if ($primaryPort !== $serverPort || $primaryIp === '' || @inet_pton($primaryIp) !== $serverAddrBin) {
                continue; // somebody else answered: this file was not checked
            }
            if ($code >= 200 && $code < 300) {
                // A host that answers every path with a 200 page is not serving the database;
                // only the real file content counts as proof of exposure.
                $isFile = $name === 'cashupay.sqlite'
                    ? str_starts_with($body, 'SQLite format 3')
                    : (!str_contains($type, 'text/html') && !str_starts_with(ltrim($body), '<'));
                if ($isFile) {
                    $exposed[] = $name;
                } else {
                    $refused++;
                }
            } elseif (in_array($code, [401, 403, 404], true)) {
                $refused++;
            }
            // Anything else (a redirect, a server error) proves nothing either way.
        }
    }
    if ($exposed) {
        return $result = ['status' => 'exposed', 'exposed' => array_values(array_unique($exposed))];
    }
    return $result = $refused === $total ? ['status' => 'protected', 'exposed' => []] : $inconclusive;
}
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
        <?php
        // If an older shared folder may hold this shop's wallet (its site address
        // changed), say so before the wizard offers to create a new one.
        if (Urls::isWordPress() && function_exists('cashupay_foreign_data_dir_notice')) {
            cashupay_foreign_data_dir_notice();
        }
        ?>
        <div class="card">
            <div class="logo">&#9889;</div>
            <?php if ($mode === 'add_store'): ?>
                <h1>Add New Store</h1>
                <?php
                // Map internal steps 4-6 to display steps 1-3
                $displayStep = $step - 3;
                $totalDisplaySteps = 3;
                ?>
                <p class="subtitle">Step <?php echo $displayStep; ?> of <?php echo $totalDisplaySteps; ?></p>

                <div class="steps">
                    <?php for ($i = 1; $i <= $totalDisplaySteps; $i++): ?>
                        <div class="step-dot <?php echo $i < $displayStep ? 'completed' : ($i === $displayStep ? 'active' : ''); ?>"></div>
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
                <p class="subtitle">Step <?php echo $displayStep; ?> of <?php echo $totalSteps; ?></p>

                <div class="steps">
                    <?php for ($i = 1; $i <= $totalSteps; $i++): ?>
                        <div class="step-dot <?php echo $i < $displayStep ? 'completed' : ($i === $displayStep ? 'active' : ''); ?>"></div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
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
                // These are the real runtime requirements, not an approximation:
                // includes/rates.php calls bcdiv()/bcmul() unconditionally, so a
                // GMP-only host fatals on the first fiat-priced invoice; the wallet
                // library uses readonly properties (8.1) and mbstring.
                $checks = [
                    ['PHP 8.1 or newer (found ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1.0', '>=')],
                    ['cURL extension', extension_loaded('curl')],
                    ['JSON extension', extension_loaded('json')],
                    ['PDO SQLite', extension_loaded('pdo_sqlite')],
                    ['BCMath extension', extension_loaded('bcmath')],
                    ['mbstring extension', extension_loaded('mbstring')],
                ];
                $allPassed = true;
                $failedChecks = [];
                foreach ($checks as [$name, $passed]) {
                    if (!$passed) {
                        $allPassed = false;
                        $failedChecks[] = $name;
                    }
                }
                // Not required, but the pure-BCMath curve maths is far slower.
                $recommendations = extension_loaded('gmp')
                    ? []
                    : ['GMP extension — without it every cryptographic operation is much slower'];
                ?>

                <?php if ($allPassed && $recommendations): ?>
                    <div style="background: rgba(247, 147, 26, 0.08); border: 1px solid rgba(247, 147, 26, 0.3); padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                        <strong style="color: #f7931a;">Recommended</strong>
                        <ul style="margin: 0.5rem 0 0 1.25rem; color: #a0aec0; font-size: 0.9rem;">
                            <?php foreach ($recommendations as $name): ?>
                                <li><?php echo htmlspecialchars($name); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!$allPassed): ?>
                    <div class="error" style="margin-bottom: 1.5rem;">
                        <strong>Missing Requirements</strong>
                        <p style="margin-top: 0.5rem;">Please install the following before continuing:</p>
                        <ul style="margin: 0.5rem 0 0 1.25rem;">
                            <?php foreach ($failedChecks as $name): ?>
                                <li><?php echo htmlspecialchars($name); ?></li>
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

                    // Build list of URL paths to test (same logic as PHP version, but for JS).
                    // The -wal/-shm sidecars hold the newest secrets; a rule that only blocks
                    // *.sqlite leaves them downloadable. (The recovery-code file has a random
                    // name now, so there is no fixed URL to probe for it.)
                    $testPaths = [];
                    $candidateDirs = setupDataDirUrlPaths();
                    $candidateDirs[] = '/data';
                    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
                    $appDir = dirname($scriptPath);
                    if ($appDir && $appDir !== '/' && $appDir !== '.') {
                        $candidateDirs[] = $appDir . '/data';
                    }
                    foreach ($candidateDirs as $dir) {
                        foreach (['cashupay.sqlite', 'cashupay.sqlite-wal', 'cashupay.sqlite-shm'] as $file) {
                            $testPaths[] = $dir . '/' . $file;
                        }
                    }
                    // Normalize and deduplicate
                    $testPaths = array_unique(array_map(function($p) {
                        return '/' . ltrim($p, '/');
                    }, $testPaths));

                    // The server's own check decides; the browser test below is a second opinion.
                    $serverProbe = (!$setupTokenRequired || !$claimedElsewhere)
                        ? setupProbeDataExposure()
                        : ['status' => 'inconclusive', 'exposed' => []];
                    $probeSettled = in_array($serverProbe['status'], ['protected', 'outside'], true);
                    ?>

                    <?php if ($isWordPressSetup): ?>
                    <!-- WordPress: the plugin reads CASHUPAY_DATA_DIR only from wp-config.php (SPB-DEP-008) -->
                    <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <p style="margin-bottom: 0.5rem; font-size: 0.9rem;">
                            Your CashuPay data is stored in:
                            <code><?php echo htmlspecialchars(Setup::relativeDataDirForDisplay()); ?></code>
                            <?php if ($isOutsideWebroot): ?>
                                <span style="color: #48bb78;">(outside your website folder &mdash; the safest place)</span>
                            <?php endif; ?>
                        </p>
                        <?php if (!$isOutsideWebroot): ?>
                        <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.5rem;">
                            This folder is inside your website. If the test below says it can be downloaded,
                            ask your hosting provider to block it, or move it: add this line to your
                            <code>wp-config.php</code> file, above the line that says
                            <em>&ldquo;That's all, stop editing!&rdquo;</em>, with a folder outside your website:
                        </p>
                        <pre style="background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.5rem;">define('CASHUPAY_DATA_DIR', '<?php echo htmlspecialchars(rtrim(dirname(rtrim(ABSPATH, '/')), '/') . '/cashupay-data-' . substr(hash('sha256', ABSPATH), 0, 12)); ?>');</pre>
                        <p style="color: #a0aec0; font-size: 0.85rem; margin-bottom: 0;">
                            Then reload this page. Do not put this line in the plugin's own files: plugin updates replace them.
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
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
                        Current data location: <code><?php echo htmlspecialchars(Setup::relativeDataDirForDisplay()); ?></code>
                        <?php if ($isOutsideWebroot): ?>
                            <span style="color: #48bb78;">(outside web root)</span>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>

                    <!-- Server-side check (authoritative) -->
                    <div class="security-check">
                        <div class="status <?php echo $serverProbe['status'] === 'exposed' ? 'FAIL' : ($probeSettled ? 'OK' : 'WARN'); ?>"></div>
                        <div style="flex: 1;">
                            <?php if ($serverProbe['status'] === 'exposed'): ?>
                                <span>The server checked: your database CAN be downloaded</span>
                                <p style="font-size: 0.85rem; color: #fc8181; margin-top: 0.25rem;">
                                    Readable from the internet: <?php echo htmlspecialchars(implode(', ', $serverProbe['exposed'])); ?>.
                                    Setup cannot continue until this is fixed &mdash; anyone could take the money stored in it.
                                </p>
                                <?php if (!Urls::isWordPress()): ?>
                                <form method="post" style="margin-top: 0.75rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::generateCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="relocate_data">
                                    <button type="submit" class="btn" style="width: 100%;">Move my data to a safe place</button>
                                </form>
                                <p style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.5rem;">
                                    This moves the data folder next to your website folder, where the internet cannot reach it.
                                    Nothing is set up yet, so nothing can be lost.
                                </p>
                                <?php endif; ?>
                                <?php if ($relocateError !== null): ?>
                                <p style="font-size: 0.85rem; color: #fc8181; margin-top: 0.5rem;">
                                    <?php echo htmlspecialchars($relocateError); ?>
                                    Please send this to your hosting provider's support:
                                </p>
                                <textarea readonly rows="4" style="width: 100%; font-size: 0.8rem;">Hello, I installed a payment application in <?php echo htmlspecialchars((string)(realpath(dirname(Database::getDataDir())) ?: '')); ?>. Its folder "<?php echo htmlspecialchars(basename(Database::getDataDir())); ?>" must not be downloadable from the web. Could you please block web access to that folder (for nginx: a "deny all" location for it), or allow PHP to create a folder next to my website folder? Thank you.</textarea>
                                <?php endif; ?>
                            <?php elseif ($serverProbe['status'] === 'outside'): ?>
                                <span>The database is outside your website folder</span>
                                <p style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;">It cannot be downloaded from the internet.</p>
                            <?php elseif ($serverProbe['status'] === 'protected'): ?>
                                <span>The server checked: your database cannot be downloaded</span>
                                <p style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;">The database and its two helper files were all refused.</p>
                            <?php else: ?>
                                <span>The server could not check this by itself</span>
                                <p style="font-size: 0.85rem; color: #a0aec0; margin-top: 0.25rem;">
                                    Look at the browser test below and check it yourself (see &ldquo;Manual verification&rdquo;), then tick the box.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

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
                                    <a href="<?php echo htmlspecialchars($testUrl); ?>" target="_blank" rel="noopener" style="color: #63b3ed; word-break: break-all;"><?php echo htmlspecialchars($testUrl); ?></a>
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
                            <pre style="background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;">location <?php echo htmlspecialchars($dataPath); ?>/ {
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

                    <?php if ($claimedElsewhere): ?>
                    <div class="error" style="margin: 1.5rem 0;">
                        <strong>Someone has already started setting up this server</strong>
                        <p style="margin-top: 0.5rem;">
                            Setup was started from a different browser. If that was you &mdash; on
                            another device, or before you cleared your cookies &mdash; just finish
                            there and you can ignore this page.
                        </p>
                        <p style="margin-top: 0.5rem;">
                            <strong>If it wasn't you, act now.</strong> Whoever finishes setup chooses
                            the password and the wallet, so they would control your payments. Take
                            back control by entering the recovery code below.
                        </p>
                    </div>

                    <h3 style="margin: 1.5rem 0 0.5rem;">Where to find your recovery code</h3>
                    <p style="margin-bottom: 0.75rem; color: #a0aec0; font-size: 0.9rem;">
                        It is in a small text file on your server. Open your hosting file manager
                        (or the FTP program you used to upload CashuPayServer).
                        <?php echo htmlspecialchars(Setup::tokenLocationHint()); ?>
                    </p>
                    <p style="color: #718096; font-size: 0.8rem; margin-bottom: 1rem;">
                        It contains one short line, like <code>A1B2C3D4E5</code>. Copy it in below.
                        The code stops working as soon as setup is finished.
                    </p>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="step" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="detected_base_url" id="detected_base_url" value="">

                        <?php if ($claimedElsewhere): ?>
                        <div class="form-group" style="margin: 1.5rem 0;">
                            <label for="setup_token">Recovery code</label>
                            <input type="text" id="setup_token" name="setup_token" required
                                   autocomplete="off" spellcheck="false" autocapitalize="characters"
                                   placeholder="A1B2C3D4E5">
                        </div>
                        <?php endif; ?>

                        <?php if ($baseUrlOtherHost !== null): ?>
                        <div class="checkbox-group" style="margin: 1.5rem 0;">
                            <input type="checkbox" id="confirm_other_host" name="confirm_other_host" value="1">
                            <label for="confirm_other_host">
                                Yes, <?php echo htmlspecialchars($baseUrlOtherHost['pinned']); ?> is the address of this server
                            </label>
                        </div>
                        <?php endif; ?>

                        <div class="checkbox-group" style="margin: 1.5rem 0;">
                            <input type="checkbox" id="security_acknowledged" name="security_acknowledged"<?php echo $probeSettled ? ' checked' : ' required'; ?>>
                            <label for="security_acknowledged">
                                I have verified that the database is not accessible from the web
                            </label>
                        </div>

                        <script>
                            // Record the origin the operator actually reached us on, so
                            // background self-requests have a trusted destination instead of
                            // one derived from an attacker-supplied Host header.
                            (function () {
                                var field = document.getElementById('detected_base_url');
                                if (!field) return;
                                var path = window.location.pathname.replace(/\/[^\/]*$/, '');
                                field.value = window.location.origin + path;
                            })();
                        </script>

                        <button type="submit" class="btn" style="width: 100%;"<?php echo $serverProbe['status'] === 'exposed' ? ' disabled' : ''; ?>><?php echo $claimedElsewhere ? 'Take back control' : 'Start setup'; ?></button>
                    </form>

                    <script>
                    (function() {
                        const baseOrigin = <?php echo json_encode($baseOrigin); ?>;
                        const testPaths = <?php echo json_encode(array_values($testPaths)); ?>;
                        const isOutsideWebroot = <?php echo json_encode($isOutsideWebroot); ?>;

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

                            const blockedPaths = [];
                            const unknownPaths = [];

                            // Test each path with a ranged GET (some servers answer HEAD
                            // from a rule a real GET would not hit). Any 2xx carrying the
                            // file is exposure; a failed request proves nothing either way.
                            for (const path of testPaths) {
                                const url = baseOrigin + path;
                                try {
                                    const response = await fetch(url, {
                                        method: 'GET',
                                        headers: { 'Range': 'bytes=0-15' },
                                        mode: 'same-origin',
                                        cache: 'no-store',
                                        redirect: 'manual'
                                    });
                                    let status = response.status;
                                    if (response.ok) {
                                        // A host that answers every path with a 200 page is not
                                        // serving the file: only the real content counts.
                                        const type = (response.headers.get('content-type') || '').toLowerCase();
                                        const head = new Uint8Array((await response.arrayBuffer()).slice(0, 16));
                                        const text = String.fromCharCode.apply(null, head);
                                        const isFile = path.endsWith('.sqlite')
                                            ? text.startsWith('SQLite format 3')
                                            : !type.includes('text/html') && !text.trimStart().startsWith('<');
                                        if (isFile) {
                                            worstStatus = 'FAIL';
                                            exposedPaths.push(path);
                                            status = 'exposed';
                                        }
                                    } else if (response.type === 'opaqueredirect') {
                                        status = 'redirect'; // sent elsewhere, not the file
                                    } else if (status === 0) {
                                        status = 'error';
                                    }
                                    results[path] = status;
                                    if (status === 'error') {
                                        unknownPaths.push(path);
                                    } else if (status !== 'exposed') {
                                        blockedPaths.push(path);
                                    }
                                } catch (e) {
                                    // Network error (reset, WAF, redirect elsewhere): the test
                                    // did not run, which is not the same as "protected".
                                    results[path] = 'error';
                                    unknownPaths.push(path);
                                }
                            }
                            if (worstStatus !== 'FAIL' && unknownPaths.length > 0) {
                                worstStatus = 'WARN';
                            }

                            // Hide loading, show results
                            loadingEl.style.display = 'none';
                            resultEl.style.display = 'block';

                            // Update status indicator
                            statusEl.className = 'status ' + worstStatus;

                            // Build results HTML
                            let urlsHtml = '';
                            for (const [path, status] of Object.entries(results)) {
                                if (status === 'exposed') {
                                    urlsHtml += '<div>' + escapeHtml(path) + ': <span style="color: #fc8181;">DOWNLOADABLE!</span></div>';
                                } else if (status === 'error') {
                                    urlsHtml += '<div>' + escapeHtml(path) + ': <span style="color: #ed8936;">could not check</span></div>';
                                } else {
                                    urlsHtml += '<div>' + escapeHtml(path) + ': HTTP ' + escapeHtml(String(status)) + '</div>';
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
                            } else if (worstStatus === 'WARN') {
                                messageEl.textContent = 'Could not verify - some test requests failed';
                                detailsEl.textContent = 'This does not mean the database is protected. Check it yourself with the link under "Manual verification".';
                            } else {
                                messageEl.textContent = 'Data directory is protected';
                                detailsEl.textContent = 'All tested addresses refused to send the database files.';
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

                            const baseUrl = <?php echo json_encode(Urls::siteBase()); ?>;
                            const setupUrl = <?php echo json_encode(Urls::setup()); ?>;

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

                            // Submit the detected mode with Step 1, after ownership verification.
                            if (selectedMode) {
                                const setupForm = document.querySelector('form input[name="step"][value="1"]')?.form;
                                if (setupForm) {
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'detected_url_mode';
                                    input.value = selectedMode;
                                    setupForm.appendChild(input);
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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

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
                <p style="margin-bottom: 1.5rem;"><?php echo $mode === 'add_store' ? 'Create a new store. You\'ll configure its mint and wallet next.' : 'Create your first store. You\'ll configure its mint and wallet next.'; ?></p>

                <form method="post">
                    <input type="hidden" name="step" value="4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                    <a href="<?php echo htmlspecialchars(Urls::admin()); ?>" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem; text-align: center;">Cancel</a>
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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <?php if (!empty($mintUnits)): ?>
                        <!-- Phase 2: Unit selection (shown after URL submitted) -->
                        <input type="hidden" name="mint_url" value="<?php echo htmlspecialchars($mintUrlTemp); ?>">

                        <div style="background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                            <p style="font-size: 0.9rem; color: #68d391;">Connected to mint:</p>
                            <code style="word-break: break-all;"><?php echo htmlspecialchars($mintUrlTemp); ?></code>
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
                                    <option value="<?php echo htmlspecialchars($unit); ?>"<?php echo $selected; ?>><?php echo htmlspecialchars($displayName); ?></option>
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
                            <a href="?step=5<?php echo $mode === 'add_store' ? '&mode=add_store' : ''; ?>" class="btn btn-secondary" style="text-align: center;">Change Mint</a>
                            <button type="submit" class="btn" id="continue-btn">Continue</button>
                        </div>
                    <?php else: ?>
                        <!-- Phase 1: Enter mint URL -->
                        <div class="form-group">
                            <label for="mint_url">Mint URL</label>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="url" id="mint_url" name="mint_url"
                                       placeholder="https://..."
                                       value="<?php echo htmlspecialchars(setupField('mint_url')); ?>"
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

                    <div class="seed-display"><?php echo htmlspecialchars($tempSeed); ?></div>

                    <form method="post">
                        <input type="hidden" name="step" value="6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="confirm">
                        <?php if ($mode === 'add_store'): ?>
                            <input type="hidden" name="mode" value="add_store">
                        <?php endif; ?>

                        <div class="checkbox-group" style="margin-bottom: 1.5rem;">
                            <input type="checkbox" id="seed_confirmed" name="seed_confirmed" required>
                            <label for="seed_confirmed">I have written down my seed phrase and stored it safely</label>
                        </div>

                        <button type="submit" class="btn" style="width: 100%;"><?php echo $mode === 'add_store' ? 'Create Store' : 'Complete Setup'; ?></button>
                    </form>
                <?php else: ?>
                    <p style="margin-bottom: 1.5rem;">
                        Generate a new seed phrase for this store's wallet, or restore from an existing seed.
                    </p>

                    <form method="post" style="margin-bottom: 1rem;">
                        <input type="hidden" name="step" value="6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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

                            <button type="submit" class="btn btn-secondary" style="width: 100%;"><?php echo $mode === 'add_store' ? 'Create Store with Existing Seed' : 'Use Existing Seed'; ?></button>
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
                // Urls::cron() honours the configured URL mode (and WordPress routing);
                // hardcoding /cron.php produced a command that 404s in router mode.
                $cronEndpoint = Urls::cron();
                $cronKeyValue = (string)Config::get('cron_key', '');
                ?>
                <div style="background: rgba(247, 147, 26, 0.08); border: 1px solid rgba(247, 147, 26, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <p style="margin-bottom: 0.5rem; font-weight: 600; color: #f7931a;">Recommended: set up a cron job</p>
                    <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.5rem;">
                        Payments still settle when your payment/admin pages are visited, but a
                        1-minute cron makes settlement, auto-withdrawal and recovery reliable
                        even with no traffic. In your hosting control panel (cPanel &rarr; Cron Jobs),
                        add a job that runs every minute:
                    </p>
                    <code style="display: block; word-break: break-all; background: rgba(0,0,0,0.3); padding: 0.6rem; border-radius: 6px; font-size: 0.8rem;">* * * * * curl -s -H "X-Cron-Key: <?php echo htmlspecialchars($cronKeyValue); ?>" "<?php echo htmlspecialchars($cronEndpoint); ?>" &gt;/dev/null 2&gt;&amp;1</code>
                    <p style="color: #718096; font-size: 0.8rem; margin-top: 0.5rem;">
                        The key travels in a header, not the URL, so it does not end up in web-server
                        or CDN access logs. If your host cannot send headers, the query form
                        <code>?key=…</code> also works — keep that URL private.
                    </p>
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
                        $recoveryComplete = $restoreResult['complete'] ?? true;
                        if ($proofsFound > 0 || !$recoveryComplete): ?>
                <div style="background: rgba(72, 187, 120, 0.1); border: 1px solid rgba(72, 187, 120, 0.3); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <p style="margin-bottom: 0.25rem; font-weight: 500; color: #68d391;">Wallet Restored</p>
                    <p style="color: #a0aec0; font-size: 0.9rem;">
                        <?php if ($proofsFound > 0): ?>
                            Found <?php echo $proofsFound; ?> token(s) from previous use.
                            <?php if ($proofsUnspent > 0 || $proofsSpent > 0): ?>
                                <br><span style="font-size: 0.85rem;">
                                    <?php echo $proofsUnspent; ?> unspent (available)<?php if ($proofsSpent > 0): ?>, <?php echo $proofsSpent; ?> already spent<?php endif; ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!$recoveryComplete): ?>
                            <br>Still checking the mint for earlier payments to this recovery phrase. That
                            finishes in the background, usually within a few minutes; payments start working
                            once it is done.
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif;
                    elseif (!empty($restoreResult['error'])): ?>
                <div class="warning" style="margin-bottom: 1.5rem;">
                    <p style="margin-bottom: 0.25rem; font-weight: 500;">Wallet Restore Warning</p>
                    <p style="font-size: 0.9rem;">
                        Could not restore wallet history: <?php echo htmlspecialchars($restoreResult['error']); ?>
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
                require_once __DIR__ . '/btcpay-integration.php';
                require_once __DIR__ . '/btcpay-dependency.php';
                $btcpayPluginActive = cashupay_plugin_state(CASHUPAY_BTCPAY_SLUG)['state'] === 'active';
                $storeId = $_SESSION['setup_store_id'] ?? null;
                $wooConfigured = false;

                if ($btcpayPluginActive && $storeId) {
                    $configResult = null;

                    // Mutating WooCommerce is a state change, so it happens only on a
                    // POST whose CSRF token validated ($error is null) — it used to run
                    // during template rendering regardless.
                    if ($error === null
                        && $_SERVER['REQUEST_METHOD'] === 'POST'
                        && isset($_POST['configure_woocommerce'])) {
                        // A dedicated integration key, not the least-privilege dashboard
                        // one: the WooCommerce gateway refetches the invoice on
                        // InvoiceSettled and refuses to mark the order paid if that
                        // lookup fails, so it needs canviewinvoices as well.
                        $createdKey = Auth::createApiKey(
                            $storeId,
                            'WooCommerce (auto-configured)',
                            [
                                'btcpay.store.cancreateinvoice',
                                'btcpay.store.canviewinvoices',
                                'btcpay.store.canmodifyinvoices',
                                // Required by the gateway's own key check on settings save.
                                'btcpay.store.canviewstoresettings',
                                'btcpay.store.webhooks.canmodifywebhooks',
                            ],
                            'woocommerce'
                        );
                        $apiKey = $createdKey['key'];

                        $configResult = cashupay_configure_btcpay_plugin($storeId, $apiKey);
                        $wooConfigured = $configResult['success'] ?? false;
                        if (!$wooConfigured) {
                            // The guard refused (a real BTCPay Server is configured), so the
                            // key just minted would otherwise stay live and invisible.
                            Auth::deleteApiKey((string)($createdKey['id'] ?? ''));
                        }
                    }
                }
                ?>
                <div style="background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 0.75rem;">WooCommerce Integration</h3>

                    <?php if (!$btcpayPluginActive): $btcpayStep = cashupay_btcpay_next_step(); ?>
                        <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 0.75rem;">
                            To take payments in your WooCommerce shop, CashuPay needs the free
                            <strong>"BTCPay for WooCommerce"</strong> plugin.
                            <?php echo htmlspecialchars($btcpayStep['why'] ?? ''); ?>
                        </p>
                        <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 1rem;">
                            You can finish setup first. Your WordPress dashboard will show the next step until your shop is connected.
                        </p>
                        <?php if ($btcpayStep && $btcpayStep['url'] !== null): ?>
                        <a href="<?php echo htmlspecialchars($btcpayStep['url']); ?>" class="btn btn-secondary" style="display: inline-block;"<?php echo $btcpayStep['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
                            <?php echo htmlspecialchars($btcpayStep['label']); ?>
                        </a>
                        <?php endif; ?>
                    <?php elseif ($wooConfigured): ?>
                        <div class="success">
                            WooCommerce has been configured to use CashuPay for payments.
                        </div>
                    <?php elseif (isset($configResult) && !$configResult['success']): ?>
                        <div class="warning">
                            <strong>Cannot auto-configure:</strong>
                            <?php echo htmlspecialchars($configResult['message']); ?>
                        </div>
                        <p style="color: #a0aec0; font-size: 0.9rem; margin-top: 0.75rem;">
                            Disconnect your existing BTCPay Server via WooCommerce &rarr; Settings &rarr; Payments &rarr; BTCPay, then return here.
                        </p>
                        <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=btcpay_greenfield'); ?>" class="btn btn-secondary" style="display: inline-block; margin-top: 0.5rem;">
                            Go to BTCPay Settings
                        </a>
                    <?php else: ?>
                        <?php if (cashupay_is_real_btcpay_configured()): ?>
                            <div class="warning">
                                <strong>Existing BTCPay Server detected:</strong>
                                <code style="display: block; margin-top: 0.5rem; word-break: break-all;"><?php echo htmlspecialchars(get_option('btcpay_gf_url', '')); ?></code>
                            </div>
                            <p style="color: #a0aec0; font-size: 0.9rem; margin: 0.75rem 0;">
                                A real BTCPay Server is configured. To use CashuPay instead, disconnect it first via WooCommerce settings.
                            </p>
                            <div class="btn-group">
                                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=btcpay_greenfield'); ?>" class="btn btn-secondary" style="text-align: center;">Go to BTCPay Settings</a>
                                <a href="<?php echo admin_url('admin.php?page=cashupay'); ?>" class="btn" style="text-align: center;">Skip</a>
                            </div>
                        <?php else: ?>
                            <p style="color: #a0aec0; font-size: 0.9rem; margin-bottom: 1rem;">
                                The BTCPay WooCommerce plugin is active. Click below to auto-configure it to use CashuPay.
                            </p>
                            <form method="post">
                                <input type="hidden" name="step" value="7">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                        <?php echo htmlspecialchars($serverUrl); ?>
                    </code>
                    <p style="color: #a0aec0; font-size: 0.8rem; margin-top: 0.5rem;">
                        Enter this URL in your e-commerce plugin's BTCPay Server settings
                    </p>
                    <p style="color: #68d391; font-size: 0.8rem; margin-top: 0.25rem;"><?php echo htmlspecialchars($urlModeLabel); ?></p>
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
                    <a id="test-pairing-link" href="<?php echo htmlspecialchars($pairingUrl); ?>" class="btn btn-secondary" style="display: inline-block; font-size: 0.9rem; padding: 0.5rem 1rem;">
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
                                <?php echo $urlMode; ?>: OK (detected in Step 1)
                            <?php endif; ?>
                        </div>
                    </div>
                </details>

                <a href="<?php echo Urls::isWordPress() ? admin_url('admin.php?page=cashupay') : Urls::admin(); ?>" class="btn" style="width: 100%; text-align: center; display: block;">
                    Go to CashuPay Admin
                </a>
                <?php if (Urls::isWordPress()): ?>
                <a href="<?php echo admin_url(); ?>" class="btn btn-secondary" style="width: 100%; text-align: center; display: block; margin-top: 0.5rem;">
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

    <script src="<?php echo htmlspecialchars(Urls::asset('js/mint-discovery.bundle.js')); ?>"></script>
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
        if (!info || !info.nuts || !info.nuts[4] || !Array.isArray(info.nuts[4].methods)) return [];
        // Mint-supplied; keep only short plain strings so a "unit" cannot smuggle markup.
        var units = info.nuts[4].methods
            .map(function(m) { return m && m.unit; })
            .filter(function(u) { return typeof u === 'string' && /^[a-z0-9]{1,16}$/i.test(u); });
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

    /**
     * Build the discovery list as DOM nodes.
     *
     * Every field here is announced by a stranger over Nostr, so none of it may reach
     * innerHTML. Uppercasing a unit does not sanitize it, and an HTML-escaped URL
     * interpolated into an inline onclick is still inside a JavaScript string.
     */
    function renderMintList() {
        var listEl = document.getElementById('mint-discovery-list');
        var filterUnit = document.getElementById('mint-unit-filter').value;
        var searchText = document.getElementById('mint-search').value.toLowerCase().trim();

        var filtered = discoveredMints.filter(function(m) {
            if (!m || typeof m.url !== 'string') return false;
            if (filterUnit) {
                var units = getUnitsFromInfo(m.info);
                if (units.indexOf(filterUnit) === -1) return false;
            }
            if (searchText) {
                var name = (m.info && typeof m.info.name === 'string') ? m.info.name.toLowerCase() : '';
                var url = m.url.toLowerCase();
                if (name.indexOf(searchText) === -1 && url.indexOf(searchText) === -1) return false;
            }
            return true;
        });

        listEl.textContent = '';

        if (filtered.length === 0) {
            var empty = document.createElement('p');
            empty.style.cssText = 'color: #a0aec0; text-align: center; padding: 2rem;';
            empty.textContent = 'No mints found matching your criteria';
            listEl.appendChild(empty);
            return;
        }

        filtered.forEach(function(m) {
            var name = (m.info && typeof m.info.name === 'string') ? m.info.name : 'Unknown Mint';
            var isOnline = !m.error && m.info;
            var units = getUnitsFromInfo(m.info);

            var card = document.createElement('div');
            card.style.cssText = 'background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;';

            var header = document.createElement('div');
            header.style.cssText = 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;';

            var rating = document.createElement('div');
            rating.style.fontSize = '0.9rem';
            // Our own markup around a numeric rating, never mint-supplied text.
            rating.innerHTML = renderStars(m.averageRating);
            var reviews = document.createElement('span');
            reviews.style.cssText = 'color: #a0aec0; font-size: 0.8rem;';
            reviews.textContent = ' (' + (Number(m.reviewsCount) || 0) + ' reviews)';
            rating.appendChild(reviews);

            var status = document.createElement('span');
            status.style.cssText = 'font-size: 0.8rem; color: ' + (isOnline ? '#48bb78' : '#e53e3e') + ';';
            status.textContent = isOnline ? '\u25CF Online' : '\u25CB Offline';

            header.appendChild(rating);
            header.appendChild(status);

            var title = document.createElement('h4');
            title.style.cssText = 'margin: 0 0 0.25rem 0; font-size: 1rem;';
            title.textContent = name;

            var url = document.createElement('p');
            url.style.cssText = 'font-size: 0.8rem; color: #a0aec0; margin: 0 0 0.5rem 0; word-break: break-all;';
            url.textContent = m.url;

            var unitsEl = document.createElement('div');
            unitsEl.style.cssText = 'font-size: 0.8rem; color: #a0aec0; margin-bottom: 0.75rem;';
            unitsEl.textContent = units.length > 0
                ? units.map(function(u) { return String(u).toUpperCase(); }).join(' \u2022 ')
                : 'Unknown units';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn';
            button.style.width = '100%';
            button.textContent = 'Select';
            button.addEventListener('click', function() { selectDiscoveredMint(m.url); });

            card.appendChild(header);
            card.appendChild(title);
            card.appendChild(url);
            card.appendChild(unitsEl);
            card.appendChild(button);
            listEl.appendChild(card);
        });

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
        var mintUrl = '<?php echo htmlspecialchars($mintUrlTemp ?? ''); ?>';

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
             formData.append('csrf_token', <?php echo json_encode($csrfToken); ?>);
            <?php if ($mode === 'add_store'): ?>
            formData.append('mode', 'add_store');
            <?php endif; ?>

            fetch(<?php echo json_encode(Urls::setup()); ?>, {
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
