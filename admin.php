<?php
/**
 * CashuPayServer - Admin Interface
 *
 * Modern PWA admin dashboard with PIN access.
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/lightning_address.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/urls.php';

use Cashu\ProofState;

// Check setup
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    header('Location: ' . Urls::setup());
    exit;
}

// C1: Trigger background sync if needed (logged in users only)
// NOTE: Auto-melt is handled exclusively by cron.php via Background::trigger()
// to prevent race conditions from concurrent melt attempts
Auth::initSession();
if (Auth::isLoggedIn() && Background::shouldSync()) {
    Background::trigger();
}

/**
 * H5: Check if data directory is protected from HTTP access
 */
function checkDataDirectoryProtection(): ?string {
    $baseUrl = Config::getBaseUrl();
    $testUrl = rtrim($baseUrl, '/') . '/data/cashupay.sqlite';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Note: curl_close() not needed since PHP 8.0 - handle auto-closes

    if ($httpCode === 200) {
        return 'WARNING: Your database may be exposed via HTTP! Check server configuration.';
    }

    return null;
}

// Handle API-style requests from the SPA
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    // Verify session for API requests
    Auth::initSession();
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    $action = $_GET['api'];

    switch ($action) {
        case 'dashboard':
            $storeId = $_GET['store_id'] ?? null;

            // Get all stores for selector
            $stores = Database::fetchAll("SELECT * FROM stores ORDER BY created_at DESC");

            // Ensure each store has an internal API key
            foreach ($stores as &$store) {
                $store['internalApiKey'] = Auth::getOrCreateInternalApiKey($store['id']);
                $store['isConfigured'] = Config::isStoreConfigured($store['id']);
            }
            unset($store);

            // If no store selected, return stores list only
            if (!$storeId) {
                echo json_encode([
                    'stores' => $stores,
                    'noStoreSelected' => true,
                ]);
                break;
            }

            // Verify store exists
            if (!Config::getStore($storeId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Store not found']);
                break;
            }

            // Check if store is configured
            $storeConfigured = Config::isStoreConfigured($storeId);
            $balance = 0;
            $swapFee = 0;
            $exportAvailable = 0;
            $mintUnit = Config::getStoreMintUnit($storeId);

            // Dashboard uses LOCAL balance (no mint contact) for fast loading
            // The "balanceCached" flag tells UI to show refresh button
            $balanceCached = false;

            if ($storeConfigured) {
                // Dashboard uses offline-first balance (no mint contact)
                // This ensures dashboard loads even when mint is unreachable
                // User can click "Refresh" to contact mint and verify proof states
                $balance = Invoice::getBalance($storeId);
                $balanceCached = true;

                // When using offline balance, set exportAvailable = balance
                // This allows Max button to work even without mint contact
                // (no fee deduction since we can't calculate swap fee without mint)
                $exportAvailable = $balance;
            }

            // Get invoices for this store
            $recentInvoices = Invoice::getByStore($storeId, null, 10);

            // Get store details including auto-melt settings
            $store = Config::getStore($storeId);
            $autoMelt = [
                'address' => $store['auto_melt_address'] ?? '',
                'enabled' => (bool)($store['auto_melt_enabled'] ?? 0),
                'threshold' => (int)($store['auto_melt_threshold'] ?? 2000),
            ];

            // Calculate balance in sats for fiat mints (uses cached exchange rates)
            $balanceInSats = null;
            if ($storeConfigured && $balance > 0) {
                $isFiatMint = !in_array(strtolower($mintUnit), ['sat', 'sats', 'msat']);
                if ($isFiatMint) {
                    require_once __DIR__ . '/includes/rates.php';
                    try {
                        $balanceInSats = ExchangeRates::convertMintUnitToSats(
                            $balance,
                            $mintUnit,
                            $store['price_provider_primary'] ?? null,
                            $store['price_provider_secondary'] ?? null
                        );
                    } catch (Exception $e) {
                        error_log("Failed to convert balance to sats: " . $e->getMessage());
                        $balanceInSats = null;
                    }
                } else {
                    // Already in sats (or close)
                    $balanceInSats = $mintUnit === 'msat' ? (int)ceil($balance / 1000) : $balance;
                }
            }

            echo json_encode([
                'storeId' => $storeId,
                'storeName' => $store['name'] ?? 'Unknown',
                'storeConfigured' => $storeConfigured,
                'balance' => $balance,
                'balanceInSats' => $balanceInSats,
                'swapFee' => $swapFee,
                'exportAvailable' => $exportAvailable,
                'mintUnit' => $mintUnit,
                'balanceCached' => $balanceCached,
                'invoices' => array_map([Invoice::class, 'formatForApi'], $recentInvoices),
                'stores' => $stores,
                'autoMelt' => $autoMelt,
            ]);
            break;

        case 'invoices':
            $status = $_GET['status'] ?? null;
            $storeId = $_GET['store_id'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 50), 100);
            $offset = (int)($_GET['offset'] ?? 0);

            $sql = "SELECT * FROM invoices";
            $params = [];
            $conditions = [];

            if ($status) {
                $conditions[] = "status = ?";
                $params[] = $status;
            }

            if ($storeId) {
                $conditions[] = "store_id = ?";
                $params[] = $storeId;
            }

            if (count($conditions) > 0) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }

            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $invoices = Database::fetchAll($sql, $params);
            echo json_encode(array_map([Invoice::class, 'formatForApi'], $invoices));
            break;

        case 'stores':
            $stores = Database::fetchAll("SELECT * FROM stores ORDER BY created_at DESC");
            echo json_encode($stores);
            break;

        case 'api_keys':
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId) {
                echo json_encode([]);
                break;
            }
            $keys = Auth::getApiKeys($storeId);
            echo json_encode($keys);
            break;

        case 'export_info':
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId || !Config::isStoreConfigured($storeId)) {
                echo json_encode(['available' => 0, 'error' => 'Store not configured']);
                break;
            }

            $mintReachable = true;

            // Try to resolve pending proofs (requires mint contact)
            try {
                Invoice::checkPendingProofs($storeId);
            } catch (\Exception $e) {
                $mintReachable = false;
                error_log("CashuPayServer: export_info checkPendingProofs failed: " . $e->getMessage());
            }

            if ($mintReachable) {
                // Mint is reachable - get proofs and check states
                $proofs = Invoice::getUnspentProofs($storeId);
                $wallet = Invoice::getWalletInstance($storeId);

                // Check proof states at mint (same as export_token)
                if (!empty($proofs)) {
                    try {
                        $states = $wallet->checkProofState($proofs);
                        $validProofs = [];
                        $spentSecrets = [];

                        foreach ($states as $i => $state) {
                            // Normalize case - mints may return lowercase states
                            $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                            if ($mintState === ProofState::UNSPENT) {
                                $validProofs[] = $proofs[$i];
                            } elseif ($mintState === ProofState::SPENT) {
                                $spentSecrets[] = $proofs[$i]->secret;
                            }
                            // Skip PENDING proofs (same as export_token)
                        }

                        // Update spent proofs locally
                        if (!empty($spentSecrets)) {
                            Invoice::markProofsSpent($storeId, $spentSecrets);
                        }

                        $proofs = $validProofs;
                    } catch (\Cashu\CashuException $e) {
                        // If checkstate fails, fall back to local balance
                        $mintReachable = false;
                        error_log("CashuPayServer: export_info checkProofState failed: " . $e->getMessage());
                    }
                }

                if ($mintReachable) {
                    $balance = \Cashu\Wallet::sumProofs($proofs);
                    $fee = $wallet->calculateFee($proofs);
                    $available = max(0, $balance - $fee);
                    echo json_encode(['available' => $available, 'mintUnit' => Config::getStoreMintUnit($storeId)]);
                    break;
                }
            }

            // Mint unreachable - fall back to offline balance with no fee calculation
            $offlineBalance = Invoice::getBalance($storeId);
            echo json_encode([
                'available' => $offlineBalance,
                'mintUnit' => Config::getStoreMintUnit($storeId),
                'mintUnreachable' => true
            ]);
            break;

        case 'proofs':
            $storeId = $_GET['store_id'] ?? null;
            if (!$storeId || !Config::isStoreConfigured($storeId)) {
                echo json_encode([]);
                break;
            }
            $wallet = Invoice::getWalletInstance($storeId);
            $rows = $wallet->getStorage()->getProofs(ProofState::UNSPENT);
            echo json_encode($rows);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Handle form POSTs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    // Login doesn't require existing session or CSRF (first request)
    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        $clientIp = Security::getClientIp();

        // Check if locked out
        if (Security::isLockedOut($clientIp)) {
            $remaining = Security::getLockoutRemaining($clientIp);
            http_response_code(429);
            echo json_encode(['error' => "Too many failed attempts. Try again in {$remaining} seconds."]);
            exit;
        }

        if (Auth::login($password)) {
            Security::clearLoginAttempts($clientIp);

            // C1: Trigger background sync on login
            if (Background::shouldSync()) {
                Background::trigger();
            }

            // H5: Check DB protection on login
            $securityWarning = null;
            if (!Database::isDataDirOutsideWebroot()) {
                $securityWarning = checkDataDirectoryProtection();
            }

            echo json_encode([
                'success' => true,
                'securityWarning' => $securityWarning,
                'csrfToken' => Auth::generateCsrfToken()
            ]);
        } else {
            Security::recordFailedLogin($clientIp);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid password']);
        }
        exit;
    }

    // All other actions require authentication
    Auth::initSession();
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    // M2: CSRF validation for authenticated POST requests
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!Auth::validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    switch ($action) {
        case 'logout':
            Auth::logout();
            echo json_encode(['success' => true]);
            break;

        case 'save_url_mode':
            $mode = $_POST['mode'] ?? 'router';
            if (in_array($mode, ['direct', 'router'])) {
                Config::set('url_mode', $mode);
                echo json_encode([
                    'success' => true,
                    'mode' => $mode,
                    'serverUrl' => Urls::server()
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid mode']);
            }
            break;

        case 'create_store':
            try {
                $name = trim($_POST['name'] ?? 'New Store');
                $mintUrl = $_POST['mint_url'] ?? null;
                $mintUnit = $_POST['mint_unit'] ?? 'sat';
                $seedPhrase = $_POST['seed_phrase'] ?? null;
                $exchangeFee = (float)($_POST['exchange_fee_percent'] ?? 0);
                $primaryProvider = $_POST['price_provider_primary'] ?? 'coingecko';
                $secondaryProvider = $_POST['price_provider_secondary'] ?? 'binance';

                // Check for duplicate store name
                $existingStore = Database::fetchOne(
                    "SELECT id FROM stores WHERE LOWER(name) = LOWER(?)",
                    [$name]
                );
                if ($existingStore) {
                    throw new Exception('A store with this name already exists.');
                }

                // If mint URL provided, test connection
                if ($mintUrl) {
                    $test = Config::testMintConnection($mintUrl);
                    if (!$test['success']) {
                        throw new Exception('Cannot connect to mint: ' . $test['error']);
                    }
                }

                // Generate seed phrase if not provided
                if ($mintUrl && !$seedPhrase) {
                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    $seedPhrase = \Cashu\Mnemonic::generate();
                }

                // Check for duplicate seed phrase (critical - same seed with different units = lost funds)
                if ($seedPhrase) {
                    $existing = Database::fetchOne(
                        "SELECT id, name FROM stores WHERE seed_phrase = ?",
                        [$seedPhrase]
                    );
                    if ($existing) {
                        throw new Exception("This seed phrase is already used by store: {$existing['name']}. Using the same seed for multiple stores can result in lost funds.");
                    }
                }

                $storeId = Database::generateId('store');
                Database::insert('stores', [
                    'id' => $storeId,
                    'name' => $name,
                    'mint_url' => $mintUrl,
                    'mint_unit' => $mintUnit,
                    'seed_phrase' => $seedPhrase,
                    'exchange_fee_percent' => $exchangeFee,
                    'price_provider_primary' => $primaryProvider,
                    'price_provider_secondary' => $secondaryProvider,
                    'created_at' => Database::timestamp(),
                ]);

                echo json_encode([
                    'id' => $storeId,
                    'name' => $name,
                    'isConfigured' => !empty($mintUrl) && !empty($seedPhrase),
                    'seedPhrase' => $seedPhrase, // Show once for backup
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'update_store':
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (!$storeId) {
                    throw new Exception('Store ID required');
                }

                $store = Config::getStore($storeId);
                if (!$store) {
                    throw new Exception('Store not found');
                }

                $updates = [];

                if (isset($_POST['name'])) {
                    $updates['name'] = $_POST['name'];
                }
                if (isset($_POST['mint_url'])) {
                    $mintUrl = $_POST['mint_url'];
                    if ($mintUrl) {
                        $test = Config::testMintConnection($mintUrl);
                        if (!$test['success']) {
                            throw new Exception('Cannot connect to mint: ' . $test['error']);
                        }
                    }
                    $updates['mint_url'] = $mintUrl;
                }
                if (isset($_POST['mint_unit'])) {
                    $updates['mint_unit'] = $_POST['mint_unit'];
                }
                if (isset($_POST['seed_phrase'])) {
                    $updates['seed_phrase'] = $_POST['seed_phrase'];
                }
                if (isset($_POST['exchange_fee_percent'])) {
                    $updates['exchange_fee_percent'] = (float)$_POST['exchange_fee_percent'];
                }
                if (isset($_POST['price_provider_primary'])) {
                    $updates['price_provider_primary'] = $_POST['price_provider_primary'];
                }
                if (isset($_POST['price_provider_secondary'])) {
                    $updates['price_provider_secondary'] = $_POST['price_provider_secondary'];
                }

                Config::updateStore($storeId, $updates);

                echo json_encode([
                    'success' => true,
                    'isConfigured' => Config::isStoreConfigured($storeId),
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'generate_seed':
            require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
            echo json_encode(['seedPhrase' => \Cashu\Mnemonic::generate()]);
            break;

        case 'validate_seed':
            try {
                $seedPhrase = $_POST['seed_phrase'] ?? '';
                if (empty($seedPhrase)) {
                    throw new Exception('Seed phrase required');
                }

                // Check for duplicate seed phrase
                $existing = Database::fetchOne(
                    "SELECT id, name FROM stores WHERE seed_phrase = ?",
                    [$seedPhrase]
                );

                if ($existing) {
                    echo json_encode([
                        'valid' => false,
                        'error' => "This seed phrase is already used by store: {$existing['name']}",
                        'existingStore' => $existing['name'],
                    ]);
                } else {
                    echo json_encode(['valid' => true]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'delete_store':
            $storeId = $_POST['store_id'] ?? '';
            Database::delete('stores', 'id = ?', [$storeId]);
            echo json_encode(['success' => true]);
            break;

        case 'create_api_key':
            $storeId = $_POST['store_id'] ?? '';
            $label = $_POST['label'] ?? 'API Key';
            $apiKey = Auth::createApiKey($storeId, $label);
            echo json_encode($apiKey);
            break;

        case 'delete_api_key':
            $keyId = $_POST['key_id'] ?? '';
            Auth::deleteApiKey($keyId);
            echo json_encode(['success' => true]);
            break;

        case 'check_proofs_spent':
            // Check if specific proofs have been spent (for detecting token claims)
            try {
                $storeId = $_POST['store_id'] ?? '';
                $secretsRaw = $_POST['secrets'] ?? '[]';
                $secrets = json_decode($secretsRaw, true);
                if ($secrets === null && json_last_error() !== JSON_ERROR_NONE) {
                    // JSON parse failed - likely WordPress magic quotes escaping
                    $secrets = json_decode(stripslashes($secretsRaw), true);
                }

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (empty($secrets)) {
                    echo json_encode(['spent' => false]);
                    break;
                }

                // Check database state first using library storage (quick check)
                $wallet = Invoice::getWalletInstance($storeId);
                $spentProofs = $wallet->getStorage()->getProofs(ProofState::SPENT);
                $spentSecrets = array_column($spentProofs, 'secret');
                $spentCount = count(array_intersect($secrets, $spentSecrets));

                if ($spentCount == count($secrets)) {
                    // All spent in database
                    echo json_encode(['spent' => true, 'source' => 'db']);
                    break;
                }

                // Check at mint for real-time status
                $Ys = [];
                foreach ($secrets as $secret) {
                    $Y = \Cashu\Crypto::hashToCurve($secret);
                    $Ys[] = bin2hex(\Cashu\Secp256k1::compressPoint($Y));
                }

                $store = Config::getStore($storeId);
                $client = new \Cashu\MintClient($store['mint_url']);
                $response = $client->post('checkstate', ['Ys' => $Ys]);


                $allSpent = true;
                foreach ($response['states'] ?? [] as $state) {
                    // Normalize case - mints may return lowercase states
                    $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                    if ($mintState !== ProofState::SPENT) {
                        $allSpent = false;
                        break;
                    }
                }

                // If all spent at mint but not in DB, update DB
                if ($allSpent && $spentCount < count($secrets)) {
                    Invoice::markProofsSpent($storeId, $secrets);
                }

                echo json_encode(['spent' => $allSpent, 'source' => 'mint']);
            } catch (Exception $e) {
                error_log("CashuPayServer: check_proofs_spent error: " . $e->getMessage());
                echo json_encode(['spent' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_withdrawal_estimate':
            // Get mint's actual cost for a Lightning withdrawal (to handle exchange rate differences)
            try {
                $storeId = $_POST['store_id'] ?? '';
                $destination = $_POST['destination'] ?? '';
                $amountSats = (int)($_POST['amount_sats'] ?? 0);

                if (empty($storeId) || !Config::isStoreConfigured($storeId)) {
                    throw new Exception('Store not configured');
                }
                if (empty($destination)) {
                    throw new Exception('Destination required');
                }
                if ($amountSats < 1) {
                    throw new Exception('Amount required');
                }

                $mintUnit = Config::getStoreMintUnit($storeId);
                $isFiatMint = !in_array(strtolower($mintUnit), ['sat', 'sats', 'msat']);

                // Get wallet for this store
                $wallet = Invoice::getWalletInstance($storeId);
                $balance = Invoice::getBalance($storeId);

                // Check if it's a BOLT-11 invoice or Lightning address
                $isBolt11 = LightningAddress::isBolt11Invoice($destination);
                $isLightningAddress = !$isBolt11 && strpos($destination, '@') !== false;

                if (!$isBolt11 && !$isLightningAddress) {
                    throw new Exception('Invalid destination');
                }

                // Get invoice for the amount
                if ($isBolt11) {
                    // For BOLT-11, extract the amount and use the invoice directly
                    $bolt11Info = LightningAddress::getBolt11Amount($destination, $storeId);
                    if ($bolt11Info !== null && $bolt11Info['amountSats'] > 0) {
                        $amountSats = $bolt11Info['amountSats'];
                    }
                    $bolt11 = $destination;
                } else {
                    // Get invoice from Lightning address
                    $bolt11 = \Cashu\LightningAddress::getInvoice($destination, $amountSats, null);
                }

                // Request melt quote to get actual cost in mint units
                $meltQuote = $wallet->requestMeltQuote($bolt11);

                $costMintUnit = $meltQuote->amount;
                $feeReserve = $meltQuote->feeReserve;
                $totalCost = $costMintUnit + $feeReserve;
                $canAfford = $balance >= $totalCost;

                // Calculate max SAT that user can afford at mint's rate
                $maxAffordableSats = 0;
                if ($isFiatMint && $costMintUnit > 0) {
                    // Rate: sats per mint unit
                    $ratePerMintUnit = $amountSats / $costMintUnit;
                    // How many mint units available after fee reserve?
                    // Need to account for fee reserve scaling with amount
                    // Approximate: use same fee percentage
                    $feePercent = ($feeReserve / $costMintUnit) * 100;
                    $availableForMelt = $balance / (1 + $feePercent / 100);
                    $maxAffordableSats = (int)floor($availableForMelt * $ratePerMintUnit);
                }

                echo json_encode([
                    'success' => true,
                    'amountSats' => $amountSats,
                    'costMintUnit' => $costMintUnit,
                    'feeReserve' => $feeReserve,
                    'totalCost' => $totalCost,
                    'balance' => $balance,
                    'canAfford' => $canAfford,
                    'maxAffordableSats' => $maxAffordableSats,
                    'mintUnit' => $mintUnit,
                    'isFiatMint' => $isFiatMint,
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;

        case 'save_auto_melt':
            try {
                $storeId = $_POST['store_id'] ?? '';
                $address = $_POST['address'] ?? '';
                $enabled = ($_POST['enabled'] ?? '0') === '1' ? 1 : 0;
                $threshold = (int)($_POST['threshold'] ?? 2000);

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }

                // Validate Lightning address format if provided
                if (!empty($address) && !preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $address)) {
                    throw new Exception('Invalid Lightning address format');
                }

                Database::update('stores', [
                    'auto_melt_enabled' => $enabled,
                    'auto_melt_address' => $address ?: null,
                    'auto_melt_threshold' => $threshold
                ], 'id = ?', [$storeId]);

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'manual_melt':
            try {
                $storeId = $_POST['store_id'] ?? '';
                $destination = $_POST['address'] ?? '';
                $amount = (int)($_POST['amount'] ?? 0);
                $donate = isset($_POST['donate']) && $_POST['donate'] === '1';
                // For ALL Lightning destinations with fiat mints, amount is in SATS (from frontend)
                $amountIsSats = isset($_POST['amount_is_sats']) && $_POST['amount_is_sats'] === '1';

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (!Config::isStoreConfigured($storeId)) {
                    throw new Exception('Store not configured');
                }
                if (empty($destination)) {
                    throw new Exception('Destination (Lightning address or BOLT-11 invoice) required');
                }

                $isBolt11 = LightningAddress::isBolt11Invoice($destination);
                $isLightningAddress = !$isBolt11 && strpos($destination, '@') !== false;
                $isLightningDestination = $isBolt11 || $isLightningAddress;
                $mintUnit = Config::getStoreMintUnit($storeId);
                $isFiatMint = !in_array(strtolower($mintUnit), ['sat', 'sats', 'msat']);

                // For BOLT-11 with encoded amount, get the sat amount from the invoice
                // This overrides the frontend amount since the invoice has a fixed amount
                if ($isBolt11) {
                    $bolt11Info = LightningAddress::getBolt11Amount($destination, $storeId);
                    if ($bolt11Info !== null && $bolt11Info['amountSats'] > 0) {
                        // Use the invoice's encoded amount in SATS
                        $amount = $bolt11Info['amountSats'];
                        $amountIsSats = true; // Bolt11 encoded amount is in sats
                    }
                }

                if ($amount < 1) {
                    throw new Exception('Amount required');
                }

                // For ALL Lightning destinations with fiat mints, amount is in SATS
                // We need to handle donation calculations differently
                $donationAmount = 0;
                $donationSuccess = false;

                if ($isLightningDestination && $isFiatMint && $amountIsSats) {
                    // Amount is in SATS - need to estimate equivalent mint units for donation
                    $withdrawAmountSats = $amount;

                    // Get bolt11 invoice first (to get melt quote before doing anything)
                    if ($isBolt11) {
                        $bolt11ForQuote = $destination;
                    } else {
                        // Get invoice from Lightning address
                        $bolt11ForQuote = LightningAddress::getInvoice($destination, $withdrawAmountSats, 'CashuPayServer withdrawal');
                    }

                    // Get melt quote to know the cost in mint units BEFORE doing anything
                    $wallet = Invoice::getWalletInstance($storeId);
                    $meltQuote = $wallet->requestMeltQuote($bolt11ForQuote);
                    $meltCost = $meltQuote->amount + $meltQuote->feeReserve;

                    // Process donation FIRST (while we have all proofs available)
                    if ($donate && defined('CASHUPAY_DONATION_PERCENT')) {
                        $donationAmount = Donation::calculateAmount($meltQuote->amount);

                        if ($donationAmount > 0) {
                            $balance = Invoice::getBalance($storeId);
                            if ($balance >= $meltCost + $donationAmount) {
                                // Send donation FIRST while we have all proofs to work with
                                $donationResult = Donation::sendToDonationSink($storeId, $donationAmount);
                                $donationSuccess = $donationResult['success'];
                                if (!$donationSuccess) {
                                    error_log("Donation failed: " . ($donationResult['error'] ?? 'unknown'));
                                    $donationAmount = 0;
                                }
                            } else {
                                error_log("Skipping donation: balance {$balance} < melt cost {$meltCost} + donation {$donationAmount}");
                                $donationAmount = 0;
                            }
                        }
                    }

                    // THEN do the melt (using the bolt11 we already have)
                    $result = LightningAddress::meltToBolt11($storeId, $bolt11ForQuote);
                } else {
                    // Standard flow: amount is in mint units (sat mint or non-Lightning destination)
                    // Process donation if enabled (using donation sink)
                    // Donation is taken ON TOP of the withdrawal amount - user receives exactly what they entered
                    if ($donate && defined('CASHUPAY_DONATION_PERCENT')) {
                        $donationAmount = Donation::calculateAmount($amount);

                        if ($donationAmount > 0) {
                            // Check balance covers withdrawal amount + donation
                            $balance = Invoice::getBalance($storeId);
                            $totalNeeded = $amount + $donationAmount;

                            if ($balance < $totalNeeded) {
                                // Insufficient balance for amount + donation, skip donation
                                error_log("Skipping donation: balance {$balance} < total needed {$totalNeeded}");
                                $donationAmount = 0;
                            } else {
                                $donationResult = Donation::sendToDonationSink($storeId, $donationAmount);
                                $donationSuccess = $donationResult['success'];

                                if (!$donationSuccess) {
                                    // Donation failed - continue with full withdrawal
                                    error_log("Donation failed: " . ($donationResult['error'] ?? 'unknown'));
                                    $donationAmount = 0;
                                }
                            }
                        }
                    }

                    // Proceed with main withdrawal
                    $withdrawAmount = $amount;

                    if ($isBolt11) {
                        $result = LightningAddress::meltToBolt11($storeId, $destination, $withdrawAmount);
                    } else {
                        // For Lightning address with sat mint, amount is already in sats
                        $result = LightningAddress::meltToAddress($storeId, $destination, $withdrawAmount, 'CashuPayServer withdrawal');
                    }
                }

                // Include donation info in response
                $result['donated'] = $donationAmount;
                $result['donationSuccess'] = $donationSuccess;
                if ($donationSuccess) {
                    $result['donationMessage'] = 'Thank you for supporting CashuPayServer!';
                }

                echo json_encode($result);
            } catch (Exception $e) {
                // If melt failed due to "already spent", sync proof states
                if (stripos($e->getMessage(), 'already spent') !== false ||
                    stripos($e->getMessage(), 'proof already spent') !== false) {
                    try {
                        $wallet = Invoice::getWalletInstance($storeId);
                        $wallet->syncProofStates();
                        http_response_code(400);
                        echo json_encode([
                            'error' => 'Some proofs were already spent. Balance updated. Please try again.',
                            'sync' => true
                        ]);
                        break;
                    } catch (Exception $syncError) {
                        // Sync failed, fall through to generic error
                    }
                }
                http_response_code(400);
                $errorMsg = ($e instanceof \Cashu\CashuProtocolException)
                    ? 'Mint returned error: ' . $e->getMessage()
                    : $e->getMessage();
                echo json_encode(['error' => $errorMsg]);
            }
            break;

        case 'get_bolt11_amount':
            try {
                $bolt11 = $_POST['bolt11'] ?? '';
                $storeId = $_POST['store_id'] ?? '';

                if (empty($bolt11) || !LightningAddress::isBolt11Invoice($bolt11)) {
                    echo json_encode(['hasAmount' => false, 'amountSats' => null, 'feeEstimate' => null]);
                    break;
                }

                // Use store-specific wallet if store_id is provided
                $info = LightningAddress::getBolt11Amount($bolt11, $storeId ?: null);

                if ($info === null) {
                    echo json_encode(['hasAmount' => false, 'amountSats' => null, 'feeEstimate' => null]);
                } else {
                    // The invoice amount is encoded in SATS - this is what Lightning invoices use
                    // The melt quote 'amount' is in the mint's unit, but the invoice itself is in sats
                    // We return amountSats which is the sat amount from the invoice
                    echo json_encode([
                        'hasAmount' => $info['amountSats'] > 0,
                        'amountSats' => $info['amountSats'],
                        'amountMintUnit' => $info['amountMintUnit'] ?? null,
                        'feeEstimate' => $info['feeReserve'],
                        'meltError' => $info['meltError'] ?? null
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode(['hasAmount' => false, 'amountSats' => null, 'feeEstimate' => null, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_balance_in_sats':
            // Get store balance converted to satoshis (for fiat mints)
            try {
                $storeId = $_POST['store_id'] ?? '';
                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }

                $store = Config::getStore($storeId);
                if (!$store) {
                    throw new Exception('Store not found');
                }

                require_once __DIR__ . '/includes/rates.php';

                $balance = Invoice::getBalance($storeId);
                $mintUnit = strtolower($store['mint_unit'] ?? 'sat');

                if (in_array($mintUnit, ['sat', 'sats', 'msat'])) {
                    // Already in sats (or close)
                    $balanceInSats = $mintUnit === 'msat' ? (int)ceil($balance / 1000) : $balance;
                } else {
                    // Fiat mint - convert to sats
                    $balanceInSats = ExchangeRates::convertMintUnitToSats(
                        $balance,
                        $mintUnit,
                        $store['price_provider_primary'] ?? null,
                        $store['price_provider_secondary'] ?? null
                    );
                }

                echo json_encode([
                    'balanceMintUnit' => $balance,
                    'balanceSats' => $balanceInSats,
                    'mintUnit' => $mintUnit,
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'export_token':
            // OFFLINE-FIRST EXPORT: Minimize mint contact, maximize reliability
            //
            // Flow:
            // 1. Get proofs from local storage
            // 2. Try greedy selection (no mint needed if exact change available)
            // 3. If no exact change, try mint swap (if reachable)
            // 4. If mint unreachable, ask user to confirm larger amount
            // 5. Process donation (sink may be reachable even if mint is not)
            // 6. Serialize and return token
            try {
                require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';

                $storeId = $_POST['store_id'] ?? '';
                $amount = (int)($_POST['amount'] ?? 0);
                $donate = isset($_POST['donate']) && $_POST['donate'] === '1';
                $forceAmount = isset($_POST['force_amount']) ? (int)$_POST['force_amount'] : null;

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (!Config::isStoreConfigured($storeId)) {
                    throw new Exception('Store not configured');
                }
                if ($amount < 1) {
                    throw new Exception('Amount required');
                }

                // Resolve any pending proofs first (handles donation proofs marked pending)
                Invoice::checkPendingProofs($storeId);

                // 1. Get proofs from local storage (offline-first)
                $proofs = Invoice::getUnspentProofs($storeId);
                $balance = \Cashu\Wallet::sumProofs($proofs);

                if ($balance < $amount) {
                    $store = Config::getStore($storeId);
                    $mintUnit = $store['mint_unit'] ?? 'sat';
                    throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$amount} {$mintUnit}");
                }

                // 2. Calculate donation if enabled
                $donationAmount = 0;
                if ($donate && defined('CASHUPAY_DONATION_PERCENT')) {
                    $donationAmount = Donation::calculateAmount($amount);
                }

                // 3. Try greedy selection first (no mint needed if exact change available)
                $selected = \Cashu\Wallet::selectProofs($proofs, $amount);
                $selectedSum = \Cashu\Wallet::sumProofs($selected);
                $hasExactChange = ($selectedSum === $amount);

                // Track state
                $sendProofs = null;
                $mintUsed = false;
                $mintReachable = true;
                $donationSuccess = false;
                $wallet = null;

                // 4. If no exact change, try mint swap
                if (!$hasExactChange) {
                    try {
                        $wallet = Invoice::getWalletInstance($storeId);

                        // Check proof states at mint first (filter out spent/pending)
                        if (!empty($proofs)) {
                            $states = $wallet->checkProofState($proofs);
                            $validProofs = [];
                            $spentSecrets = [];

                            foreach ($states as $i => $state) {
                                $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                                if ($mintState === ProofState::SPENT) {
                                    $spentSecrets[] = $proofs[$i]->secret;
                                } elseif ($mintState === ProofState::UNSPENT) {
                                    $validProofs[] = $proofs[$i];
                                }
                                // Skip PENDING proofs
                            }

                            if (!empty($spentSecrets)) {
                                Invoice::markProofsSpent($storeId, $spentSecrets);
                            }
                            $proofs = $validProofs;
                            $balance = \Cashu\Wallet::sumProofs($proofs);
                        }

                        $fee = $wallet->calculateFee($proofs);

                        // Try optimized single swap (export + donation + keep)
                        if ($donationAmount > 0 && $balance >= $amount + $donationAmount + $fee) {
                            $keepAmount = $balance - $amount - $donationAmount - $fee;

                            $exportAmounts = \Cashu\Wallet::splitAmount($amount);
                            $donationAmounts = \Cashu\Wallet::splitAmount($donationAmount);
                            $keepAmounts = $keepAmount > 0 ? \Cashu\Wallet::splitAmount($keepAmount) : [];
                            $allAmounts = array_merge($exportAmounts, $donationAmounts, $keepAmounts);

                            $newProofs = $wallet->swap($proofs, $allAmounts);

                            // Separate proofs into categories
                            $donationProofs = [];
                            $remainingExportAmounts = $exportAmounts;
                            $remainingDonationAmounts = $donationAmounts;

                            foreach ($newProofs as $proof) {
                                $key = array_search($proof->amount, $remainingExportAmounts);
                                if ($key !== false) {
                                    $sendProofs[] = $proof;
                                    unset($remainingExportAmounts[$key]);
                                    $remainingExportAmounts = array_values($remainingExportAmounts);
                                    continue;
                                }

                                $key = array_search($proof->amount, $remainingDonationAmounts);
                                if ($key !== false) {
                                    $donationProofs[] = $proof;
                                    unset($remainingDonationAmounts[$key]);
                                    $remainingDonationAmounts = array_values($remainingDonationAmounts);
                                }
                                // Rest goes to keep (already in storage from swap)
                            }

                            // Send donation
                            if (!empty($donationProofs)) {
                                $donationToken = $wallet->serializeToken($donationProofs);
                                Donation::postTokenToSink($donationToken);
                                $donationSuccess = true;
                                $donationSecrets = array_map(fn($p) => $p->secret, $donationProofs);
                                Invoice::markProofsSpent($storeId, $donationSecrets);
                            }

                            $mintUsed = true;
                        } elseif ($balance >= $amount + $fee) {
                            // Simple split (no donation or insufficient for donation)
                            $result = $wallet->split($proofs, $amount);
                            $sendProofs = $result['send'];
                            $mintUsed = true;
                            $donationAmount = 0; // Reset since we couldn't include it
                        } else {
                            $mintUnit = $wallet->getUnit();
                            throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: " . ($amount + $fee) . " {$mintUnit}");
                        }

                    } catch (Exception $e) {
                        // Check if mint is unreachable
                        if (Invoice::isMintUnreachable($e)) {
                            $mintReachable = false;
                            // Continue with greedy selection below
                        } elseif (stripos($e->getMessage(), 'already spent') !== false ||
                                  stripos($e->getMessage(), 'proof already spent') !== false) {
                            // Sync proof states and ask user to retry
                            if ($wallet) {
                                try { $wallet->syncProofStates(); } catch (Exception $se) {}
                            }
                            http_response_code(400);
                            echo json_encode([
                                'error' => 'Some proofs were already spent. Balance updated. Please try again.',
                                'sync' => true
                            ]);
                            break;
                        } else {
                            throw $e; // Re-throw other errors
                        }
                    }
                }

                // 5. If mint not used (exact change locally OR mint unreachable), use greedy selection
                if (!$mintUsed) {
                    // Re-select in case proofs changed during state check
                    $selected = \Cashu\Wallet::selectProofs($proofs, $amount);
                    $selectedSum = \Cashu\Wallet::sumProofs($selected);

                    // Ask user to confirm if amount differs and mint is unreachable
                    if ($selectedSum !== $amount && $forceAmount !== $selectedSum) {
                        $message = $mintReachable
                            ? "Exact change unavailable. Export {$selectedSum} instead of {$amount}?"
                            : "Mint unreachable. Exact change unavailable. Export {$selectedSum} instead of {$amount}?";

                        echo json_encode([
                            'error' => 'change_needed',
                            'requested' => $amount,
                            'available' => $selectedSum,
                            'mintUnreachable' => !$mintReachable,
                            'message' => $message
                        ]);
                        break;
                    }

                    $sendProofs = $selected;
                    $amount = $selectedSum; // Update to actual amount

                    // Still try donation even when offline (sink may be reachable)
                    if ($donationAmount > 0) {
                        $remainingProofs = array_filter($proofs, fn($p) => !in_array($p, $selected, true));
                        $remainingBalance = \Cashu\Wallet::sumProofs($remainingProofs);

                        if ($remainingBalance >= $donationAmount) {
                            try {
                                $donationSelected = \Cashu\Wallet::selectProofs($remainingProofs, $donationAmount);
                                $donationSum = \Cashu\Wallet::sumProofs($donationSelected);

                                if ($donationSum >= $donationAmount) {
                                    // Serialize and send donation (offline - no swap, send raw proofs)
                                    $store = Config::getStore($storeId);
                                    $mintUrl = $store['mint_url'];
                                    $mintUnit = $store['mint_unit'] ?? 'sat';

                                    $donationToken = \Cashu\TokenSerializer::serializeV4($mintUrl, $donationSelected, $mintUnit);
                                    Donation::postTokenToSink($donationToken);
                                    $donationSuccess = true;

                                    // Mark donation proofs as SPENT
                                    $donationSecrets = array_map(fn($p) => $p->secret, $donationSelected);
                                    Invoice::markProofsSpent($storeId, $donationSecrets);
                                }
                            } catch (Exception $de) {
                                // Donation failed, continue without it
                                error_log("CashuPayServer: Offline donation failed: " . $de->getMessage());
                                $donationAmount = 0;
                            }
                        } else {
                            $donationAmount = 0; // Not enough remaining for donation
                        }
                    }
                }

                // 6. Serialize and return token
                if (empty($sendProofs)) {
                    throw new Exception("Export failed: no proofs available");
                }

                // Get store config for serialization
                $store = Config::getStore($storeId);
                $mintUrl = $store['mint_url'];
                $mintUnit = $store['mint_unit'] ?? 'sat';

                // Use wallet serialization if available, otherwise manual
                if ($wallet && $mintUsed) {
                    $token = $wallet->serializeToken($sendProofs);
                } else {
                    // Offline serialization - detect V4 vs V3 format
                    $useV4 = true;
                    foreach ($sendProofs as $proof) {
                        if (!\Cashu\TokenSerializer::isHexKeysetId($proof->id)) {
                            $useV4 = false;
                            break;
                        }
                    }
                    $token = $useV4
                        ? \Cashu\TokenSerializer::serializeV4($mintUrl, $sendProofs, $mintUnit)
                        : \Cashu\TokenSerializer::serializeV3($mintUrl, $sendProofs, $mintUnit);
                }

                // Mark sent proofs as PENDING for claim tracking
                $sentSecrets = array_map(fn($p) => $p->secret, $sendProofs);
                Invoice::markProofsPending($storeId, $sentSecrets);

                $response = [
                    'token' => $token,
                    'amount' => \Cashu\Wallet::sumProofs($sendProofs),
                    'secrets' => $sentSecrets,
                ];

                if (!$mintUsed) {
                    $response['offlineExport'] = true;
                }
                if (!$mintReachable) {
                    $response['mintUnreachable'] = true;
                }
                if ($donationSuccess && $donationAmount > 0) {
                    $response['donated'] = $donationAmount;
                    $response['donationSuccess'] = true;
                    if ($mintUsed) {
                        $response['feeSaved'] = true;
                    }
                }

                echo json_encode($response);

            } catch (Exception $e) {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollback();
                }
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'get_backup_mints':
            $storeId = $_GET['store_id'] ?? $_POST['store_id'] ?? null;
            if (!$storeId) {
                http_response_code(400);
                echo json_encode(['error' => 'Store ID required']);
                break;
            }
            echo json_encode(Config::getStoreBackupMints($storeId));
            break;

        case 'add_backup_mint':
            try {
                $storeId = $_POST['store_id'] ?? '';
                $mintUrl = $_POST['mint_url'] ?? '';
                $unit = $_POST['unit'] ?? 'sat';
                $priority = (int)($_POST['priority'] ?? 100);

                if (empty($storeId)) {
                    throw new Exception('Store ID required');
                }
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }

                // Test connection first
                $test = Config::testMintConnection($mintUrl);
                if (!$test['success']) {
                    throw new Exception('Cannot connect to mint: ' . $test['error']);
                }

                $id = Config::addStoreBackupMint($storeId, $mintUrl, $unit, $priority);
                echo json_encode([
                    'success' => true,
                    'id' => $id,
                    'mint_info' => $test['info']
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'update_backup_mint':
            try {
                $id = (int)($_POST['id'] ?? 0);
                $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : null;
                $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : null;

                $data = [];
                if ($enabled !== null) $data['enabled'] = $enabled;
                if ($priority !== null) $data['priority'] = $priority;

                Config::updateStoreBackupMint($id, $data);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'remove_backup_mint':
            try {
                $id = (int)($_POST['id'] ?? 0);
                Config::removeStoreBackupMint($id);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'test_mint':
            try {
                $mintUrl = $_POST['mint_url'] ?? '';
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }
                $result = Config::testMintConnection($mintUrl);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'test_mint_expiry':
            try {
                require_once __DIR__ . '/includes/mint_helpers.php';
                $mintUrl = $_POST['mint_url'] ?? '';
                $unit = $_POST['unit'] ?? 'sat';
                if (empty($mintUrl)) {
                    throw new Exception('Mint URL required');
                }
                $result = MintHelpers::testExpiry($mintUrl, $unit);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Serve the SPA HTML
$baseUrl = Config::getBaseUrl();
$isWp = Urls::isWordPress();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0f23">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="CashuPay">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
    <title>CashuPayServer Admin</title>
    <?php if (!$isWp): ?><link rel="manifest" href="manifest.json"><?php endif; ?>
    <link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect fill='%230f0f23' width='100' height='100' rx='20'/><text x='50' y='70' font-size='60' text-anchor='middle'>⚡</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #0f0f23;
            --bg-secondary: #1a1a2e;
            --bg-card: rgba(255, 255, 255, 0.05);
            --bg-card-hover: rgba(255, 255, 255, 0.08);
            --text-primary: #ffffff;
            --text-secondary: #a0aec0;
            --text-muted: #4a5568;
            --accent: #f7931a;
            --accent-hover: #e8820a;
            --success: #48bb78;
            --error: #e53e3e;
            --warning: #ed8936;
            --border: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
        }

        /* Lock Screen */
        .lock-screen {
            position: fixed;
            inset: 0;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 2rem;
        }

        .lock-screen.hidden {
            display: none;
        }

        .lock-logo {
            font-size: 4rem;
            margin-bottom: 2rem;
        }

        .lock-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .lock-subtitle {
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

        .pin-dots {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .pin-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--border);
            transition: background 0.2s;
        }

        .pin-dot.filled {
            background: var(--accent);
        }

        .pin-dot.error {
            background: var(--error);
            animation: shake 0.5s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .pin-pad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            max-width: 280px;
        }

        .pin-key {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-primary);
            font-size: 1.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pin-key:hover {
            background: var(--bg-card-hover);
        }

        .pin-key:active {
            transform: scale(0.95);
        }

        .pin-key.empty {
            background: transparent;
            border: none;
            cursor: default;
        }

        .pin-key.backspace {
            font-size: 1.2rem;
        }

        .password-fallback {
            margin-top: 2rem;
        }

        .password-fallback input {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 1rem;
            width: 100%;
            margin-bottom: 1rem;
        }

        .password-fallback input:focus {
            outline: none;
            border-color: var(--accent);
        }

        /* App Shell */
        .app {
            display: none;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
        }

        .app.visible {
            display: flex;
        }

        /* Header */
        .header {
            position: sticky;
            top: 0;
            background: rgba(15, 15, 35, 0.9);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            z-index: 100;
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
            min-width: 0;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .header-store-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            min-width: 0;
            max-width: 350px;
        }

        .store-selector-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .header-store-selector select {
            flex: 1;
            min-width: 0;
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            text-overflow: ellipsis;
        }

        .header-store-selector select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        /* Mobile responsive header */
        @media (max-width: 500px) {
            .header {
                padding: 0.5rem;
            }

            .header-left {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .header-store-selector {
                max-width: none;
                width: 100%;
                order: 2;
            }

            .header-title {
                order: 1;
            }

            .store-selector-label {
                display: none;
            }
        }

        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .icon-btn:hover {
            background: var(--bg-card-hover);
        }

        /* Navigation */
        .nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(15, 15, 35, 0.95);
            backdrop-filter: blur(20px);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-around;
            padding: 0.5rem 0;
            padding-bottom: max(0.5rem, env(safe-area-inset-bottom));
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.75rem;
            transition: color 0.2s;
            cursor: pointer;
            background: none;
            border: none;
        }

        .nav-item.active {
            color: var(--accent);
        }

        .nav-item svg {
            width: 24px;
            height: 24px;
        }

        /* Main Content */
        .main {
            flex: 1;
            padding: 1rem;
            padding-bottom: calc(5rem + env(safe-area-inset-bottom));
            max-width: 800px;
            margin: 0 auto;
            width: 100%;
        }

        /* Views */
        .view {
            display: none;
        }

        .view.active {
            display: block;
        }

        /* Balance Card */
        .balance-card {
            background: linear-gradient(135deg, var(--accent) 0%, #d97706 100%);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
        }

        .balance-label {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .balance-amount {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .balance-unit {
            font-size: 0.875rem;
            opacity: 0.8;
        }

        .balance-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .balance-btn {
            flex: 1;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .balance-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-weight: 600;
        }

        .card-body {
            padding: 1rem;
        }

        /* Lists */
        .list-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.2s;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item:hover {
            background: var(--bg-card-hover);
        }

        .list-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .list-icon.success { background: rgba(72, 187, 120, 0.2); }
        .list-icon.pending { background: rgba(247, 147, 26, 0.2); }
        .list-icon.expired { background: rgba(229, 62, 62, 0.2); }

        .list-content {
            flex: 1;
            min-width: 0;
        }

        .list-title {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .list-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .list-amount {
            text-align: right;
        }

        .list-amount-value {
            font-weight: 600;
        }

        .list-amount-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            display: inline-block;
        }

        .list-amount-status.settled { background: rgba(72, 187, 120, 0.2); color: var(--success); }
        .list-amount-status.new { background: rgba(247, 147, 26, 0.2); color: var(--accent); }
        .list-amount-status.expired { background: rgba(229, 62, 62, 0.2); color: var(--error); }

        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .form-help {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn:hover {
            background: var(--accent-hover);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-card-hover);
        }

        .btn-danger {
            background: var(--error);
        }

        .btn-full {
            width: 100%;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-icon:hover {
            background: rgba(255,255,255,0.15);
        }

        /* Toggle */
        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
        }

        .toggle {
            position: relative;
            width: 50px;
            height: 28px;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--text-muted);
            border-radius: 28px;
            transition: background 0.3s;
        }

        .toggle-slider::before {
            position: absolute;
            content: "";
            width: 22px;
            height: 22px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: transform 0.3s;
        }

        .toggle input:checked + .toggle-slider {
            background: var(--accent);
        }

        .toggle input:checked + .toggle-slider::before {
            transform: translateX(22px);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 200;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--bg-secondary);
            border-radius: 24px 24px 0 0;
            padding: 1.5rem;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(100%);
            transition: transform 0.3s;
        }

        .modal-overlay.visible .modal {
            transform: translateY(0);
        }

        .modal-handle {
            width: 40px;
            height: 4px;
            background: var(--text-muted);
            border-radius: 2px;
            margin: 0 auto 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        /* Token Display */
        .token-display {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 1rem;
            font-family: monospace;
            font-size: 0.75rem;
            word-break: break-all;
            margin: 1rem 0;
            max-height: 150px;
            overflow-y: auto;
        }

        /* QR Container in Modal */
        .modal-qr {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 1.5rem 0;
            width: 100%;
            box-sizing: border-box;
        }

        /* Store Info Items */
        .store-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }

        .store-info-item:last-child {
            border-bottom: none;
        }

        .store-info-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .store-info-value {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
            word-break: break-all;
            text-align: right;
            max-width: 60%;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: calc(5rem + env(safe-area-inset-bottom) + 1rem);
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-size: 0.875rem;
            z-index: 300;
            transition: transform 0.3s;
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
        }

        .toast.success {
            border-color: var(--success);
        }

        .toast.error {
            border-color: var(--error);
        }

        .toast.info {
            border-color: #3b82f6;
        }

        /* Loading */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (min-width: 768px) {
            .nav {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                right: auto;
                width: 80px;
                flex-direction: column;
                justify-content: flex-start;
                padding: 1rem 0;
                border-top: none;
                border-right: 1px solid var(--border);
            }

            .main {
                margin-left: 80px;
                padding-bottom: 2rem;
            }

            .header {
                margin-left: 80px;
            }

            .modal {
                border-radius: 24px;
                margin: auto;
            }
        }
    </style>
</head>
<body>
    <!-- Lock Screen -->
    <div class="lock-screen" id="lock-screen">
        <div class="lock-logo">&#9889;</div>
        <div class="lock-title">CashuPayServer</div>
        <div class="lock-subtitle">Enter PIN to unlock</div>

        <div class="pin-dots" id="pin-dots">
            <div class="pin-dot"></div>
            <div class="pin-dot"></div>
            <div class="pin-dot"></div>
            <div class="pin-dot"></div>
        </div>

        <div class="pin-pad" id="pin-pad">
            <button class="pin-key" data-key="1">1</button>
            <button class="pin-key" data-key="2">2</button>
            <button class="pin-key" data-key="3">3</button>
            <button class="pin-key" data-key="4">4</button>
            <button class="pin-key" data-key="5">5</button>
            <button class="pin-key" data-key="6">6</button>
            <button class="pin-key" data-key="7">7</button>
            <button class="pin-key" data-key="8">8</button>
            <button class="pin-key" data-key="9">9</button>
            <button class="pin-key empty"></button>
            <button class="pin-key" data-key="0">0</button>
            <button class="pin-key backspace" data-key="back">&#8592;</button>
        </div>

        <div class="password-fallback" id="password-fallback">
            <input type="password" id="password-input" placeholder="Or enter password">
            <button class="btn btn-full" id="password-submit">Unlock</button>
        </div>
    </div>

    <!-- App -->
    <div class="app" id="app">
        <header class="header">
            <div class="header-left">
                <div class="header-title">
                    <span>&#9889;</span>
                    <span id="header-text">Dashboard</span>
                </div>
                <div class="header-store-selector" id="header-store-selector">
                    <span class="store-selector-label">Selected Store:</span>
                    <select id="store-select">
                        <option value="">Loading stores...</option>
                    </select>
                </div>
            </div>
            <div class="header-actions">
                <button class="icon-btn" id="refresh-btn" title="Refresh">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M23 4v6h-6M1 20v-6h6"></path>
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                    </svg>
                </button>
                <button class="icon-btn" id="lock-btn" title="Lock">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </button>
            </div>
        </header>

        <main class="main">
            <!-- Dashboard View -->
            <div class="view active" id="view-dashboard">
                <div class="balance-card">
                    <div class="balance-label">Total Balance</div>
                    <div class="balance-amount" id="balance-amount">---</div>
                    <div class="balance-unit" id="balance-unit">sats</div>
                    <div class="balance-actions">
                        <button class="balance-btn" id="btn-withdraw">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12l7-7 7 7"></path>
                            </svg>
                            Withdraw
                        </button>
                        <button class="balance-btn" id="btn-export">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"></path>
                            </svg>
                            Export Token
                        </button>
                        <button class="balance-btn" id="btn-request">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path>
                            </svg>
                            Request
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Recent Invoices</div>
                    </div>
                    <div id="recent-invoices">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                </div>
            </div>

            <!-- Invoices View -->
            <div class="view" id="view-invoices">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">All Invoices</div>
                    </div>
                    <div id="all-invoices">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                </div>
            </div>

            <!-- Store Settings View -->
            <div class="view" id="view-stores">
                <div id="store-settings-content">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Store Info</div>
                            <button class="btn btn-secondary" id="btn-edit-store">Edit</button>
                        </div>
                        <div class="card-body">
                            <div class="store-info-item">
                                <span class="store-info-label">Store Name</span>
                                <span class="store-info-value" id="store-settings-name">-</span>
                            </div>
                            <div class="store-info-item">
                                <span class="store-info-label">Store ID</span>
                                <span class="store-info-value" id="store-settings-id">-</span>
                            </div>
                            <div class="store-info-item">
                                <span class="store-info-label">Mint URL</span>
                                <span class="store-info-value" id="store-settings-mint">-</span>
                            </div>
                            <div class="store-info-item">
                                <span class="store-info-label">Unit</span>
                                <span class="store-info-value" id="store-settings-unit">-</span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">API Keys</div>
                            <button class="btn" id="btn-create-api-key">+ New</button>
                        </div>
                        <div id="store-api-keys">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Auto-Withdraw to Lightning Address</div>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Lightning Address</label>
                                <input type="text" class="form-input" id="auto-melt-address"
                                       placeholder="user@wallet.com">
                                <p class="form-help">e.g., yourname@walletofsatoshi.com, yourname@blink.sv</p>
                            </div>

                            <div class="toggle-container">
                                <span>Auto-withdraw when balance reaches threshold</span>
                                <label class="toggle">
                                    <input type="checkbox" id="auto-melt-enabled">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label" id="auto-melt-threshold-label">Threshold (<span class="unit-label">SAT</span>)</label>
                                <input type="number" class="form-input" id="auto-melt-threshold"
                                       value="2000" min="1" step="1">
                            </div>

                            <div style="padding: 0.75rem; background: rgba(247, 147, 26, 0.1); border-radius: 8px; margin-top: 1rem; font-size: 0.85rem; color: var(--text-secondary);">
                                Auto-withdrawals include a <?= CASHUPAY_DONATION_PERCENT ?>% donation to support CashuPayServer development. Use manual withdrawal to opt out.
                            </div>

                            <button class="btn btn-full" id="btn-save-auto-melt" style="margin-top: 1rem;">
                                Save Settings
                            </button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Exchange Rate Settings</div>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Primary Rate Provider</label>
                                <select class="form-input" id="price-provider-primary">
                                    <option value="coingecko">CoinGecko</option>
                                    <option value="binance">Binance</option>
                                    <option value="kraken">Kraken</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Backup Rate Provider</label>
                                <select class="form-input" id="price-provider-secondary">
                                    <option value="binance">Binance</option>
                                    <option value="coingecko">CoinGecko</option>
                                    <option value="kraken">Kraken</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Exchange Fee (%)</label>
                                <input type="number" class="form-input" id="exchange-fee-percent"
                                       value="0" min="0" max="10" step="0.1">
                                <p class="form-help">Fee added to currency conversions (0-10%)</p>
                            </div>
                            <button class="btn btn-full" id="btn-save-exchange-settings">Save Settings</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Danger Zone</div>
                        </div>
                        <div class="card-body">
                            <button class="btn btn-danger btn-full" id="btn-delete-store">
                                Delete Store
                            </button>
                        </div>
                    </div>
                </div>

                <div id="store-settings-empty" style="display: none;">
                    <div class="empty-state">
                        <div class="empty-state-icon">🏪</div>
                        <p>No store selected</p>
                        <button class="btn" id="btn-create-store" style="margin-top: 1rem;">Create Store</button>
                    </div>
                </div>
            </div>

            <!-- Settings View (Global) -->
            <div class="view" id="view-settings">
                <?php if (!Urls::isWordPress()): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Server URL Mode</div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Current Server URL</label>
                            <code id="current-server-url" style="display: block; background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px; font-size: 0.9rem; word-break: break-all; user-select: all;">
                                <?= htmlspecialchars(Urls::server()) ?>
                            </code>
                            <p class="form-help">This URL is used for e-commerce plugin integration</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">URL Mode</label>
                            <div id="url-mode-options" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <label style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px; cursor: pointer;">
                                    <input type="radio" name="url_mode" value="direct" id="url-mode-direct" style="width: 18px; height: 18px;">
                                    <span>
                                        <span style="display: block; font-weight: 500;">Direct URLs</span>
                                        <span style="display: block; font-size: 0.85rem; color: var(--text-secondary);">/api/v1/... (requires server rewrite rules)</span>
                                    </span>
                                    <span id="url-mode-direct-status" style="margin-left: auto; font-size: 0.75rem;"></span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: rgba(0,0,0,0.2); border-radius: 8px; cursor: pointer;">
                                    <input type="radio" name="url_mode" value="router" id="url-mode-router" style="width: 18px; height: 18px;">
                                    <span>
                                        <span style="display: block; font-weight: 500;">Router.php URLs</span>
                                        <span style="display: block; font-size: 0.85rem; color: var(--text-secondary);">/router.php/api/v1/... (works on any PHP host)</span>
                                    </span>
                                    <span id="url-mode-router-status" style="margin-left: auto; font-size: 0.75rem;"></span>
                                </label>
                            </div>
                        </div>

                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-secondary" id="btn-detect-url-mode" style="flex: 1;">
                                Re-detect
                            </button>
                            <button class="btn" id="btn-save-url-mode" style="flex: 1;">
                                Save
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Security</div>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-secondary btn-full" id="btn-set-pin">
                            Set/Change PIN
                        </button>
                        <button class="btn btn-danger btn-full" id="btn-logout" style="margin-top: 0.5rem;">
                            Logout
                        </button>
                    </div>
                </div>

                <div style="text-align: center; padding: 1.5rem 0; color: var(--text-muted); font-size: 0.8rem;">
                    CashuPayServer v<?= CASHUPAY_VERSION ?> &middot;
                    <a href="https://github.com/jooray/cashupayserver/releases" target="_blank" rel="noopener"
                       style="color: var(--text-secondary); text-decoration: none;">Check for updates</a>
                </div>
            </div>
        </main>

        <!-- Navigation -->
        <nav class="nav">
            <button class="nav-item active" data-view="dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                Home
            </button>
            <button class="nav-item" data-view="invoices">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                Invoices
            </button>
            <button class="nav-item" data-view="stores">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <path d="M9 22V12h6v10"></path>
                </svg>
                Store
            </button>
            <button class="nav-item" data-view="settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                Settings
            </button>
        </nav>
    </div>

    <!-- Modals -->
    <div class="modal-overlay" id="modal-withdraw">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Withdraw to Lightning</div>

            <div class="form-group">
                <label class="form-label">Destination</label>
                <input type="text" class="form-input" id="withdraw-address"
                       placeholder="user@wallet.com or lnbc1..." oninput="handleDestinationInput()">
                <p class="form-help" id="withdraw-destination-help">Lightning address or BOLT-11 invoice</p>
            </div>

            <div class="form-group" id="withdraw-amount-group">
                <label class="form-label">Amount (<span class="unit-label">SAT</span>)</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="number" class="form-input" id="withdraw-amount"
                           placeholder="0" min="1" step="1" style="flex: 1;">
                    <button type="button" class="btn btn-secondary" id="btn-withdraw-max" onclick="withdrawMax()">Max</button>
                </div>
                <p class="form-help" id="withdraw-amount-fiat-equiv" style="display: none;"></p>
                <p class="form-help">Available: <span id="withdraw-available">0</span><span id="withdraw-max-with-donation"></span></p>
            </div>

            <div class="form-group" style="padding: 0.75rem; background: rgba(247, 147, 26, 0.1); border-radius: 12px; border: 1px solid rgba(247, 147, 26, 0.2);">
                <label style="display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                    <input type="checkbox" id="withdraw-donate" checked style="width: 20px; height: 20px; margin-top: 0.1rem;" onchange="updateWithdrawInfo()">
                    <span>
                        <span style="display: block; font-weight: 500;">Support CashuPayServer</span>
                        <span style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">
                            Donate <?= CASHUPAY_DONATION_PERCENT ?>% (<span id="donate-amount">0</span> <span class="unit-label">SAT</span>) to help with development
                        </span>
                    </span>
                </label>
            </div>

            <button class="btn btn-full" id="btn-confirm-withdraw">Withdraw</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-withdraw')">Cancel</button>
        </div>
    </div>

    <div class="modal-overlay" id="modal-export">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Export Cashu Token</div>

            <div id="export-form">
                <div class="form-group">
                    <label class="form-label">Amount (<span class="unit-label">SAT</span>)</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="number" class="form-input" id="export-amount"
                               placeholder="0" min="1" step="1" style="flex: 1;" oninput="updateExportDonation()">
                        <button type="button" class="btn btn-secondary" id="btn-send-max" onclick="sendMax()">Max</button>
                    </div>
                    <p class="form-help">Available: <span id="export-available">0</span> <span class="unit-label">SAT</span></p>
                </div>

                <div class="form-group" style="padding: 0.75rem; background: rgba(247, 147, 26, 0.1); border-radius: 12px; border: 1px solid rgba(247, 147, 26, 0.2);">
                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                        <input type="checkbox" id="export-donate" checked style="width: 20px; height: 20px; margin-top: 0.1rem;" onchange="updateExportDonation()">
                        <span>
                            <span style="display: block; font-weight: 500;">Support CashuPayServer</span>
                            <span style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                Donate <?= CASHUPAY_DONATION_PERCENT ?>% (<span id="export-donate-amount">0</span> <span class="unit-label">SAT</span>) to help with development
                            </span>
                        </span>
                    </label>
                </div>

                <button class="btn btn-full" id="btn-confirm-export">Generate Token</button>
            </div>

            <div id="export-result" style="display: none;">
                <div class="modal-qr" id="export-qr"></div>
                <div class="token-display" id="export-token"></div>
                <button class="btn btn-full" id="btn-copy-token">Copy Token</button>
            </div>

            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-export')">Close</button>
        </div>
    </div>

    <div class="modal-overlay" id="modal-apikey">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title" id="modal-apikey-title">Create API Key</div>
            <div id="modal-apikey-content"></div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-request">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Request Payment</div>

            <div id="request-form">
                <div class="form-group">
                    <label class="form-label" id="request-amount-label">Amount</label>
                    <input type="number" class="form-input" id="request-amount"
                           placeholder="100" min="1" step="1">
                </div>

                <div class="form-group">
                    <label class="form-label">Description (optional)</label>
                    <input type="text" class="form-input" id="request-memo"
                           placeholder="Payment for...">
                </div>

                <button class="btn btn-full" id="btn-generate-request">Go to Checkout</button>
            </div>

            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-request')">Cancel</button>
        </div>
    </div>

    <div class="modal-overlay" id="modal-store">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title" id="store-modal-title">Store Details</div>
            <div id="store-modal-content"></div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-pin-setup">
        <div class="modal">
            <div class="modal-handle"></div>
            <div class="modal-title">Set PIN</div>
            <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                Enter a 4-digit PIN to quickly unlock the app.
            </p>

            <div class="form-group">
                <input type="password" class="form-input" id="new-pin" maxlength="4"
                       placeholder="Enter 4-digit PIN" pattern="[0-9]{4}">
            </div>

            <div class="form-group">
                <input type="password" class="form-input" id="confirm-pin" maxlength="4"
                       placeholder="Confirm PIN" pattern="[0-9]{4}">
            </div>

            <button class="btn btn-full" id="btn-save-pin">Save PIN</button>
            <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-pin-setup')">Cancel</button>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <!-- Mint Discovery Modal -->
    <div id="mint-discovery-modal" class="modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1100; justify-content: center; align-items: center;">
        <div class="modal-content" style="max-width: 700px; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; background: var(--card-bg); border-radius: 12px; padding: 1.5rem;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Discover Mints</h3>
                <button type="button" onclick="closeMintDiscoveryModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text);">&times;</button>
            </div>

            <div style="background: rgba(237, 137, 54, 0.15); border: 1px solid rgba(237, 137, 54, 0.4); border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <p style="font-size: 0.85rem; color: var(--warning); margin: 0 0 0.75rem 0; line-height: 1.5;">
                    Audit data is provided by independent third parties to help assess a mint's reliability over time. However, these results are informational only and do not guarantee the safety, solvency, or trustworthiness of any mint. Always conduct your own research and ensure you trust the mint operator before using their services. To be sure, run your own mint, this is the Bitcoin way!
                </p>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="mint-disclaimer-checkbox" onchange="onMintDisclaimerChange(this)" style="width: 18px; height: 18px;">
                    <span style="font-size: 0.85rem; color: var(--warning);">I understand the above</span>
                </label>
            </div>

            <div id="mint-discovery-status" style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                Loading mints from Nostr...
            </div>

            <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                <input type="text" id="mint-discovery-search" class="form-input" placeholder="Search mints..." style="flex: 1;" onkeyup="filterDiscoveredMints()">
                <select id="mint-discovery-unit-filter" class="form-input" style="width: auto;" onchange="filterDiscoveredMints()">
                    <option value="">All units</option>
                    <option value="sat">SAT</option>
                    <option value="eur">EUR</option>
                    <option value="usd">USD</option>
                </select>
                <button type="button" class="btn btn-secondary" onclick="startMintDiscovery()" style="white-space: nowrap;">Refresh</button>
            </div>

            <div id="mint-discovery-list" style="flex: 1; overflow-y: auto; max-height: 400px;">
                <p style="color: var(--text-secondary); font-size: 0.9rem; text-align: center; padding: 2rem;">
                    Loading...
                </p>
            </div>

            <div id="mint-discovery-loading" style="display: none; text-align: center; padding: 2rem;">
                <div class="spinner" style="width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--text-secondary);">Connecting to Nostr relays...</p>
            </div>
        </div>
    </div>

    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>mint-discovery.bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script type="module">
        // Import bc-ur library as ES module
        import { UR, UREncoder } from 'https://cdn.skypack.dev/@gandlaf21/bc-ur@1.1.12';

        // Expose to global scope for AnimatedQR class
        window.bcur = { UR, UREncoder };
    </script>
    <script src="<?= htmlspecialchars(Urls::assets('js/')) ?>animated-qr.js?v=4"></script>
    <script>
        // WordPress mode - skip lock screen
        const isWordPressMode = <?= Urls::isWordPress() ? 'true' : 'false' ?>;
        const adminUrl = <?= json_encode(Urls::admin()) ?>;
        const setupUrl = <?= json_encode(Urls::setup()) ?>;

        // URL mode config (embedded from PHP)
        const urlModeConfig = {
            isWordPress: <?= json_encode(Urls::isWordPress()) ?>,
            currentMode: <?= json_encode(Config::getUrlMode()) ?>,
            baseUrl: <?= json_encode(Urls::siteBase()) ?>
        };

        // State
        let isAuthenticated = false;
        let pin = '';
        let dashboardData = null;

        // Local Storage Keys
        const STORAGE_PIN = 'cashupay_pin';
        const STORAGE_AUTH = 'cashupay_auth';

        // M2: CSRF token helper - reads from meta tag dynamically
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        }

        // Server URL for e-commerce integration
        let serverUrl = <?= json_encode(Urls::server()) ?>;

        // Helper for POST requests with CSRF token
        async function postWithCsrf(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: body
            });
        }

        // Format amount for display based on unit (handles fiat decimals)
        function formatAmount(amount, unit) {
            const u = (unit || 'sat').toLowerCase();
            if (u === 'sat' || u === 'msat') {
                return Math.floor(amount).toLocaleString();
            }
            // Fiat: divide by 100 for cents → main unit
            return (amount / 100).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Parse user input to smallest unit (handles fiat decimals)
        function parseAmount(value, unit) {
            const u = (unit || 'sat').toLowerCase();
            const num = parseFloat(value) || 0;
            if (u === 'sat' || u === 'msat') {
                return Math.floor(num);
            }
            // Fiat: multiply by 100 for main unit → cents
            return Math.round(num * 100);
        }

        // Check if unit is fiat (needs decimal display)
        function isFiatUnit(unit) {
            const u = (unit || 'sat').toLowerCase();
            return u !== 'sat' && u !== 'msat';
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            checkAuth();
            setupEventListeners();
        });

        // Check authentication state
        function checkAuth() {
            if (isWordPressMode) {
                showApp();
                return;
            }

            const storedAuth = localStorage.getItem(STORAGE_AUTH);
            const storedPin = localStorage.getItem(STORAGE_PIN);

            if (storedAuth === 'true') {
                // Check if we have a PIN set
                if (storedPin) {
                    showLockScreen();
                } else {
                    // No PIN, show password entry
                    document.getElementById('pin-pad').style.display = 'none';
                    document.getElementById('pin-dots').style.display = 'none';
                    document.querySelector('.lock-subtitle').textContent = 'Enter your password';
                    showLockScreen();
                }
            } else {
                // Not authenticated, show password entry
                document.getElementById('pin-pad').style.display = 'none';
                document.getElementById('pin-dots').style.display = 'none';
                document.querySelector('.lock-subtitle').textContent = 'Enter your password';
                showLockScreen();
            }
        }

        function showLockScreen() {
            document.getElementById('lock-screen').classList.remove('hidden');
            document.getElementById('app').classList.remove('visible');
        }

        async function showApp() {
            document.getElementById('lock-screen').classList.add('hidden');
            document.getElementById('app').classList.add('visible');
            isAuthenticated = true;
            localStorage.setItem(STORAGE_AUTH, 'true');

            // Check for store_created parameter from setup.php redirect
            const urlParams = new URLSearchParams(window.location.search);
            const createdStoreId = urlParams.get('store_created');

            if (createdStoreId) {
                // Set as current store so it gets selected
                currentStoreId = createdStoreId;
                localStorage.setItem('selectedStoreId', createdStoreId);

                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            await loadDashboard();

            // Show success toast after dashboard loaded
            if (createdStoreId) {
                showToast('Store created successfully!', 'success');
            }
        }

        // PIN handling
        function handlePinInput(key) {
            const dots = document.querySelectorAll('.pin-dot');

            if (key === 'back') {
                pin = pin.slice(0, -1);
            } else if (pin.length < 4) {
                pin += key;
            }

            // Update dots
            dots.forEach((dot, i) => {
                dot.classList.toggle('filled', i < pin.length);
                dot.classList.remove('error');
            });

            // Check PIN when complete
            if (pin.length === 4) {
                const storedPin = localStorage.getItem(STORAGE_PIN);
                if (pin === storedPin) {
                    showApp();
                } else {
                    // Wrong PIN
                    dots.forEach(dot => dot.classList.add('error'));
                    setTimeout(() => {
                        pin = '';
                        dots.forEach(dot => {
                            dot.classList.remove('filled', 'error');
                        });
                    }, 500);
                }
            }
        }

        // Event Listeners
        function setupEventListeners() {
            // PIN pad
            document.querySelectorAll('.pin-key').forEach(key => {
                key.addEventListener('click', () => {
                    const value = key.dataset.key;
                    if (value) handlePinInput(value);
                });
            });

            // Password login
            document.getElementById('password-submit').addEventListener('click', async () => {
                const password = document.getElementById('password-input').value;
                try {
                    const response = await fetch(adminUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=login&password=${encodeURIComponent(password)}`
                    });

                    if (response.ok) {
                        const data = await response.json();

                        // Update CSRF token after login
                        if (data.csrfToken) {
                            const metaTag = document.querySelector('meta[name="csrf-token"]');
                            if (metaTag) {
                                metaTag.content = data.csrfToken;
                            }
                        }

                        showApp();
                    } else {
                        showToast('Invalid password', 'error');
                    }
                } catch (e) {
                    showToast('Login failed', 'error');
                }
            });

            document.getElementById('password-input').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    document.getElementById('password-submit').click();
                }
            });

            // Navigation
            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    const view = item.dataset.view;
                    switchView(view);
                });
            });

            // Store selector
            document.getElementById('store-select').addEventListener('change', onStoreSelectChange);

            // Header buttons
            document.getElementById('refresh-btn').addEventListener('click', loadDashboard);
            document.getElementById('lock-btn').addEventListener('click', lock);

            // Balance actions
            document.getElementById('btn-withdraw').addEventListener('click', () => openModal('modal-withdraw'));
            document.getElementById('btn-export').addEventListener('click', () => openModal('modal-export'));
            document.getElementById('btn-request').addEventListener('click', () => openModal('modal-request'));

            // Withdraw modal
            document.getElementById('btn-confirm-withdraw').addEventListener('click', handleWithdraw);
            document.getElementById('withdraw-amount').addEventListener('input', updateWithdrawInfo);

            // Export modal
            document.getElementById('btn-confirm-export').addEventListener('click', () => handleExport());
            document.getElementById('btn-copy-token').addEventListener('click', copyToken);

            // Request modal
            document.getElementById('btn-generate-request').addEventListener('click', handleGenerateRequest);

            // Settings
            document.getElementById('btn-save-auto-melt').addEventListener('click', saveAutoMelt);
            document.getElementById('btn-save-exchange-settings').addEventListener('click', saveExchangeSettings);
            document.getElementById('btn-set-pin').addEventListener('click', () => openModal('modal-pin-setup'));
            document.getElementById('btn-save-pin').addEventListener('click', savePin);
            document.getElementById('btn-logout').addEventListener('click', logout);

            // URL Mode settings (standalone only)
            if (document.getElementById('btn-detect-url-mode')) {
                document.getElementById('btn-detect-url-mode').addEventListener('click', detectUrlMode);
                document.getElementById('btn-save-url-mode').addEventListener('click', saveUrlMode);
                // Set radio button to saved mode (no auto-detection, user clicks Recheck if needed)
                initUrlModeSettings();
            }

            // Stores
            document.getElementById('btn-create-store').addEventListener('click', () => {
                window.location.href = setupUrl + '?mode=add_store';
            });

            // Store Settings
            document.getElementById('btn-edit-store').addEventListener('click', () => {
                if (currentStoreId) {
                    const store = dashboardData?.stores?.find(s => s.id === currentStoreId);
                    if (store) showStoreDetails(currentStoreId, store.name);
                }
            });
            document.getElementById('btn-create-api-key').addEventListener('click', () => {
                if (currentStoreId) {
                    createApiKey(currentStoreId);
                }
            });
            document.getElementById('btn-delete-store').addEventListener('click', () => {
                if (currentStoreId) {
                    deleteStore(currentStoreId);
                }
            });

            // Modal close on overlay click
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.classList.remove('visible');
                    }
                });
            });
        }

        // View switching
        function switchView(view) {
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

            document.getElementById(`view-${view}`).classList.add('active');
            document.querySelector(`[data-view="${view}"]`).classList.add('active');

            const titles = {
                dashboard: 'Dashboard',
                invoices: 'Invoices',
                stores: 'Store Settings',
                settings: 'Settings'
            };
            document.getElementById('header-text').textContent = titles[view];

            // Show/hide store selector based on view (hide on global settings)
            const storeSelector = document.getElementById('header-store-selector');
            if (view === 'settings') {
                storeSelector.style.display = 'none';
            } else {
                storeSelector.style.display = 'flex';
            }

            if (view === 'invoices') loadInvoices();
            if (view === 'stores') loadStoreSettings();
        }

        // Track currently selected store
        let currentStoreId = localStorage.getItem('selectedStoreId') || null;

        // Handle store selection change
        function onStoreSelectChange() {
            const select = document.getElementById('store-select');
            const value = select.value;

            // Handle "Create Store" option
            if (value === '__create__') {
                // Reset to previous selection
                if (currentStoreId) {
                    select.value = currentStoreId;
                } else if (dashboardData?.stores?.length > 0) {
                    select.value = dashboardData.stores[0].id;
                }
                // Redirect to setup in add_store mode
                window.location.href = setupUrl + '?mode=add_store';
                return;
            }

            currentStoreId = value || null;
            if (currentStoreId) {
                localStorage.setItem('selectedStoreId', currentStoreId);
            } else {
                localStorage.removeItem('selectedStoreId');
            }
            loadDashboard();

            // If on store settings view, reload store settings
            if (document.getElementById('view-stores').classList.contains('active')) {
                loadStoreSettings();
            }
            // If on invoices view, reload invoices
            if (document.getElementById('view-invoices').classList.contains('active')) {
                loadInvoices();
            }
        }

        // Populate store selector dropdown
        function updateStoreSelector(stores) {
            const select = document.getElementById('store-select');
            const previousValue = select.value;

            select.innerHTML = '';

            if (!stores || stores.length === 0) {
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = 'No stores configured';
                select.appendChild(emptyOpt);
            } else {
                stores.forEach(store => {
                    const opt = document.createElement('option');
                    opt.value = store.id;
                    opt.textContent = store.name + (store.isConfigured ? '' : ' (not configured)');
                    select.appendChild(opt);
                });
            }

            // Add "Create Store" option
            const createOpt = document.createElement('option');
            createOpt.value = '__create__';
            createOpt.textContent = '+ Create Store';
            select.appendChild(createOpt);

            select.disabled = false;

            // Restore selection or select first store
            if (currentStoreId && stores && stores.find(s => s.id === currentStoreId)) {
                select.value = currentStoreId;
            } else if (stores && stores.length > 0) {
                select.value = stores[0].id;
                currentStoreId = stores[0].id;
                localStorage.setItem('selectedStoreId', currentStoreId);
            }
        }

        // Data loading
        async function loadDashboard() {
            try {
                // Fetch with store_id if one is selected
                let url = adminUrl + '?api=dashboard';
                if (currentStoreId) {
                    url += `&store_id=${encodeURIComponent(currentStoreId)}`;
                }

                const response = await fetch(url);

                // Handle stale store_id from localStorage (store deleted or new database)
                if (response.status === 404 && currentStoreId) {
                    currentStoreId = null;
                    localStorage.removeItem('selectedStoreId');
                    return loadDashboard(); // Retry without store_id
                }

                if (!response.ok) throw new Error('Failed to load');

                dashboardData = await response.json();

                // Update store selector with available stores
                updateStoreSelector(dashboardData.stores);

                // Handle case when no store is selected
                if (dashboardData.noStoreSelected) {
                    if (dashboardData.stores && dashboardData.stores.length > 0) {
                        // Auto-select the first store and reload
                        currentStoreId = dashboardData.stores[0].id;
                        localStorage.setItem('selectedStoreId', currentStoreId);
                        return loadDashboard();
                    } else {
                        // No stores configured
                        document.getElementById('balance-amount').textContent = '--';
                        document.getElementById('balance-unit').textContent = '';
                        document.querySelector('.balance-label').textContent = 'No stores configured';
                        document.getElementById('withdraw-available').textContent = '0';
                        document.getElementById('export-available').textContent = '0';
                        renderInvoices('recent-invoices', []);
                        return;
                    }
                }

                // Verify the selected store still exists
                if (dashboardData.storeId) {
                    currentStoreId = dashboardData.storeId;
                    localStorage.setItem('selectedStoreId', currentStoreId);
                }

                const mintUnit = dashboardData.mintUnit || 'sat';
                const unitLabel = mintUnit.toUpperCase();

                // Update balance with proper decimal formatting
                document.getElementById('balance-amount').textContent =
                    formatAmount(dashboardData.balance ?? 0, mintUnit);
                document.getElementById('balance-unit').textContent = unitLabel;

                // Show mint status warning if unreachable
                const balanceLabel = document.querySelector('.balance-label');
                if (dashboardData.mintUnreachable) {
                    balanceLabel.innerHTML = 'Total Balance <span style="color: #f59e0b; font-size: 0.75rem;">(mint offline - cached)</span>';
                    // Disable withdraw/export buttons when mint is unreachable
                    document.querySelectorAll('.withdraw-btn, .export-btn').forEach(btn => {
                        btn.disabled = true;
                        btn.title = 'Mint is currently unreachable';
                    });
                } else {
                    balanceLabel.textContent = 'Total Balance';
                    document.querySelectorAll('.withdraw-btn, .export-btn').forEach(btn => {
                        btn.disabled = false;
                        btn.title = '';
                    });
                }

                // Update available amounts in modals with proper formatting
                document.getElementById('withdraw-available').textContent =
                    formatAmount(dashboardData.balance ?? 0, mintUnit);
                document.getElementById('export-available').textContent =
                    formatAmount(dashboardData.exportAvailable ?? 0, mintUnit);

                // Update unit labels in modals
                document.querySelectorAll('.unit-label').forEach(el => el.textContent = unitLabel);

                // Update auto-melt threshold unit label and value
                const thresholdLabel = document.getElementById('auto-melt-threshold-label');
                if (thresholdLabel) thresholdLabel.textContent = `Threshold (${unitLabel})`;

                // Update auto-melt settings (per-store)
                if (dashboardData.autoMelt) {
                    document.getElementById('auto-melt-address').value = dashboardData.autoMelt.address || '';
                    document.getElementById('auto-melt-enabled').checked = dashboardData.autoMelt.enabled;
                    // Format threshold for display (fiat needs decimal)
                    const thresholdInput = document.getElementById('auto-melt-threshold');
                    thresholdInput.value = isFiatUnit(mintUnit)
                        ? (dashboardData.autoMelt.threshold / 100).toFixed(2)
                        : dashboardData.autoMelt.threshold;
                    // Set input attributes for fiat
                    if (isFiatUnit(mintUnit)) {
                        thresholdInput.step = '0.01';
                        thresholdInput.min = '0.01';
                    } else {
                        thresholdInput.step = '1';
                        thresholdInput.min = '1';
                    }
                }

                // Render recent invoices
                renderInvoices('recent-invoices', dashboardData.invoices || []);

            } catch (e) {
                console.error(e);
                showToast('Failed to load dashboard', 'error');
            }
        }

        async function loadInvoices() {
            try {
                let url = adminUrl + '?api=invoices&limit=100';
                if (currentStoreId) {
                    url += `&store_id=${encodeURIComponent(currentStoreId)}`;
                }
                const response = await fetch(url);
                const invoices = await response.json();
                renderInvoices('all-invoices', invoices);
            } catch (e) {
                showToast('Failed to load invoices', 'error');
            }
        }

        async function loadStoreSettings() {
            const contentEl = document.getElementById('store-settings-content');
            const emptyEl = document.getElementById('store-settings-empty');

            if (!currentStoreId) {
                contentEl.style.display = 'none';
                emptyEl.style.display = 'block';
                return;
            }

            contentEl.style.display = 'block';
            emptyEl.style.display = 'none';

            try {
                // Load store details
                const response = await fetch(`${adminUrl}?api=stores`);
                const stores = await response.json();
                const store = stores.find(s => s.id === currentStoreId);

                if (!store) {
                    contentEl.style.display = 'none';
                    emptyEl.style.display = 'block';
                    return;
                }

                // Update store info display
                document.getElementById('store-settings-name').textContent = store.name;
                document.getElementById('store-settings-id').textContent = store.id;
                document.getElementById('store-settings-mint').textContent = store.mint_url || '-';
                document.getElementById('store-settings-unit').textContent = (store.mint_unit || 'sat').toUpperCase();

                // Load auto-melt settings from dashboard data
                if (dashboardData && dashboardData.autoMelt) {
                    const mintUnit = dashboardData.mintUnit || 'sat';
                    document.getElementById('auto-melt-address').value = dashboardData.autoMelt.address || '';
                    document.getElementById('auto-melt-enabled').checked = dashboardData.autoMelt.enabled;
                    const thresholdInput = document.getElementById('auto-melt-threshold');
                    thresholdInput.value = isFiatUnit(mintUnit)
                        ? (dashboardData.autoMelt.threshold / 100).toFixed(2)
                        : dashboardData.autoMelt.threshold;
                    // Update threshold label
                    document.querySelectorAll('.unit-label').forEach(el => {
                        el.textContent = mintUnit.toUpperCase();
                    });
                    // Set input attributes for fiat
                    if (isFiatUnit(mintUnit)) {
                        thresholdInput.step = '0.01';
                        thresholdInput.min = '0.01';
                    } else {
                        thresholdInput.step = '1';
                        thresholdInput.min = '1';
                    }
                }

                // Load exchange rate settings from store data
                document.getElementById('price-provider-primary').value = store.price_provider_primary || 'coingecko';
                document.getElementById('price-provider-secondary').value = store.price_provider_secondary || 'binance';
                document.getElementById('exchange-fee-percent').value = store.exchange_fee_percent || 0;

                // Load API keys
                loadStoreApiKeys();

            } catch (e) {
                console.error(e);
                showToast('Failed to load store settings', 'error');
            }
        }

        async function loadStoreApiKeys() {
            const container = document.getElementById('store-api-keys');
            if (!currentStoreId) {
                container.innerHTML = '<div class="empty-state"><p>No store selected</p></div>';
                return;
            }

            try {
                const response = await fetch(`${adminUrl}?api=api_keys&store_id=${encodeURIComponent(currentStoreId)}`);
                const keys = await response.json();

                if (!keys || keys.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">🔑</div>
                            <p>No API keys yet</p>
                        </div>
                    `;
                    return;
                }

                container.innerHTML = keys.map(key => `
                    <div class="list-item">
                        <div class="list-icon" style="background: rgba(247, 147, 26, 0.2);">🔑</div>
                        <div class="list-content">
                            <div class="list-title">${key.label || 'API Key'}</div>
                            <div class="list-subtitle">ID: ${key.id.substring(0, 8)}...</div>
                        </div>
                        <button class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" onclick="deleteApiKeyFromSettings('${key.id}')">Delete</button>
                    </div>
                `).join('');
            } catch (e) {
                container.innerHTML = '<div class="empty-state"><p>Failed to load API keys</p></div>';
            }
        }

        // Rendering
        function renderInvoices(containerId, invoices) {
            const container = document.getElementById(containerId);

            if (!invoices || invoices.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📄</div>
                        <p>No invoices yet</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = invoices.map(inv => {
                const statusClass = inv.status.toLowerCase();
                const icon = inv.status === 'Settled' ? '✓' :
                             inv.status === 'New' ? '⏳' : '✕';
                const date = new Date(inv.createdTime * 1000).toLocaleDateString();
                const description = inv.metadata?.itemDesc || '';

                return `
                    <div class="list-item">
                        <div class="list-icon ${statusClass}">${icon}</div>
                        <div class="list-content">
                            <div class="list-title">${description ? escapeHtml(description) : inv.id}</div>
                            <div class="list-subtitle">${date}${description ? ' · ' + inv.id : ''}</div>
                        </div>
                        <div class="list-amount">
                            <div class="list-amount-value">${inv.amount} ${inv.currency}</div>
                            <div class="list-amount-status ${statusClass}">${inv.status}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderStores(stores) {
            // Legacy function - no longer used for store list view
            // Kept for compatibility if needed elsewhere
            const container = document.getElementById('stores-list');
            if (!container) return;

            container.innerHTML = stores.map(store => `
                <div class="list-item" onclick="showStoreDetails('${store.id}', '${store.name}')">
                    <div class="list-icon" style="background: rgba(247, 147, 26, 0.2);">🏪</div>
                    <div class="list-content">
                        <div class="list-title">${store.name}</div>
                        <div class="list-subtitle">${store.id}</div>
                    </div>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </div>
            `).join('');
        }

        // Actions
        async function handleWithdraw() {
            const address = document.getElementById('withdraw-address').value;
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const isFiatMint = isFiatUnit(mintUnit);
            const isBolt11 = /^ln(bc|tb|tbs|bcrt)[0-9]/i.test(address);
            const isLnAddress = !isBolt11 && address.includes('@');
            const isLightningDestination = isBolt11 || isLnAddress;

            // For ALL Lightning destinations with fiat mints, amount is in SATS
            // For sat mints or non-Lightning destinations, amount is in mint units
            let amount;
            let amountIsSats = '0';

            if (isLightningDestination && isFiatMint) {
                // Amount is in sats (integer) for all Lightning destinations
                amount = parseInt(document.getElementById('withdraw-amount').value, 10) || 0;
                amountIsSats = '1';
            } else {
                // Amount is in mint units (parse normally)
                amount = parseAmount(document.getElementById('withdraw-amount').value, mintUnit);
            }

            const donate = document.getElementById('withdraw-donate').checked ? '1' : '0';

            if (!address || !amount) {
                showToast('Please fill all fields', 'error');
                return;
            }

            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }

            const withdrawBtn = document.getElementById('btn-confirm-withdraw');
            const withdrawBtnOrigText = withdrawBtn.textContent;
            withdrawBtn.textContent = 'Processing, please wait...';
            withdrawBtn.disabled = true;

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=manual_melt&store_id=${encodeURIComponent(currentStoreId)}&address=${encodeURIComponent(address)}&amount=${amount}&donate=${donate}&amount_is_sats=${amountIsSats}`
                );

                const result = await response.json();
                const unitLabel = mintUnit.toUpperCase();

                if (response.ok) {
                    // For all Lightning destinations with fiat mint, show amount in sats
                    let msg;
                    if (isLightningDestination && isFiatMint) {
                        msg = `Sent ${amount} SAT!`;
                    } else {
                        msg = `Sent ${formatAmount(result.amountPaid, mintUnit)} ${unitLabel}!`;
                    }

                    if (result.donationSuccess && result.donated > 0) {
                        showToast(msg, 'success');
                        setTimeout(() => {
                            showToast(`Thank you for your ${formatAmount(result.donated, mintUnit)} ${unitLabel} donation!`, 'success');
                        }, 1500);
                    } else {
                        showToast(msg, 'success');
                    }
                    closeModal('modal-withdraw');
                    loadDashboard();
                } else {
                    showToast(result.error || 'Withdrawal failed', 'error');
                    withdrawBtn.textContent = withdrawBtnOrigText;
                    withdrawBtn.disabled = false;
                }
            } catch (e) {
                showToast('Withdrawal failed', 'error');
                withdrawBtn.textContent = withdrawBtnOrigText;
                withdrawBtn.disabled = false;
            }
        }

        // Donation percentage constant
        const DONATION_PERCENT = <?= CASHUPAY_DONATION_PERCENT ?>;

        // API base URL for Greenfield API calls
        let API_BASE_URL = <?= json_encode(Urls::api()) ?>;

        // Lightning routing fee buffer (same as auto-melt uses)
        const LN_FEE_BUFFER_PERCENT = 2; // 2%
        const LN_FEE_BUFFER_MIN = 10;    // minimum 10 sats

        // Track if bolt-11 has fixed amount
        let bolt11FixedAmount = null;
        let bolt11FeeEstimate = null;
        let bolt11MeltError = false;
        let bolt11FetchTimeout = null;

        // Handle destination input (detect bolt-11 and auto-fill amount)
        function handleDestinationInput() {
            const input = document.getElementById('withdraw-address').value.trim();
            const helpText = document.getElementById('withdraw-destination-help');
            const amountInput = document.getElementById('withdraw-amount');

            // Clear any pending timeout
            if (bolt11FetchTimeout) {
                clearTimeout(bolt11FetchTimeout);
                bolt11FetchTimeout = null;
            }

            // Check if it looks like a bolt-11 invoice
            const isBolt11 = /^ln(bc|tb|tbs|bcrt)[0-9]/i.test(input);
            const isLightningAddress = !isBolt11 && input.includes('@');

            if (isBolt11 && input.length > 20) {
                helpText.textContent = 'Checking invoice amount...';
                helpText.style.color = 'var(--accent)';

                // Debounce the fetch
                bolt11FetchTimeout = setTimeout(async () => {
                    try {
                        const response = await postWithCsrf(adminUrl,
                            `action=get_bolt11_amount&bolt11=${encodeURIComponent(input)}&store_id=${encodeURIComponent(currentStoreId || '')}`
                        );
                        const result = await response.json();

                        if (result.meltError) {
                            // Mint rejected the invoice - withdrawal won't work
                            bolt11FixedAmount = null;
                            bolt11FeeEstimate = null;
                            bolt11MeltError = true;
                            amountInput.disabled = true;
                            document.getElementById('btn-withdraw-max').disabled = true;
                            const amountInfo = result.amountSats > 0 ? ` (${result.amountSats.toLocaleString()} SAT)` : '';
                            helpText.textContent = `Mint error${amountInfo}: ${result.meltError}`;
                            helpText.style.color = 'var(--error)';
                            updateWithdrawInfo();
                        } else if (result.hasAmount && result.amountSats > 0) {
                            // Invoice has encoded amount - auto-fill and disable amount editing
                            bolt11FixedAmount = result.amountSats;
                            bolt11FeeEstimate = result.feeEstimate;
                            bolt11MeltError = false;
                            amountInput.value = result.amountSats;
                            amountInput.disabled = true;
                            document.getElementById('btn-withdraw-max').disabled = true;
                            helpText.textContent = `Invoice amount: ${result.amountSats.toLocaleString()} SAT (fixed)`;
                            helpText.style.color = 'var(--success)';
                            updateWithdrawInfo();
                        } else {
                            // Amountless invoice - enable amount field
                            bolt11FixedAmount = null;
                            bolt11FeeEstimate = null;
                            bolt11MeltError = false;
                            amountInput.disabled = false;
                            document.getElementById('btn-withdraw-max').disabled = false;
                            helpText.textContent = 'Amountless invoice - enter amount below';
                            helpText.style.color = 'var(--text-secondary)';
                        }
                    } catch (e) {
                        console.error('Failed to check bolt-11:', e);
                        bolt11FixedAmount = null;
                        bolt11FeeEstimate = null;
                        bolt11MeltError = false;
                        amountInput.disabled = false;
                        document.getElementById('btn-withdraw-max').disabled = false;
                        helpText.textContent = 'Could not verify invoice';
                        helpText.style.color = 'var(--error)';
                    }
                }, 300);
            } else if (isLightningAddress) {
                // Lightning address detected
                bolt11FixedAmount = null;
                bolt11FeeEstimate = null;
                bolt11MeltError = false;
                amountInput.disabled = false;
                document.getElementById('btn-withdraw-max').disabled = false;
                helpText.textContent = 'Lightning address detected';
                helpText.style.color = 'var(--accent)';
            } else {
                // Incomplete input or unknown format
                bolt11FixedAmount = null;
                bolt11FeeEstimate = null;
                bolt11MeltError = false;
                amountInput.disabled = false;
                document.getElementById('btn-withdraw-max').disabled = false;
                helpText.textContent = 'Lightning address or BOLT-11 invoice';
                helpText.style.color = 'var(--text-secondary)';
            }

            updateWithdrawInfo();
        }

        // Track last estimate for display
        let lastWithdrawEstimate = null;
        let withdrawEstimateTimeout = null;

        // Update withdraw info (donation amount and total from wallet)
        function updateWithdrawInfo() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const donate = document.getElementById('withdraw-donate').checked;
            const balance = dashboardData?.balance || 0;
            const isFiatMint = isFiatUnit(mintUnit);

            // Lightning withdrawals ALWAYS use SAT (Lightning Network operates in satoshis)
            const amountSats = parseInt(document.getElementById('withdraw-amount').value, 10) || 0;
            const destination = document.getElementById('withdraw-address').value.trim();

            // Update fiat equivalent display for fiat mints
            const equivEl = document.getElementById('withdraw-amount-fiat-equiv');
            if (isFiatMint && amountSats > 0) {
                // If we have an estimate from the mint, use actual cost
                if (lastWithdrawEstimate && lastWithdrawEstimate.amountSats === amountSats) {
                    const cost = lastWithdrawEstimate.costMintUnit;
                    const fee = lastWithdrawEstimate.feeReserve;
                    equivEl.innerHTML = `Cost: ${formatAmount(cost, mintUnit)} + ${formatAmount(fee, mintUnit)} fee = <strong>${formatAmount(cost + fee, mintUnit)} ${mintUnit.toUpperCase()}</strong>`;
                    equivEl.style.display = 'block';
                } else if (dashboardData?.balanceInSats > 0) {
                    // Fallback to our approximation
                    const rate = dashboardData.balance / dashboardData.balanceInSats;
                    const fiatAmount = amountSats * rate;
                    equivEl.textContent = `≈ ${formatAmount(Math.round(fiatAmount), mintUnit)} ${mintUnit.toUpperCase()} (estimate)`;
                    equivEl.style.display = 'block';
                } else {
                    equivEl.style.display = 'none';
                }
            } else {
                equivEl.style.display = 'none';
            }

            // Update donation amount display
            let donationAmount = 0;
            let donationUnit = 'sat';

            if (donate && amountSats > 0) {
                if (isFiatMint && dashboardData?.balance > 0 && dashboardData?.balanceInSats > 0) {
                    // Fiat mint: estimate donation in mint units using exchange rate
                    const rate = dashboardData.balance / dashboardData.balanceInSats;
                    const amountMintUnit = Math.round(amountSats * rate);
                    donationAmount = Math.max(1, Math.floor(amountMintUnit * DONATION_PERCENT / 100));
                    // Don't donate more than 10%
                    donationAmount = Math.min(donationAmount, Math.floor(amountMintUnit * 0.1));
                    donationUnit = dashboardData.mintUnit || 'sat';
                } else if (!isFiatMint) {
                    // Sat mint: donation is in sats
                    donationAmount = Math.max(1, Math.floor(amountSats * DONATION_PERCENT / 100));
                    // Don't donate more than 10%
                    donationAmount = Math.min(donationAmount, Math.floor(amountSats * 0.1));
                }
            }
            document.getElementById('donate-amount').textContent = formatAmount(donationAmount, donationUnit);

            // Update total from wallet display
            const maxWithDonation = document.getElementById('withdraw-max-with-donation');

            if (amountSats > 0) {
                let notes = [];

                if (isFiatMint) {
                    // Fiat mint: show actual cost if available
                    if (lastWithdrawEstimate && lastWithdrawEstimate.amountSats === amountSats) {
                        const total = lastWithdrawEstimate.totalCost;
                        if (donate) {
                            notes.push(`from ${mintUnit.toUpperCase()} balance + donation`);
                        } else {
                            notes.push(`from ${mintUnit.toUpperCase()} balance`);
                        }
                    } else {
                        notes.push('paid from ' + mintUnit.toUpperCase() + ' balance');
                        notes.push('+ LN fees');
                        if (donate) {
                            notes.push('+ donation');
                        }
                    }
                } else {
                    // Sat mint: donation + LN fees
                    if (donationAmount > 0) {
                        notes.push(`+${formatAmount(donationAmount, 'sat')} donation`);
                    }
                    notes.push('+ LN fees');
                }

                if (notes.length > 0) {
                    maxWithDonation.textContent = ` (${notes.join(', ')})`;
                } else {
                    maxWithDonation.textContent = '';
                }
            } else {
                maxWithDonation.textContent = '';
            }

            // Validate and update button state
            const withdrawBtn = document.getElementById('btn-confirm-withdraw');

            if (bolt11MeltError) {
                // Mint rejected the invoice - disable withdraw
                withdrawBtn.disabled = true;
            } else if (isFiatMint) {
                // For fiat mint, validate against actual cost if we have estimate
                if (lastWithdrawEstimate && lastWithdrawEstimate.amountSats === amountSats) {
                    withdrawBtn.disabled = amountSats < 1 || !lastWithdrawEstimate.canAfford;
                } else {
                    // Just check that amount > 0 (server will validate)
                    withdrawBtn.disabled = amountSats < 1;
                }
            } else if (bolt11FixedAmount && bolt11FeeEstimate !== null) {
                // We have an actual fee from the melt quote - use it instead of generic buffer
                const totalNeeded = amountSats + bolt11FeeEstimate;
                const donationAmount = donate ? Math.max(1, Math.floor(amountSats * DONATION_PERCENT / 100)) : 0;
                withdrawBtn.disabled = amountSats < 1 || (totalNeeded + donationAmount) > balance;
            } else {
                // For sat mint, validate against balance with generic fee buffer
                let maxSats = balance;

                // Reserve buffer for Lightning routing fees
                const feeBuffer = Math.max(LN_FEE_BUFFER_MIN, Math.floor(balance * LN_FEE_BUFFER_PERCENT / 100));
                maxSats = balance - feeBuffer;

                // Account for donation percentage
                if (donate) {
                    maxSats = Math.floor(maxSats / (1 + DONATION_PERCENT / 100));
                }

                const isValid = amountSats > 0 && amountSats <= maxSats;
                withdrawBtn.disabled = !isValid;
            }

            // For fiat mints, debounce fetch actual cost from mint
            if (isFiatMint && amountSats > 0 && destination) {
                clearTimeout(withdrawEstimateTimeout);
                withdrawEstimateTimeout = setTimeout(async () => {
                    const estimate = await getWithdrawalEstimate(destination, amountSats);
                    if (estimate) {
                        lastWithdrawEstimate = estimate;
                        updateWithdrawInfo(); // Re-render with actual cost
                    }
                }, 500);
            }
        }

        // Get withdrawal estimate from mint (actual cost at mint's exchange rate)
        async function getWithdrawalEstimate(destination, amountSats) {
            if (!currentStoreId || !destination || amountSats < 1) {
                return null;
            }

            try {
                const response = await fetch(adminUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_withdrawal_estimate&store_id=${encodeURIComponent(currentStoreId)}&destination=${encodeURIComponent(destination)}&amount_sats=${amountSats}`
                });
                const data = await response.json();
                return data.success ? data : null;
            } catch (e) {
                console.error('Failed to get withdrawal estimate:', e);
                return null;
            }
        }

        // Withdraw max amount (always in SAT for Lightning withdrawals)
        async function withdrawMax() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const donate = document.getElementById('withdraw-donate').checked;
            const isFiatMint = isFiatUnit(mintUnit);
            const destination = document.getElementById('withdraw-address').value.trim();

            // Get balance in SAT
            let maxSats;
            if (isFiatMint) {
                maxSats = dashboardData?.balanceInSats;
                if (!maxSats || maxSats <= 0) {
                    showToast('Could not calculate max amount (no exchange rate)', 'error');
                    return;
                }

                // For fiat mints with a destination, query mint for actual max
                if (destination) {
                    const estimate = await getWithdrawalEstimate(destination, maxSats);
                    if (estimate && estimate.maxAffordableSats > 0) {
                        maxSats = estimate.maxAffordableSats;
                        // Apply fee buffer to mint's calculated max
                        const feeBuffer = Math.max(LN_FEE_BUFFER_MIN, Math.floor(maxSats * LN_FEE_BUFFER_PERCENT / 100));
                        maxSats = maxSats - feeBuffer;
                        document.getElementById('withdraw-amount').value = Math.max(0, maxSats);
                        updateWithdrawInfo();
                        return;
                    }
                }
            } else {
                maxSats = dashboardData?.balance || 0;
            }

            // Reserve buffer for Lightning routing fees
            const feeBuffer = Math.max(LN_FEE_BUFFER_MIN, Math.floor(maxSats * LN_FEE_BUFFER_PERCENT / 100));
            maxSats = maxSats - feeBuffer;

            // Account for donation percentage (only for sat mints - fiat mint donation is calculated server-side)
            if (donate && !isFiatMint) {
                maxSats = Math.floor(maxSats / (1 + DONATION_PERCENT / 100));
            }

            document.getElementById('withdraw-amount').value = Math.max(0, maxSats);
            updateWithdrawInfo();
        }

        // Update donation amount display when withdraw amount changes
        function updateDonationAmount() {
            updateWithdrawInfo();
        }

        // Update export donation display
        function updateExportDonation() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            // Parse as smallest unit (cents for fiat, sats for bitcoin)
            const amountSmallest = parseAmount(document.getElementById('export-amount').value, mintUnit);
            const donate = document.getElementById('export-donate').checked;

            let donationAmount = 0;
            if (donate && amountSmallest > 0) {
                donationAmount = Math.max(1, Math.floor(amountSmallest * DONATION_PERCENT / 100));
                // Don't donate more than 10%
                donationAmount = Math.min(donationAmount, Math.floor(amountSmallest * 0.1));
            }
            // Display donation in user-friendly format
            document.getElementById('export-donate-amount').textContent = formatAmount(donationAmount, mintUnit);

            // Validate and update button state
            const exportBtn = document.getElementById('btn-confirm-export');
            const exportAvailable = dashboardData?.exportAvailable || 0;

            // Calculate max considering donation (in smallest unit)
            let maxAmountSmallest = exportAvailable;
            if (donate) {
                maxAmountSmallest = Math.floor(exportAvailable / (1 + DONATION_PERCENT / 100));
            }

            const isValid = amountSmallest > 0 && amountSmallest <= maxAmountSmallest;
            exportBtn.disabled = !isValid;
        }

        // Track export state for claiming detection
        let exportCheckInterval = null;
        let exportSecrets = null;

        async function handleExport(forceAmount = null) {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const amount = forceAmount || parseAmount(document.getElementById('export-amount').value, mintUnit);
            const donate = document.getElementById('export-donate').checked ? '1' : '0';

            const minAmount = isFiatUnit(mintUnit) ? 1 : 1; // 1 cent or 1 sat minimum
            if (!amount || amount < minAmount) {
                showToast('Please enter an amount', 'error');
                return;
            }

            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }

            const exportBtn = document.getElementById('btn-confirm-export');
            const exportBtnOrigText = exportBtn.textContent;
            exportBtn.textContent = 'Processing, please wait...';
            exportBtn.disabled = true;

            try {
                let postData = `action=export_token&store_id=${encodeURIComponent(currentStoreId)}&amount=${amount}&donate=${donate}`;
                if (forceAmount !== null) {
                    postData += `&force_amount=${forceAmount}`;
                }

                const response = await postWithCsrf(adminUrl, postData);
                const result = await response.json();

                // Handle mint unreachable - needs user confirmation for different amount
                if (result.error === 'mint_unreachable_change_needed') {
                    const unitLabel = mintUnit.toUpperCase();
                    const requestedDisplay = formatAmount(result.requested, mintUnit);
                    const availableDisplay = formatAmount(result.available, mintUnit);

                    const confirmed = confirm(
                        `Mint Unreachable - Offline Export\n\n` +
                        `The mint is currently unreachable, so exact change cannot be provided.\n\n` +
                        `Requested: ${requestedDisplay} ${unitLabel}\n` +
                        `Available (closest match): ${availableDisplay} ${unitLabel}\n\n` +
                        `Export ${availableDisplay} ${unitLabel} instead?`
                    );

                    if (confirmed) {
                        // Re-submit with force_amount
                        exportBtn.textContent = exportBtnOrigText;
                        exportBtn.disabled = false;
                        handleExport(result.available);
                    } else {
                        showToast('Export cancelled. Try using "Max" for exact match.', 'info');
                        exportBtn.textContent = exportBtnOrigText;
                        exportBtn.disabled = false;
                    }
                    return;
                }

                if (response.ok && result.token) {
                    // Show result
                    document.getElementById('export-form').style.display = 'none';
                    document.getElementById('export-result').style.display = 'block';
                    document.getElementById('export-token').textContent = result.token;

                    // Store secrets for claim detection
                    exportSecrets = result.secrets;

                    // Show offline export notice or donation toast
                    if (result.offlineExport && result.mintUnreachable) {
                        setTimeout(() => {
                            showToast('Token exported offline (mint unreachable). Claim detection disabled.', 'info');
                        }, 500);
                        // Don't start claim detection - mint is unreachable
                    } else if (result.offlineExport) {
                        // Had exact change locally - mint wasn't needed but is reachable
                        // Start claim detection since mint is available
                        if (exportCheckInterval) clearInterval(exportCheckInterval);
                        exportCheckInterval = setInterval(checkIfTokenClaimed, 5000);
                    } else {
                        // Show donation toast if donation was made
                        if (result.donationSuccess && result.donated > 0) {
                            const unitLabel = mintUnit.toUpperCase();
                            setTimeout(() => {
                                showToast(`Thank you for your ${formatAmount(result.donated, mintUnit)} ${unitLabel} donation!`, 'success');
                            }, 500);
                        }

                        // Start checking if token has been claimed (every 5 seconds)
                        if (exportCheckInterval) clearInterval(exportCheckInterval);
                        exportCheckInterval = setInterval(checkIfTokenClaimed, 5000);
                    }

                    // Generate QR code (single or animated based on size)
                    const qrContainer = document.getElementById('export-qr');
                    qrContainer.innerHTML = '';

                    try {
                        // Check if we need animated QR based on token size
                        if (AnimatedQR.needsAnimation(result.token)) {
                            // Large token - use NUT-16 animated QR with UR encoding
                            const animatedQr = new AnimatedQR(qrContainer, {
                                frameRate: 200,
                                maxFragmentLen: 200,
                                qrSize: 280,
                                errorCorrection: 'M'
                            });

                            if (!animatedQr.encode(result.token)) {
                                throw new Error('Failed to encode animated QR');
                            }
                        } else {
                            // Small token - use single static QR
                            if (typeof QRious !== 'undefined') {
                                const canvas = document.createElement('canvas');
                                qrContainer.appendChild(canvas);

                                const maxSize = Math.min(280, window.innerWidth - 80);

                                new QRious({
                                    element: canvas,
                                    value: result.token,
                                    size: maxSize,
                                    backgroundAlpha: 1,
                                    foreground: '#000000',
                                    background: '#ffffff',
                                    level: 'L'
                                });
                            } else {
                                throw new Error('QR library not loaded');
                            }
                        }
                    } catch (e) {
                        console.error('QR generation failed:', e);
                        const warning = document.createElement('div');
                        warning.style.cssText = 'color: #888; text-align: center; padding: 2rem; font-size: 0.9rem;';
                        warning.textContent = 'QR code generation failed. Please copy the token below.';
                        qrContainer.appendChild(warning);
                    }

                    loadDashboard();
                } else {
                    showToast(result.error || 'Export failed', 'error');
                    exportBtn.textContent = exportBtnOrigText;
                    exportBtn.disabled = false;

                    // If sync happened, reload dashboard to show updated balance
                    if (result.sync) {
                        loadDashboard();
                    }
                }
            } catch (e) {
                showToast('Export failed', 'error');
                exportBtn.textContent = exportBtnOrigText;
                exportBtn.disabled = false;
            }
        }

        function copyToken() {
            const token = document.getElementById('export-token').textContent;
            navigator.clipboard.writeText(token).then(() => {
                showToast('Token copied!', 'success');
            });
        }

        // Update amount input based on selected store's mint unit
        function updateAmountInputForStore(mintUnit) {
            const amountLabel = document.getElementById('request-amount-label');
            const amountInput = document.getElementById('request-amount');
            const unit = (mintUnit || 'sat').toUpperCase();

            amountLabel.textContent = `Amount (${unit})`;

            if (mintUnit === 'sat' || mintUnit === 'msat') {
                amountInput.placeholder = '100';
                amountInput.min = '1';
                amountInput.step = '1';
            } else {
                amountInput.placeholder = '1.00';
                amountInput.min = '0.01';
                amountInput.step = '0.01';
            }
        }

        async function handleGenerateRequest() {
            const storeId = currentStoreId;
            const amount = parseFloat(document.getElementById('request-amount').value);
            const memo = document.getElementById('request-memo').value;

            if (!storeId) {
                showToast('Please select a store first', 'error');
                return;
            }

            // Get store info from dashboardData
            const store = dashboardData?.stores?.find(s => s.id === storeId);
            const apiKey = store?.internalApiKey;
            const mintUnit = store?.mint_unit || 'sat';
            const minAmount = (mintUnit === 'sat' || mintUnit === 'msat') ? 1 : 0.01;

            if (!amount || amount < minAmount) {
                showToast('Please enter an amount', 'error');
                return;
            }

            if (!apiKey) {
                showToast('Store API key not available', 'error');
                return;
            }

            try {
                // Use Greenfield API to create invoice
                const apiUrl = API_BASE_URL + '/api/v1/stores/' + encodeURIComponent(storeId) + '/invoices';

                const invoiceData = {
                    amount: amount,
                    currency: mintUnit,
                    checkout: {
                        redirectURL: window.location.href.split('?')[0], // Return to admin
                        redirectAutomatically: true
                    }
                };

                if (memo) {
                    invoiceData.metadata = { itemDesc: memo };
                }

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'token ' + apiKey
                    },
                    body: JSON.stringify(invoiceData)
                });

                const result = await response.json();

                if (response.ok && result.checkoutLink) {
                    // Redirect to checkout page
                    window.location.href = result.checkoutLink;
                } else {
                    showToast(result.message || result.error || 'Failed to create invoice', 'error');
                }
            } catch (e) {
                console.error('Invoice creation failed:', e);
                showToast('Failed to create invoice', 'error');
            }
        }

        async function saveAutoMelt() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const address = document.getElementById('auto-melt-address').value;
            const enabled = document.getElementById('auto-melt-enabled').checked ? '1' : '0';
            // Convert threshold to smallest unit (cents for fiat)
            const threshold = parseAmount(document.getElementById('auto-melt-threshold').value, mintUnit);

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=save_auto_melt&store_id=${encodeURIComponent(currentStoreId)}&address=${encodeURIComponent(address)}&enabled=${enabled}&threshold=${threshold}`
                );

                const result = await response.json();

                if (response.ok) {
                    showToast('Settings saved!', 'success');
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save settings', 'error');
            }
        }

        async function saveExchangeSettings() {
            if (!currentStoreId) {
                showToast('No store selected', 'error');
                return;
            }

            const primaryProvider = document.getElementById('price-provider-primary').value;
            const secondaryProvider = document.getElementById('price-provider-secondary').value;
            const exchangeFee = document.getElementById('exchange-fee-percent').value;

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=update_store&store_id=${encodeURIComponent(currentStoreId)}&price_provider_primary=${encodeURIComponent(primaryProvider)}&price_provider_secondary=${encodeURIComponent(secondaryProvider)}&exchange_fee_percent=${encodeURIComponent(exchangeFee)}`
                );

                const result = await response.json();

                if (response.ok) {
                    showToast('Exchange settings saved!', 'success');
                } else {
                    showToast(result.error || 'Failed to save', 'error');
                }
            } catch (e) {
                showToast('Failed to save exchange settings', 'error');
            }
        }

        // URL Mode settings (standalone deployments only)
        let urlModeTestResults = { direct: null, router: null };

        function initUrlModeSettings() {
            if (urlModeConfig.isWordPress) return; // WordPress uses its own routing

            // Set the current mode radio button based on saved config
            // Detection only runs when user clicks "Recheck" button
            const radio = document.getElementById('url-mode-' + urlModeConfig.currentMode);
            if (radio) radio.checked = true;
        }

        async function detectUrlMode() {
            const directStatus = document.getElementById('url-mode-direct-status');
            const routerStatus = document.getElementById('url-mode-router-status');

            if (!directStatus || !routerStatus) return;

            directStatus.textContent = 'Testing...';
            directStatus.style.color = 'var(--text-secondary)';
            routerStatus.textContent = 'Testing...';
            routerStatus.style.color = 'var(--text-secondary)';

            if (urlModeConfig.isWordPress) return;

            const baseUrl = urlModeConfig.baseUrl;

            // Test both URLs in parallel
            // Direct mode routes /api/v1/* to api.php via nginx/Apache rewrite
            // Router mode routes everything through router.php
            const tests = await Promise.all([
                testUrlEndpoint(baseUrl + '/api/v1/server/info'),
                testUrlEndpoint(baseUrl + '/router.php/api/v1/server/info')
            ]);

            urlModeTestResults.direct = tests[0];
            urlModeTestResults.router = tests[1];

            // Update status indicators
            if (tests[0]) {
                directStatus.textContent = 'Working';
                directStatus.style.color = 'var(--success)';
            } else {
                directStatus.textContent = 'Not working';
                directStatus.style.color = 'var(--text-secondary)';
            }

            if (tests[1]) {
                routerStatus.textContent = 'Working';
                routerStatus.style.color = 'var(--success)';
            } else {
                routerStatus.textContent = 'Not working';
                routerStatus.style.color = 'var(--text-secondary)';
            }
        }

        async function testUrlEndpoint(url) {
            try {
                const response = await fetch(url, { method: 'GET', mode: 'same-origin' });
                return response.status === 200;
            } catch (e) {
                return false;
            }
        }

        async function saveUrlMode() {
            const selectedMode = document.querySelector('input[name="url_mode"]:checked')?.value;
            if (!selectedMode) {
                showToast('Please select a URL mode', 'error');
                return;
            }

            // Warn if selected mode isn't working
            if (!urlModeTestResults[selectedMode]) {
                if (!confirm('The selected URL mode does not appear to be working. Save anyway?')) {
                    return;
                }
            }

            try {
                const response = await postWithCsrf(adminUrl, `action=save_url_mode&mode=${encodeURIComponent(selectedMode)}`);
                const result = await response.json();

                if (response.ok && result.success) {
                    // Update the displayed server URL
                    const urlEl = document.getElementById('current-server-url');
                    if (urlEl) urlEl.textContent = result.serverUrl;

                    // Update global URL variables so all components use new URL
                    serverUrl = result.serverUrl;
                    API_BASE_URL = result.serverUrl.replace(/\/$/, '');  // Ensure no trailing slash

                    showToast('URL mode saved!', 'success');
                } else {
                    showToast(result.error || 'Failed to save URL mode', 'error');
                }
            } catch (e) {
                showToast('Failed to save URL mode', 'error');
            }
        }

        function savePin() {
            const newPin = document.getElementById('new-pin').value;
            const confirmPin = document.getElementById('confirm-pin').value;

            if (newPin.length !== 4 || !/^\d{4}$/.test(newPin)) {
                showToast('PIN must be 4 digits', 'error');
                return;
            }

            if (newPin !== confirmPin) {
                showToast('PINs do not match', 'error');
                return;
            }

            localStorage.setItem(STORAGE_PIN, newPin);
            showToast('PIN saved!', 'success');
            closeModal('modal-pin-setup');
        }

        async function logout() {
            await postWithCsrf(adminUrl, 'action=logout');

            localStorage.removeItem(STORAGE_AUTH);
            localStorage.removeItem(STORAGE_PIN);
            location.reload();
        }

        function lock() {
            document.getElementById('app').classList.remove('visible');
            document.getElementById('lock-screen').classList.remove('hidden');
            pin = '';
            document.querySelectorAll('.pin-dot').forEach(dot => {
                dot.classList.remove('filled');
            });

            // Check if PIN is set
            const storedPin = localStorage.getItem(STORAGE_PIN);
            if (storedPin) {
                document.getElementById('pin-pad').style.display = 'grid';
                document.getElementById('pin-dots').style.display = 'flex';
                document.querySelector('.lock-subtitle').textContent = 'Enter PIN to unlock';
            }
        }

        // ===============================
        // Mint Discovery Functions
        // ===============================
        let mintDiscoveryInstance = null;
        let discoveredMints = [];
        let discoveryCallback = null;
        let discoveryContext = null;

        function openBackupMintDiscovery(storeId, storeName) {
            discoveryContext = 'backup';
            discoveryCallback = (url) => {
                document.getElementById('backup-mint-url').value = url;
            };
            openMintDiscoveryModal();
        }

        let mintDisclaimerAcknowledged = false;

        function openMintDiscoveryModal() {
            document.getElementById('mint-discovery-modal').style.display = 'flex';
            // Reset disclaimer checkbox state when opening
            const checkbox = document.getElementById('mint-disclaimer-checkbox');
            if (checkbox) {
                checkbox.checked = false;
                mintDisclaimerAcknowledged = false;
            }
            // Auto-start discovery
            startMintDiscovery();
        }

        function onMintDisclaimerChange(checkbox) {
            mintDisclaimerAcknowledged = checkbox.checked;
            updateMintSelectButtons();
        }

        function updateMintSelectButtons() {
            const buttons = document.querySelectorAll('#mint-discovery-list button');
            buttons.forEach(btn => {
                btn.disabled = !mintDisclaimerAcknowledged;
                btn.style.opacity = mintDisclaimerAcknowledged ? '1' : '0.5';
                btn.style.cursor = mintDisclaimerAcknowledged ? 'pointer' : 'not-allowed';
            });
        }

        function closeMintDiscoveryModal() {
            document.getElementById('mint-discovery-modal').style.display = 'none';
            if (mintDiscoveryInstance) {
                mintDiscoveryInstance.close();
                mintDiscoveryInstance = null;
            }
        }

        async function startMintDiscovery() {
            const listEl = document.getElementById('mint-discovery-list');
            const loadingEl = document.getElementById('mint-discovery-loading');
            const statusEl = document.getElementById('mint-discovery-status');

            loadingEl.style.display = 'block';
            listEl.innerHTML = '';
            statusEl.textContent = 'Connecting to Nostr relays...';

            if (typeof MintDiscovery === 'undefined') {
                statusEl.textContent = 'Error: MintDiscovery library not loaded';
                loadingEl.style.display = 'none';
                return;
            }

            try {
                mintDiscoveryInstance = MintDiscovery.create({
                    httpTimeout: 8000,
                    nostrTimeout: 15000
                });

                discoveredMints = await mintDiscoveryInstance.discover({
                    onProgress: (progress) => {
                        if (progress.phase === 'nostr' && progress.step === 'mint-info') {
                            statusEl.textContent = 'Fetching mint announcements...';
                        } else if (progress.phase === 'nostr' && progress.step === 'reviews') {
                            statusEl.textContent = 'Fetching reviews...';
                        } else if (progress.phase === 'http') {
                            statusEl.textContent = 'Checking mint status...';
                        }
                    }
                });

                loadingEl.style.display = 'none';
                statusEl.textContent = `Found ${discoveredMints.length} mints`;
                renderDiscoveredMints();
            } catch (error) {
                loadingEl.style.display = 'none';
                statusEl.textContent = 'Error: ' + error.message;
            }
        }

        function getUnitsFromMintInfo(info) {
            if (!info?.nuts?.[4]?.methods) return [];
            return [...new Set(info.nuts[4].methods.map(m => m.unit).filter(Boolean))];
        }

        function renderDiscoveryStars(rating) {
            if (rating === null || rating === undefined) return '---';
            const full = Math.floor(rating);
            let html = '<span style="color: #FFC107;">';
            for (let i = 0; i < full; i++) html += '\u2605';
            for (let i = full; i < 5; i++) html += '\u2606';
            html += '</span> ' + rating.toFixed(1);
            return html;
        }

        function filterDiscoveredMints() {
            renderDiscoveredMints();
        }

        function renderDiscoveredMints() {
            const listEl = document.getElementById('mint-discovery-list');
            const filterUnit = document.getElementById('mint-discovery-unit-filter').value;
            const searchText = document.getElementById('mint-discovery-search').value.toLowerCase().trim();

            let filtered = discoveredMints.filter(m => {
                if (filterUnit) {
                    const units = getUnitsFromMintInfo(m.info);
                    if (!units.includes(filterUnit)) return false;
                }
                if (searchText) {
                    const name = (m.info?.name || '').toLowerCase();
                    const url = m.url.toLowerCase();
                    if (!name.includes(searchText) && !url.includes(searchText)) return false;
                }
                return true;
            });

            if (filtered.length === 0) {
                listEl.innerHTML = '<p style="color: var(--text-secondary); text-align: center; padding: 2rem;">No mints found</p>';
                return;
            }

            listEl.innerHTML = filtered.map(m => {
                const name = m.info?.name || 'Unknown Mint';
                const isOnline = !m.error && m.info;
                const units = getUnitsFromMintInfo(m.info);

                return `
                    <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <div style="font-size: 0.9rem;">
                                ${renderDiscoveryStars(m.averageRating)}
                                <span style="color: var(--text-secondary); font-size: 0.8rem; margin-left: 0.25rem;">(${m.reviewsCount || 0})</span>
                            </div>
                            <span style="font-size: 0.8rem; color: ${isOnline ? 'var(--accent)' : 'var(--danger)'};">
                                ${isOnline ? '\u25CF Online' : '\u25CB Offline'}
                            </span>
                        </div>
                        <h4 style="margin: 0 0 0.25rem 0; font-size: 1rem;">${escapeHtml(name)}</h4>
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0 0 0.5rem 0; word-break: break-all;">${escapeHtml(m.url)}</p>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            ${units.length > 0 ? units.map(u => u.toUpperCase()).join(' \u2022 ') : 'Unknown units'}
                        </div>
                        <button type="button" class="btn btn-full" style="font-size: 0.85rem;" onclick="selectDiscoveredMint('${escapeHtml(m.url)}')">Select</button>
                    </div>
                `;
            }).join('');

            const statusEl = document.getElementById('mint-discovery-status');
            statusEl.textContent = `Showing ${filtered.length} of ${discoveredMints.length} mints`;

            // Update button states based on disclaimer checkbox
            updateMintSelectButtons();
        }

        function selectDiscoveredMint(url) {
            if (discoveryCallback) {
                discoveryCallback(url);
            }
            closeMintDiscoveryModal();
        }

        async function showStoreDetails(storeId, storeName) {
            document.getElementById('store-modal-title').textContent = storeName;

            // Load API keys and backup mints in parallel
            const [keysRes, mintsRes] = await Promise.all([
                fetch(`${adminUrl}?api=api_keys&store_id=${storeId}`),
                fetch(`${adminUrl}?api=get_backup_mints&store_id=${storeId}`)
            ]);
            const keys = await keysRes.json();
            const backupMintsRes = await mintsRes.json();
            const backupMints = Array.isArray(backupMintsRes) ? backupMintsRes : [];

            // Get store info from dashboardData
            const store = dashboardData?.stores?.find(s => s.id === storeId);
            const mintUrl = store?.mint_url || 'Not configured';
            const mintUnit = (store?.mint_unit || 'sat').toUpperCase();

            // Build pairing URL for testing
            const pairingParams = new URLSearchParams({
                applicationName: 'Test Connection',
                'permissions[]': 'btcpay.store.cancreateinvoice',
                strict: 'true'
            });
            const pairingUrl = serverUrl + '/api-keys/authorize?' + pairingParams.toString();

            const content = `
                <div class="card-body" style="max-height: 70vh; overflow-y: auto;">
                    <p style="color: var(--text-secondary); margin-bottom: 1rem;">Store ID: ${storeId}</p>

                    <!-- Mint Configuration -->
                    <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.75rem;">Mint Configuration</h4>
                        <div style="margin-bottom: 0.5rem;">
                            <span style="color: var(--text-secondary); font-size: 0.85rem;">Primary Mint:</span>
                            <code style="display: block; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.75rem; word-break: break-all; margin-top: 0.25rem;">
                                ${escapeHtml(mintUrl)}
                            </code>
                        </div>
                        <div>
                            <span style="color: var(--text-secondary); font-size: 0.85rem;">Unit:</span>
                            <span style="font-weight: 500; margin-left: 0.5rem;">${mintUnit}</span>
                        </div>
                    </div>

                    <!-- Backup Mints -->
                    <div style="background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem;">Backup Mints</h4>
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Fallback mints used if primary is unavailable
                        </p>
                        <div id="backup-mints-list">
                            ${backupMints.length === 0 ? '<p style="color: var(--text-secondary); font-size: 0.85rem;">No backup mints configured</p>' :
                                backupMints.map(m => `
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; background: rgba(0,0,0,0.2); border-radius: 4px; margin-bottom: 0.5rem;">
                                        <div style="flex: 1; overflow: hidden;">
                                            <code style="font-size: 0.7rem; word-break: break-all;">${escapeHtml(m.mint_url)}</code>
                                            <span style="opacity: 0.6; font-size: 0.75rem; margin-left: 0.5rem;">(${m.unit.toUpperCase()})</span>
                                        </div>
                                        <button class="btn btn-danger" style="padding: 0.2rem 0.4rem; font-size: 0.7rem; margin-left: 0.5rem;"
                                                onclick="removeBackupMint(${m.id}, '${storeId}', '${escapeHtml(storeName)}')">Remove</button>
                                    </div>
                                `).join('')
                            }
                        </div>
                        <div id="add-backup-mint-form" style="display: none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border);">
                            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="url" id="backup-mint-url" class="form-input" placeholder="https://mint.example.com" style="flex: 1; font-size: 0.85rem;">
                                <button class="btn btn-secondary" style="font-size: 0.8rem; white-space: nowrap;" onclick="openBackupMintDiscovery('${storeId}', '${escapeHtml(storeName)}')">Discover</button>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button class="btn btn-full" style="font-size: 0.8rem;" onclick="addBackupMint('${storeId}', '${escapeHtml(storeName)}')">Add</button>
                                <button class="btn btn-secondary" style="font-size: 0.8rem;" onclick="document.getElementById('add-backup-mint-form').style.display='none'">Cancel</button>
                            </div>
                        </div>
                        <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem; font-size: 0.85rem;" onclick="document.getElementById('add-backup-mint-form').style.display='block'">
                            + Add Backup Mint
                        </button>
                    </div>

                    <!-- E-commerce Integration -->
                    <div style="background: var(--card-bg); border: 1px solid var(--primary); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem; color: var(--primary);">E-commerce Integration</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                            Enter this URL in your BTCPay plugin settings:
                        </p>
                        <code style="display: block; background: rgba(0,0,0,0.3); padding: 0.5rem; border-radius: 4px; font-size: 0.8rem; word-break: break-all; user-select: all; margin-bottom: 0.75rem;">
                            ${escapeHtml(serverUrl)}
                        </code>
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            Most plugins (WooCommerce, etc.) will redirect here to pair automatically.
                        </p>
                        <a href="${escapeHtml(pairingUrl)}" class="btn" style="display: inline-block; font-size: 0.8rem; padding: 0.4rem 0.75rem;">
                            Test Pairing Flow
                        </a>
                    </div>

                    <h4 style="margin-bottom: 0.5rem;">API Keys</h4>
                    ${keys.length === 0 ? '<p style="color: var(--text-secondary);">No API keys yet</p>' :
                        keys.map(k => `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                                <span>${escapeHtml(k.label) || 'API Key'}</span>
                                ${k.label === 'Internal (Dashboard)' ? '' : `<button class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
                                        onclick="deleteApiKey('${k.id}', '${storeId}')">Delete</button>`}
                            </div>
                        `).join('')
                    }

                    <button class="btn btn-full" style="margin-top: 1rem;" onclick="createApiKey('${storeId}')">
                        + Create API Key Manually
                    </button>

                    <button class="btn btn-danger btn-full" style="margin-top: 0.5rem;" onclick="deleteStore('${storeId}')">
                        Delete Store
                    </button>
                </div>
            `;

            document.getElementById('store-modal-content').innerHTML = content;
            openModal('modal-store');
        }

        async function addBackupMint(storeId, storeName) {
            const mintUrl = document.getElementById('backup-mint-url').value.trim();
            if (!mintUrl) {
                showToast('Please enter a mint URL', 'error');
                return;
            }

            // Get the store's unit
            const store = dashboardData?.stores?.find(s => s.id === storeId);
            const unit = store?.mint_unit || 'sat';

            try {
                const response = await postWithCsrf(adminUrl,
                    `action=add_backup_mint&store_id=${storeId}&mint_url=${encodeURIComponent(mintUrl)}&unit=${unit}`
                );

                const result = await response.json();

                if (response.ok) {
                    showToast('Backup mint added!', 'success');
                    showStoreDetails(storeId, storeName);
                } else {
                    showToast(result.error || 'Failed to add backup mint', 'error');
                }
            } catch (e) {
                showToast('Failed to add backup mint', 'error');
            }
        }

        async function removeBackupMint(mintId, storeId, storeName) {
            if (!confirm('Remove this backup mint?')) return;

            try {
                await postWithCsrf(adminUrl, `action=remove_backup_mint&id=${mintId}`);
                showToast('Backup mint removed', 'success');
                showStoreDetails(storeId, storeName);
            } catch (e) {
                showToast('Failed to remove backup mint', 'error');
            }
        }

        function createApiKey(storeId) {
            document.getElementById('modal-apikey-title').textContent = 'Create API Key';
            document.getElementById('modal-apikey-content').innerHTML = `
                <div class="form-group">
                    <label class="form-label">Label (optional)</label>
                    <input type="text" class="form-input" id="apikey-label" placeholder="My API Key">
                </div>
                <button class="btn btn-full" onclick="submitCreateApiKey('${storeId}')">Create Key</button>
                <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeModal('modal-apikey')">Cancel</button>
            `;
            openModal('modal-apikey');
            document.getElementById('apikey-label').focus();
        }

        async function submitCreateApiKey(storeId) {
            const label = document.getElementById('apikey-label').value || 'API Key';

            const response = await postWithCsrf(adminUrl,
                `action=create_api_key&store_id=${storeId}&label=${encodeURIComponent(label)}`
            );

            const result = await response.json();

            if (response.ok) {
                showApiKeyResult(result.key, storeId);
            } else {
                showToast(result.error || 'Failed to create API key', 'error');
            }
        }

        function showApiKeyResult(key, storeId) {
            document.getElementById('modal-apikey-title').textContent = 'API Key Created';
            document.getElementById('modal-apikey-content').innerHTML = `
                <p style="color: var(--text-secondary); margin-bottom: 1rem;">Save this key now - it won't be shown again!</p>
                <div class="token-display" style="user-select: all; cursor: text;">${key}</div>
                <button class="btn btn-full" onclick="copyApiKey('${key}')">Copy to Clipboard</button>
                <button class="btn btn-secondary btn-full" style="margin-top: 0.5rem;" onclick="closeApiKeyModal('${storeId}')">Close</button>
            `;
        }

        function copyApiKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                showToast('API key copied to clipboard', 'success');
            }).catch(() => {
                showToast('Failed to copy', 'error');
            });
        }

        function closeApiKeyModal(storeId) {
            closeModal('modal-apikey');
            // Refresh store details or store settings view
            if (document.getElementById('view-stores').classList.contains('active')) {
                loadStoreApiKeys();
            } else {
                const store = dashboardData?.stores?.find(s => s.id === storeId);
                if (store) showStoreDetails(storeId, store.name);
            }
        }

        async function deleteApiKey(keyId, storeId) {
            if (!confirm('Delete this API key?')) return;

            await postWithCsrf(adminUrl, `action=delete_api_key&key_id=${keyId}`);

            const store = dashboardData?.stores?.find(s => s.id === storeId);
            if (store) showStoreDetails(storeId, store.name);
        }

        async function deleteApiKeyFromSettings(keyId) {
            if (!confirm('Delete this API key?')) return;

            await postWithCsrf(adminUrl, `action=delete_api_key&key_id=${keyId}`);
            loadStoreApiKeys();
        }

        async function deleteStore(storeId) {
            if (!confirm('Delete this store and all its data? This action cannot be undone.')) return;

            await postWithCsrf(adminUrl, `action=delete_store&store_id=${storeId}`);

            closeModal('modal-store');
            // Clear current store selection
            currentStoreId = null;
            localStorage.removeItem('selectedStoreId');
            // Reload dashboard which will auto-select first store
            loadDashboard();
            // If on store settings view, reload it
            if (document.getElementById('view-stores').classList.contains('active')) {
                loadStoreSettings();
            }
        }

        // Export Max button - uses pre-loaded dashboard data for instant response
        function sendMax() {
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const exportAvailable = dashboardData?.exportAvailable || 0;

            if (exportAvailable > 0) {
                const donate = document.getElementById('export-donate').checked;

                let maxAmountSmallest = exportAvailable;

                // Account for donation percentage (donation is on top of amount)
                if (donate) {
                    maxAmountSmallest = Math.floor(maxAmountSmallest / (1 + DONATION_PERCENT / 100));
                }

                // Convert to display format (divide by 100 for fiat)
                const displayValue = isFiatUnit(mintUnit)
                    ? (Math.max(0, maxAmountSmallest) / 100).toFixed(2)
                    : Math.max(0, maxAmountSmallest);
                document.getElementById('export-amount').value = displayValue;
                updateExportDonation();  // Update donation display
            } else {
                showToast('No balance available to send', 'error');
            }
        }

        // Modals
        function openModal(id) {
            // Configure input attributes based on mint unit
            const mintUnit = dashboardData?.mintUnit || 'sat';
            const isFiat = isFiatUnit(mintUnit);

            // Reset export modal state
            if (id === 'modal-export') {
                // Stop any active claim checking
                if (exportCheckInterval) {
                    clearInterval(exportCheckInterval);
                    exportCheckInterval = null;
                }
                exportSecrets = null;

                // Remove any existing success overlay
                const modal = document.getElementById('modal-export');
                const modalContent = modal.querySelector('.modal');
                const existingOverlay = Array.from(modalContent.children).find(el =>
                    el.style.position === 'absolute' && el.style.zIndex === '1000'
                );
                if (existingOverlay) {
                    existingOverlay.remove();
                }

                document.getElementById('export-form').style.display = 'block';
                document.getElementById('export-result').style.display = 'none';
                document.getElementById('export-amount').value = '';
                document.getElementById('export-qr').innerHTML = '';
                document.getElementById('export-donate').checked = true;
                document.getElementById('export-donate-amount').textContent = '0';

                // Set input attributes for fiat vs sats
                const exportInput = document.getElementById('export-amount');
                exportInput.step = isFiat ? '0.01' : '1';
                exportInput.min = isFiat ? '0.01' : '1';
                exportInput.placeholder = isFiat ? '0.00' : '0';

                // Reset export button state
                const exportBtn = document.getElementById('btn-confirm-export');
                exportBtn.textContent = 'Generate Token';
                exportBtn.disabled = true; // Will be enabled by updateExportDonation() when amount entered
            }
            if (id === 'modal-withdraw') {
                document.getElementById('withdraw-address').value = '';
                document.getElementById('withdraw-amount').value = '';
                document.getElementById('withdraw-amount').disabled = false;
                document.getElementById('btn-withdraw-max').disabled = false;
                document.getElementById('withdraw-donate').checked = true;
                document.getElementById('donate-amount').textContent = '0';
                document.getElementById('withdraw-destination-help').textContent = 'Lightning address or BOLT-11 invoice';
                document.getElementById('withdraw-destination-help').style.color = 'var(--text-secondary)';
                document.getElementById('withdraw-max-with-donation').textContent = '';
                document.getElementById('withdraw-amount-fiat-equiv').style.display = 'none';
                bolt11FixedAmount = null;
                bolt11FeeEstimate = null;
                bolt11MeltError = false;

                // Reset withdraw button state
                const withdrawBtn = document.getElementById('btn-confirm-withdraw');
                withdrawBtn.textContent = 'Withdraw';
                withdrawBtn.disabled = true; // Will be enabled by updateWithdrawInfo() when valid input

                // Lightning withdrawals ALWAYS use SAT (Lightning Network operates in satoshis)
                const withdrawInput = document.getElementById('withdraw-amount');
                withdrawInput.step = '1';
                withdrawInput.min = '1';
                withdrawInput.placeholder = '0';

                // Set label to SAT
                const amountLabel = document.querySelector('#withdraw-amount-group .form-label');
                if (amountLabel) {
                    amountLabel.innerHTML = 'Amount (<span class="unit-label">SAT</span>)';
                }

                // For fiat mints, show fiat balance primary with SAT approximation
                // The fiat amount is what they actually have; SAT is an estimate based on our exchange rate
                if (isFiat) {
                    const balanceSats = dashboardData?.balanceInSats || 0;
                    const balanceFiat = dashboardData?.balance || 0;
                    document.getElementById('withdraw-available').innerHTML =
                        `${formatAmount(balanceFiat, mintUnit)} ${mintUnit.toUpperCase()} (~${balanceSats.toLocaleString()} SAT)`;
                } else {
                    document.getElementById('withdraw-available').textContent =
                        formatAmount(dashboardData?.balance ?? 0, mintUnit) + ' SAT';
                }

                updateWithdrawInfo();
            }
            if (id === 'modal-request') {
                // Check that a store is selected
                if (!currentStoreId) {
                    showToast('Please select a store first', 'error');
                    return;
                }

                document.getElementById('request-amount').value = '';
                document.getElementById('request-memo').value = '';

                // Get current store's mint unit and update amount input
                const store = dashboardData?.stores?.find(s => s.id === currentStoreId);
                const mintUnit = store?.mint_unit || 'sat';
                updateAmountInputForStore(mintUnit);
            }

            document.getElementById(id).classList.add('visible');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('visible');

            // Cleanup and refresh when closing export modal
            if (id === 'modal-export') {
                // Stop claim checking
                if (exportCheckInterval) {
                    clearInterval(exportCheckInterval);
                    exportCheckInterval = null;
                }
                exportSecrets = null;

                loadDashboard();
            }
        }

        // Check if exported token has been claimed
        async function checkIfTokenClaimed() {
            if (!exportSecrets || exportSecrets.length === 0) return;
            if (!currentStoreId) return;

            try {
                const secretsJson = JSON.stringify(exportSecrets);
                const response = await postWithCsrf(adminUrl,
                    `action=check_proofs_spent&store_id=${encodeURIComponent(currentStoreId)}&secrets=${encodeURIComponent(secretsJson)}`
                );

                const result = await response.json();

                if (result.spent) {
                    // Token has been claimed!
                    showTokenClaimedSuccess();
                }
            } catch (e) {
                console.error('Failed to check if token claimed:', e);
            }
        }

        // Show success when token is claimed
        function showTokenClaimedSuccess() {
            // Stop checking
            if (exportCheckInterval) {
                clearInterval(exportCheckInterval);
                exportCheckInterval = null;
            }

            // Get export modal
            const modal = document.getElementById('modal-export');
            const modalContent = modal.querySelector('.modal');

            // Create success overlay
            const successOverlay = document.createElement('div');
            successOverlay.style.cssText = `
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(76, 175, 80, 0.95);
                border-radius: 24px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                cursor: pointer;
                animation: fadeIn 0.3s ease;
            `;

            successOverlay.innerHTML = `
                <div style="font-size: 4rem; margin-bottom: 1rem;">🎉</div>
                <div style="font-size: 1.5rem; font-weight: 600; color: white; margin-bottom: 0.5rem;">Token Claimed!</div>
                <div style="font-size: 0.9rem; color: rgba(255,255,255,0.9);">Recipient received the funds</div>
                <div style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 2rem;">Click anywhere to close</div>
            `;

            // Add fadeIn animation
            const style = document.createElement('style');
            style.textContent = '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }';
            document.head.appendChild(style);

            // Click to close
            successOverlay.addEventListener('click', () => {
                closeModal('modal-export');
            });

            modalContent.style.position = 'relative';
            modalContent.appendChild(successOverlay);

            // Update balance
            loadDashboard();
        }

        // Utility: escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Toast
        function showToast(message, type = '') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast show ${type}`;
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>
