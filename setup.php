<?php
/**
 * CashuPayServer - Onboarding Wizard
 *
 * Multi-screen wizard for initial configuration. Screens are identified by
 * slug rather than by number, so the sequence below reads in execution order
 * and inserting a screen never requires a renumber (the old wizard used sparse
 * integers whose order had drifted from the flow).
 *
 * Sequence (standalone first run):
 *   security   Requirements + database-exposure check + URL-mode detection
 *   password   Admin password
 *   store      Store name
 *   onchain    On-chain destination — xpub (preferred) or single address
 *   zeroconf   Zero-conf vs 1-confirmation (skipped when onchain was skipped)
 *   lightning  LNURL/Lightning address + CLINK noffer
 *   swaps      Submarine swaps on/off
 *   mints      Cashu mints on/off; auto-picks a main + backup when on
 *   cron       Reminder to install the cron entry (skipped on the desktop
 *              package, and on provisioned installs that declare
 *              CASHUPAY_EXTERNAL_CRON — see SetupFlow::externalCronConfigured)
 *   done       Completion, seed phrase, e-commerce pairing
 *
 * `?mode=add_store` (from the admin panel) runs store → mints only and then
 * returns to admin; it never shows security/password/cron/done.
 *
 * setup_complete is set at the end of the `mints` screen — the install is
 * fully usable from that point, so abandoning the browser on the cron or done
 * screen does not strand a half-configured server. The redirect-if-set-up
 * guard below therefore has to let those two screens through.
 */

require_once __DIR__ . '/includes/http_status.php';

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/urls.php';
require_once __DIR__ . '/includes/store_ln_addresses.php';
require_once __DIR__ . '/includes/swap/config.php';
require_once __DIR__ . '/includes/setup_flow.php';
require_once __DIR__ . '/includes/desktop.php';
require_once __DIR__ . '/includes/managed.php';

// Initialize session early - needed for storing temp data during setup
Auth::initSession();

/**
 * The URL of THIS wizard request (path only, query stripped) — the action for
 * every in-wizard form, Back link, and AJAX call.
 *
 * Deliberately not Urls::setup(): that helper trusts the detected URL mode,
 * and the default ('router') builds PATH_INFO-style URLs like
 * /router.php/setup.php that plenty of hosts never route — a WordPress-
 * alongside install behind nginx or a no-AllowOverride Apache serves a
 * WordPress "page not found" for them, dumping the operator out of the wizard
 * at the first screen whose form used an absolute action. The URL the
 * operator is already viewing routed HERE by construction, so posting back to
 * it works on every host and in every URL mode.
 */
function setupSelfUrl(): string {
    $uri = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    return ($uri !== '' && $uri !== false) ? $uri : Urls::setup();
}

// AJAX endpoints that are stateless and need to work both during and after
// setup (e.g. the mint-discovery modal opens from add_store mode after
// setup is complete). Handled before the redirect-if-set-up guard below.
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'mint_country') {
        require_once __DIR__ . '/includes/ipgeo.php';
        header('Content-Type: application/json');
        $url = $_GET['url'] ?? '';
        $cc = is_string($url) && $url !== '' ? IpGeo::lookupCountry($url) : null;
        echo json_encode(['country' => $cc]);
        exit;
    }
    if ($action === 'mint_country_batch') {
        // Single request for many mint URLs. The per-process CSV index is
        // built once and reused across lookups, so this is dramatically
        // faster than N individual ?action=mint_country calls.
        require_once __DIR__ . '/includes/ipgeo.php';
        header('Content-Type: application/json');
        $raw = $_GET['urls'] ?? '';
        $urls = array_filter(array_map('trim', explode(',', $raw)));
        $out = [];
        foreach ($urls as $u) {
            $u = (string)$u;
            if ($u === '') continue;
            $out[$u] = IpGeo::lookupCountry($u);
        }
        echo json_encode(['countries' => $out]);
        exit;
    }
    if ($action === 'get_suggested_mints') {
        require_once __DIR__ . '/includes/trusted_mints.php';
        header('Content-Type: application/json');
        $list = TrustedMints::getCachedList();
        $urls = [];
        if (is_array($list) && isset($list['mints']) && is_array($list['mints'])) {
            foreach ($list['mints'] as $entry) {
                if (!is_array($entry) || empty($entry['url']) || !empty($entry['disabled'])) {
                    continue;
                }
                $urls[] = rtrim((string)$entry['url'], '/');
            }
        }
        echo json_encode(['mints' => $urls]);
        exit;
    }
}

// Get mode parameter
$mode = $_GET['mode'] ?? $_POST['mode'] ?? '';

// setup_complete flips at the end of the `mints` screen so an abandoned
// browser doesn't leave a half-configured install. The two screens that come
// after it therefore have to survive the redirect-if-set-up guard.
$postStep = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['step'] ?? '') : '';
$isTailPost = in_array($postStep, SetupFlow::POST_COMPLETION, true);
if (Database::isInitialized() && Config::isSetupComplete() && !$isTailPost) {
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

// Managed installs provision the admin account up front (see managed.php);
// seed it before the wizard's shape is computed so the password screen is
// consistently absent from the very first render.
ManagedInstall::seedAdminIfProvisioned();

// One resolve per request: the answer can't change mid-request, and the
// wizard's shape must not either. A managed install implies the external
// cron declaration (its orchestrator pings cron.php). The password skip
// requires the seeded account to actually EXIST, not just the hash constant:
// if a merchant-created account predated the hash, the seed above no-oped,
// and the wizard must still collect a password rather than strand the
// operator with credentials nobody knows. Stable mid-run — only the
// password screen itself writes users, and it is exactly the screen that is
// absent when this is true.
$isDesktop = Desktop::isWindowsDesktop();
$externalCron = SetupFlow::externalCronConfigured() || ManagedInstall::isManaged();
$passwordPreseeded = ManagedInstall::adminSeededFromProvisionedHash();

// The security screen exists to prove the data directory can't be fetched
// over the web. With the directory outside the web root that exposure is
// impossible, so the screen is dropped from the flow entirely rather than
// warning about a risk that doesn't apply — unless a PHP requirement is
// missing, because the screen is also where that blocking error renders.
// The Windows desktop package drops it too: its server only listens on
// 127.0.0.1 and router.php refuses /data requests, so the screen's manual
// Apache/nginx hardening walkthrough has nothing to protect against.
$securityScreenNeeded = SetupFlow::missingRequirements() !== []
    || (!Database::isDataDirOutsideWebroot() && !$isDesktop);

// Current screen. Anything unrecognised (a stale bookmark, or a form saved
// from the pre-slug wizard) restarts at the first screen for this mode rather
// than rendering a blank card.
$stepRequested = isset($_POST['step']) || isset($_GET['step']);
$step = (string)($_POST['step'] ?? $_GET['step'] ?? '');
if (!SetupFlow::isKnownStep($step)) {
    $step = SetupFlow::firstStep($mode);
}

// Entering add_store fresh must start a genuinely new store. The session can
// still be carrying the id of the store built during first-run setup (it is
// only cleared on the completion screen), and the store handler deliberately
// reuses that id so navigating Back renames rather than duplicates — which
// would silently rename the operator's existing store instead of adding one.
if ($mode === 'add_store' && !$stepRequested) {
    unset($_SESSION['setup_store_id'], $_SESSION['setup_store_mode'], $_SESSION['setup_generated_seed']);
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX action for on-chain xpub validation + preview.
    if (isset($_POST['action']) && $_POST['action'] === 'validate_xpub') {
        header('Content-Type: application/json');
        // Catch Throwable, not Exception: a host missing the GMP extension
        // throws Error from gmp_init(), and an uncaught one turns this JSON
        // endpoint into an HTML fatal — which the wizard's JS could only
        // report as "could not reach the server" while the server was fine.
        try {
            require_once __DIR__ . '/includes/onchain/wallet.php';
            $xpub = trim($_POST['xpub'] ?? '');
            $network = $_POST['network'] ?? 'mainnet';
            $type = $_POST['address_type'] ?? 'P2WPKH';
            $check = OnchainWallet::validateXpub($xpub, $network, $type);
            $preview = [];
            if ($check['valid']) {
                $preview = OnchainWallet::deriveFirstN($xpub, $type, $network, 3);
            }
            echo json_encode([
                'valid' => $check['valid'],
                'error' => $check['error'],
                'warnings' => $check['warnings'],
                'inferredType' => $check['inferredType'],
                'inferredNetwork' => $check['inferredNetwork'],
                'preview' => $preview,
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'valid' => false,
                'error' => $e->getMessage(),
                'warnings' => [],
                'inferredType' => null,
                'inferredNetwork' => null,
                'preview' => [],
            ]);
        }
        exit;
    }

    // Handle AJAX action for saving URL mode
    if (isset($_POST['action']) && $_POST['action'] === 'save_url_mode') {
        header('Content-Type: application/json');
        $mode = $_POST['mode'] ?? 'router';
        if (in_array($mode, ['clean', 'direct', 'router'])) {
            Config::set('url_mode', $mode);
            echo json_encode(['success' => true, 'mode' => $mode]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid mode']);
        }
        exit;
    }

    // The screen sequence depends on whether an on-chain destination exists,
    // which the `onchain` handler may be about to change. Resolved again after
    // each handler runs so the advance lands on the right screen.
    $storeIdForFlow = $_SESSION['setup_store_id'] ?? null;
    $flowSteps = SetupFlow::stepSequence(
        $mode, SetupFlow::onchainState($storeIdForFlow)['configured'],
        $securityScreenNeeded, $isDesktop, $externalCron, $passwordPreseeded
    );
    // Set by the mints handler when it mints a fresh wallet seed. add_store
    // has to stop and show it rather than redirecting past it.
    $generatedSeedThisRequest = false;

    try {
        switch ($step) {
            case 'terms':
                if (empty($_POST['terms_legal']) || empty($_POST['terms_warranty']) || empty($_POST['terms_fee'])) {
                    throw new Exception('Please accept all three terms to continue.');
                }
                $step = SetupFlow::nextStep('terms', $flowSteps) ?? 'security';
                break;

            case 'security':
                if (empty($_POST['security_acknowledged'])) {
                    throw new Exception('Please confirm you have checked that your database is not reachable from the web.');
                }
                $step = SetupFlow::nextStep('security', $flowSteps) ?? 'store';
                break;

            case 'password':
                // Re-entry guard: refuse to overwrite an existing admin if
                // setup_complete was cleared (backup restore, manual purge,
                // partial corruption). Audit finding #3.
                $existingAdmin = Database::fetchOne(
                    "SELECT id FROM users WHERE role = 'admin' LIMIT 1"
                );
                if ($existingAdmin !== null) {
                    cashupay_status(403);
                    throw new Exception(
                        'An admin account already exists. Setup cannot be repeated.'
                        . ' Sign in and change credentials from the admin panel.'
                    );
                }

                $password = $_POST['password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';
                // Optional recovery email — powers the email-link password
                // reset. Blank is fine (the file-based reset works without it).
                $email = trim($_POST['admin_email'] ?? '');

                if (strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters');
                }
                if ($password !== $confirm) {
                    throw new Exception('Passwords do not match');
                }
                if ($email !== '' && ($emailErr = Auth::validateEmail($email)) !== null) {
                    throw new Exception($emailErr);
                }

                Auth::setAdminPassword($password, $email !== '' ? $email : null);
                $step = SetupFlow::nextStep('password', $flowSteps) ?? 'store';
                break;

            case 'store':
                $storeName = trim($_POST['store_name'] ?? '');
                if ($storeName === '') {
                    throw new Exception('Give your store a name to continue.');
                }

                // Default display/quote currency. Payments always settle in
                // Bitcoin; this only drives how invoices are shown and priced.
                // The field is a controlled <select>, so an out-of-range value
                // means tampering — fall back to sat rather than hard-fail an
                // onboarding step. Normalized to match the admin settings page:
                // sat lowercase, fiat codes uppercase.
                $supportedCurrencies = Config::getSupportedDisplayCurrencies();
                $rawCurrency = (string)($_POST['default_currency'] ?? 'sat');
                $defaultCurrency = (strtolower($rawCurrency) === 'sat' || strtolower($rawCurrency) === 'sats')
                    ? 'sat' : strtoupper($rawCurrency);
                if (!in_array($defaultCurrency, $supportedCurrencies, true)) {
                    $defaultCurrency = 'sat';
                }

                // Coming back to this screen mid-wizard must rename the store
                // already under construction, not create a second one — the
                // first would be orphaned the moment the session id is
                // replaced, and it would keep whatever rails were configured.
                // Only reuse a store this same wizard run created: a session
                // carried over from first-run setup into add_store (or the
                // reverse) would rename the operator's existing store instead
                // of adding one.
                $storeId = $_SESSION['setup_store_id'] ?? null;
                $storeMode = $mode === 'add_store' ? 'add_store' : 'first_run';
                if ($storeId !== null
                    && (($_SESSION['setup_store_mode'] ?? null) !== $storeMode
                        || Config::getStore($storeId) === null)) {
                    $storeId = null;
                }

                $clash = $storeId === null
                    ? Database::fetchOne(
                        "SELECT id FROM stores WHERE LOWER(name) = LOWER(?)",
                        [$storeName]
                    )
                    : Database::fetchOne(
                        "SELECT id FROM stores WHERE LOWER(name) = LOWER(?) AND id != ?",
                        [$storeName, $storeId]
                    );
                if ($clash) {
                    throw new Exception('You already have a store with that name. Try another one.');
                }

                if ($storeId === null) {
                    // primary_mint_source='setup' marks the primary mint as
                    // un-configured. Unlike the old wizard we do NOT apply the
                    // trusted-mints list here: the operator has not yet said
                    // whether they want mints at all, and auto-populating one
                    // now would have to be undone on the `mints` screen if they
                    // say no. TrustedMints::applyToNewStore runs from there.
                    $storeId = Database::generateId('store');
                    Database::insert('stores', [
                        'id' => $storeId,
                        'name' => $storeName,
                        'default_currency' => $defaultCurrency,
                        'primary_mint_source' => 'setup',
                        'created_at' => Database::timestamp(),
                    ]);
                } else {
                    Config::updateStore($storeId, [
                        'name' => $storeName,
                        'default_currency' => $defaultCurrency,
                    ]);
                }

                $_SESSION['setup_store_id'] = $storeId;
                $_SESSION['setup_store_mode'] = $storeMode;
                // Re-resolve: a brand new store has no on-chain rail, but one
                // reached by going Back may already have one, which decides
                // whether the zero-conf screen is in the sequence.
                $flowSteps = SetupFlow::stepSequence(
                    $mode, SetupFlow::onchainState($storeId)['configured'],
                    $securityScreenNeeded, $isDesktop, $externalCron, $passwordPreseeded
                );
                $step = SetupFlow::nextStep('store', $flowSteps) ?? 'onchain';
                break;

            case 'onchain':
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please restart setup.');
                }
                $onchainAction = $_POST['onchain_action'] ?? '';

                if ($onchainAction === 'skip') {
                    // Nothing saved; the zero-conf screen drops out of the
                    // sequence because there is no on-chain rail to time.
                    $flowSteps = SetupFlow::stepSequence($mode, false, $securityScreenNeeded, $isDesktop, $externalCron, $passwordPreseeded);
                    $step = SetupFlow::nextStep('onchain', $flowSteps) ?? 'lightning';
                    break;
                }
                if ($onchainAction !== 'save') {
                    // GET landing — fall through to render the form.
                    break;
                }

                require_once __DIR__ . '/includes/onchain/wallet.php';
                $onchainMode = ($_POST['onchain_address_mode'] ?? 'xpub') === 'static' ? 'static' : 'xpub';

                if ($onchainMode === 'static') {
                    $staticAddress = trim($_POST['onchain_static_address'] ?? '');
                    if ($staticAddress === '') {
                        throw new Exception('Enter a Bitcoin address, or go back and use an xpub.');
                    }
                    // Address encodings differ per network and the operator is
                    // never asked which one they are on, so try each in turn.
                    // Order matters: bcrt1… fails testnet and only passes
                    // regtest, and tb1… passes testnet before regtest is tried.
                    $network = null;
                    foreach (['mainnet', 'testnet', 'regtest'] as $candidate) {
                        if (OnchainWallet::validateAddress($staticAddress, $candidate)['valid']) {
                            $network = $candidate;
                            break;
                        }
                    }
                    if ($network === null) {
                        throw new Exception(
                            'We could not read that as a Bitcoin address. Copy it again from your wallet and try once more.'
                        );
                    }
                    // onchain_address_mode / onchain_static_address /
                    // onchain_static_tweak_range are intentionally outside
                    // Config::updateStore's allowlist, so the whole on-chain
                    // payload goes through Database::update — the same path
                    // admin.php's save_onchain handler uses. Clearing the xpub
                    // enforces the "one OR the other" invariant.
                    Database::update('stores', [
                        'onchain_address_mode' => 'static',
                        'onchain_static_address' => $staticAddress,
                        'onchain_static_tweak_range' => 1000,
                        'onchain_xpub' => null,
                        'onchain_network' => $network,
                    ], 'id = ?', [$storeId]);
                } else {
                    $xpub = trim($_POST['onchain_xpub'] ?? '');
                    if ($xpub === '') {
                        throw new Exception('Paste your extended public key to continue.');
                    }
                    // Network and address type come from the key's SLIP-32
                    // prefix. SLIP-32 only distinguishes mainnet from the
                    // testnet family, so testnet-family keys carry an explicit
                    // sub-network picked on the form; mainnet keys never show
                    // that control. An xpub/tpub prefix (BIP44) says nothing
                    // useful about the address type, so those default to
                    // P2WPKH and the operator confirms against the address
                    // preview, with a one-click switch to wrapped SegWit.
                    // A host that can't run the xpub stack at all (no GMP)
                    // must say so — the generic "couldn't read that" message
                    // below would send the operator hunting for a paste typo
                    // that isn't there.
                    if (($envError = OnchainWallet::environmentError()) !== null) {
                        throw new Exception($envError);
                    }
                    $probe = OnchainWallet::validateXpub($xpub, 'mainnet', 'P2WPKH');
                    $inferredNetwork = $probe['inferredNetwork'];
                    $inferredType = $probe['inferredType'];
                    if ($inferredNetwork === null) {
                        // validateXpub could not read the version bytes at all.
                        // Its own message ("base58check decode failed") is
                        // developer-speak; the operator just needs to re-copy.
                        throw new Exception(
                            'We couldn\'t read that as an extended public key. '
                            . 'Check for a missing character and paste it again.'
                        );
                    }
                    if ($inferredNetwork === 'mainnet') {
                        $network = 'mainnet';
                    } else {
                        $network = $_POST['onchain_testnet_network'] ?? 'testnet';
                        if (!in_array($network, ['testnet', 'signet', 'regtest'], true)) {
                            $network = 'testnet';
                        }
                    }
                    // P2PKH is not a supported receive type here; it only ever
                    // appears as the inferred type of a BIP44-prefixed key.
                    $type = $_POST['onchain_address_type'] ?? '';
                    if (!in_array($type, ['P2WPKH', 'P2SH-P2WPKH'], true)) {
                        $type = ($inferredType === 'P2SH-P2WPKH') ? 'P2SH-P2WPKH' : 'P2WPKH';
                    }

                    $check = OnchainWallet::validateXpub($xpub, $network, $type);
                    if (!$check['valid']) {
                        throw new Exception(
                            $check['error']
                            ?: 'We could not read that as an extended public key. Check for a missing character and paste it again.'
                        );
                    }
                    // Same allowlist caveat as the static branch above.
                    Database::update('stores', [
                        'onchain_address_mode' => 'xpub',
                        'onchain_xpub' => $xpub,
                        'onchain_static_address' => null,
                        'onchain_network' => $network,
                        'onchain_address_type' => $type,
                    ], 'id = ?', [$storeId]);
                }

                $flowSteps = SetupFlow::stepSequence($mode, true, $securityScreenNeeded, $isDesktop, $externalCron, $passwordPreseeded);
                $step = SetupFlow::nextStep('onchain', $flowSteps) ?? 'zeroconf';
                break;

            case 'zeroconf':
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please restart setup.');
                }
                if (!isset($_POST['zero_conf'])) {
                    // GET landing — fall through to render the form.
                    break;
                }
                $zeroConf = $_POST['zero_conf'] === '1';
                Config::updateStore($storeId, [
                    'onchain_min_confs' => $zeroConf ? 0 : 1,
                ]);
                $step = SetupFlow::nextStep('zeroconf', $flowSteps) ?? 'lightning';
                break;

            case 'lightning':
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please restart setup.');
                }
                $lnAction = $_POST['lightning_action'] ?? '';
                if ($lnAction !== 'save' && $lnAction !== 'skip') {
                    // GET landing — fall through to render the form.
                    break;
                }

                $lnAddress = trim($_POST['lightning_address'] ?? '');
                // The noffer section renders one input per entry
                // (name="noffers[]"), so the value arrives as an array; a
                // scalar is accepted too for direct POSTs. Blank rows — the
                // always-present empty input, or an added-then-abandoned row —
                // are dropped, not errors.
                $nofferInput = $_POST['noffers'] ?? [];
                $nofferPosted = [];
                foreach (is_array($nofferInput) ? $nofferInput : [$nofferInput] as $nofferRow) {
                    $nofferRow = trim((string)$nofferRow);
                    if ($nofferRow !== '') {
                        $nofferPosted[] = $nofferRow;
                    }
                }
                $nwc = trim($_POST['nwc'] ?? '');
                // Saved-connection controls (see the render below): a pasted
                // URI replaces, the clear checkbox removes, and otherwise the
                // hidden keep ref preserves the stored connection.
                if ($nwc === '' && ($_POST['nwc_clear'] ?? '') !== '1') {
                    $nwc = trim($_POST['nwc_keep_ref'] ?? '');
                }
                // Strike API key: same saved-secret controls as NWC — a pasted
                // key replaces, the clear checkbox removes, and otherwise the
                // hidden keep ref preserves the stored key.
                $strikeKey = trim($_POST['strike_api_key'] ?? '');
                if ($strikeKey === '' && ($_POST['strike_clear'] ?? '') !== '1') {
                    $strikeKey = trim($_POST['strike_keep_ref'] ?? '');
                }

                // Strike on-chain option: invoice addresses are minted in the
                // merchant's Strike account (fallback: the store's own
                // xpub/static address). Gated below behind a receive-request
                // scope probe before it is persisted.
                $strikeOnchainWanted = ($_POST['strike_onchain'] ?? '') === '1';

                if ($lnAction === 'skip') {
                    $lnAddress = '';
                    $nofferPosted = [];
                    $nwc = '';
                    $strikeKey = '';
                    $strikeOnchainWanted = false;
                }

                // Validate separately so the operator gets a message naming the
                // field they got wrong, rather than chainFromLists' generic one.
                if ($lnAddress !== '' && !StoreLnAddresses::isValid($lnAddress)) {
                    throw new Exception('Lightning addresses look like myname@wallet.com. Check the spelling and try again.');
                }
                foreach ($nofferPosted as $nofferRow) {
                    if (!ClinkNoffer::isValid($nofferRow)) {
                        throw new Exception('That noffer doesn\'t look right. It should start with noffer1 — copy the whole string from your wallet.');
                    }
                }
                // A previously stored connection round-trips as an opaque
                // keep:<id> ref (the raw URI embeds the wallet secret and is
                // never sent to the browser); resolveKeepRefs turns it back
                // into the stored value below. Only a full new URI is
                // shape-checked here.
                if ($nwc !== '' && !str_starts_with($nwc, StoreLnAddresses::KEEP_REF_PREFIX)
                        && !NwcUri::isValid($nwc)) {
                    throw new Exception('That NWC connection string doesn\'t look right. It should start with nostr+walletconnect:// — copy the whole string from your wallet.');
                }
                require_once __DIR__ . '/includes/strike/client.php';
                if ($strikeKey !== '' && !str_starts_with($strikeKey, StoreLnAddresses::KEEP_REF_PREFIX)
                        && !StrikeClient::isValidKey($strikeKey)) {
                    throw new Exception('That Strike API key doesn\'t look right. Copy the whole key from the Strike dashboard (it\'s one long block of letters and numbers).');
                }

                // Environment gate: the CLINK client can't sign Nostr
                // requests without bignum math (GMP or BCMath), so a noffer
                // saved here would silently drop Lightning from the checkout.
                // The screen renders the field disabled; this catches a direct
                // POST past that.
                require_once __DIR__ . '/includes/clink/client.php';
                $nofferEnvError = ClinkClient::environmentError();
                $storedNoffers = [];
                foreach (StoreLnAddresses::listForStore($storeId) as $lnRow) {
                    if ($lnRow['type'] === StoreLnAddresses::TYPE_NOFFER) {
                        $storedNoffers[] = $lnRow['address'];
                    }
                }
                if ($nofferEnvError !== null) {
                    $storedNofferKeys = array_map('strtolower', $storedNoffers);
                    foreach ($nofferPosted as $nofferRow) {
                        if (!in_array(strtolower($nofferRow), $storedNofferKeys, true)) {
                            throw new Exception('noffers can\'t be used on this server yet. ' . $nofferEnvError);
                        }
                    }
                }
                // A disabled input doesn't submit, so on a gated host a save
                // would silently delete previously stored noffers. Keep them —
                // they start working the moment the host gains GMP, and the
                // screen says why they're inert until then.
                $noffers = $nofferPosted;
                if ($lnAction === 'save' && $nofferEnvError !== null && $noffers === []) {
                    $noffers = $storedNoffers;
                }

                // NWC: resolve a keep:<id> ref back to the stored connection
                // string, then apply the same env gate + keep-on-gated-host
                // rules as the noffer field above. NWC signs Nostr requests
                // through the same stack, so it shares the GMP requirement.
                require_once __DIR__ . '/includes/nwc/client.php';
                $nwcEnvError = NwcClient::environmentError();
                [$nwcList, $nwcKeptKeys] = StoreLnAddresses::resolveKeepRefs(
                    $storeId, $nwc !== '' ? [$nwc] : []
                );
                if ($nwcList !== [] && $nwcEnvError !== null && $nwcKeptKeys === []) {
                    throw new Exception('NWC connections can\'t be used on this server yet. ' . $nwcEnvError);
                }
                if ($lnAction === 'save' && $nwcEnvError !== null && $nwcList === []) {
                    $storedNwc = [];
                    foreach (StoreLnAddresses::listForStore($storeId) as $lnRow) {
                        if ($lnRow['type'] === StoreLnAddresses::TYPE_NWC) {
                            $storedNwc[] = $lnRow['address'];
                        }
                    }
                    $nwcList = $storedNwc;
                }

                // Strike API key: resolve a keep:<id> ref back to the stored
                // key. No environment gate — the Strike client is plain HTTPS
                // + JSON, no bignum math involved.
                [$strikeList, ] = StoreLnAddresses::resolveKeepRefs(
                    $storeId, $strikeKey !== '' ? [$strikeKey] : [], StoreLnAddresses::TYPE_STRIKE
                );

                // Strike first, then address, NWC, noffer as final fallback —
                // the order Invoice::create walks the chain at payment time.
                $chain = StoreLnAddresses::chainFromLists(
                    $lnAddress !== '' ? [$lnAddress] : [],
                    $noffers,
                    $nwcList,
                    $strikeList
                );
                // Same LUD-21 gate as the admin auto-cashout card: a new
                // address whose host can't confirm a verify URL (or can't be
                // reached) throws here, keeping the operator on this screen
                // with the reason instead of onboarding a Lightning rail that
                // silently vanishes from checkout. An address already stored
                // for this store passes through, so revisiting the screen
                // never locks the operator out.
                $gated = StoreLnAddresses::probeAndGateChain($storeId, $chain);

                // Strike on-chain gate, BEFORE anything is persisted so a
                // refusal leaves both the chain and the flag untouched (see
                // OnchainConfig::gateStrikeOnchainEnable for the rules).
                require_once __DIR__ . '/includes/onchain/config.php';
                if ($strikeOnchainWanted) {
                    $finalStrikeKeys = [];
                    foreach ($gated['entries'] as $gatedEntry) {
                        if (($gatedEntry['type'] ?? '') === StoreLnAddresses::TYPE_STRIKE) {
                            $finalStrikeKeys[] = (string)$gatedEntry['address'];
                        }
                    }
                    $storedStrikeKeys = [];
                    foreach (StoreLnAddresses::listForStore($storeId) as $lnRow) {
                        if ($lnRow['type'] === StoreLnAddresses::TYPE_STRIKE) {
                            $storedStrikeKeys[$lnRow['address']] = true;
                        }
                    }
                    OnchainConfig::gateStrikeOnchainEnable(
                        $storeId,
                        $finalStrikeKeys,
                        $storedStrikeKeys,
                        OnchainConfig::strikeEnabledForStore($storeId)
                    );
                }

                StoreLnAddresses::replaceForStore($storeId, $gated['entries']);
                OnchainConfig::setStrikeEnabled($storeId, $strikeOnchainWanted ? 1 : 0);
                // Auto-cashout mode isn't decided here: which rail sweeps the
                // mint balance depends on the swaps and mints answers still to
                // come. setupResolveAutoCashout() settles it at the end.

                $step = SetupFlow::nextStep('lightning', $flowSteps) ?? 'swaps';
                break;

            case 'swaps':
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please restart setup.');
                }
                if (!isset($_POST['swaps_enabled'])) {
                    // GET landing — fall through to render the form.
                    break;
                }
                $swapsWanted = $_POST['swaps_enabled'] === '1';
                if ($swapsWanted && !SetupFlow::onchainState($storeId)['hasXpub']) {
                    throw new Exception(
                        'Submarine swaps send Bitcoin to your on-chain wallet, so they need an xpub. '
                        . 'Go back and add one to turn this on.'
                    );
                }
                SwapsConfig::setStoreOverride(
                    $storeId,
                    $swapsWanted ? SwapsConfig::FORCE_ON : SwapsConfig::FORCE_OFF
                );
                $step = SetupFlow::nextStep('swaps', $flowSteps) ?? 'mints';
                break;

            case 'mints':
                $storeId = $_SESSION['setup_store_id'] ?? null;
                if (!$storeId) {
                    throw new Exception('Store not found. Please restart setup.');
                }
                if (!isset($_POST['mints_enabled'])) {
                    // GET landing — fall through to render the form.
                    break;
                }
                $mintsWanted = $_POST['mints_enabled'] === '1';

                if (!$mintsWanted) {
                    // Leave mint_url/seed_phrase NULL (Config::isStoreConfigured
                    // stays false, so Invoice::create never offers a mint rail)
                    // and pin the per-store strict flag so a mint can't be
                    // acquired later without an explicit decision.
                    SwapsConfig::setStoreStrictOverride($storeId, SwapsConfig::FORCE_ON);
                    Config::updateStore($storeId, ['primary_mint_source' => 'manual']);
                } else {
                    $primaryUrl = rtrim(trim($_POST['mint_url'] ?? ''), '/');
                    $backupUrl = rtrim(trim($_POST['backup_mint_url'] ?? ''), '/');
                    $unit = trim($_POST['mint_unit'] ?? 'sat') ?: 'sat';

                    if ($primaryUrl === '') {
                        throw new Exception('Pick a main mint to continue, or choose "No thanks" to run without mints.');
                    }
                    if ($backupUrl === '') {
                        throw new Exception('Pick a backup mint to continue.');
                    }
                    if (strcasecmp($primaryUrl, $backupUrl) === 0) {
                        throw new Exception(
                            'Your backup mint needs to be different from your main one — that\'s the whole point of a backup.'
                        );
                    }

                    require_once __DIR__ . '/cashu-wallet-php/CashuWallet.php';
                    try {
                        $primaryUnits = \Cashu\Wallet::getSupportedUnits($primaryUrl);
                    } catch (Exception $e) {
                        throw new Exception('We couldn\'t reach that mint. Check the URL, or pick a different one.');
                    }
                    if (empty($primaryUnits) || !isset($primaryUnits[$unit])) {
                        throw new Exception(
                            'Your main mint doesn\'t offer ' . htmlspecialchars($unit) . '. Pick a different mint.'
                        );
                    }
                    try {
                        $backupUnits = \Cashu\Wallet::getSupportedUnits($backupUrl);
                    } catch (Exception $e) {
                        throw new Exception('We couldn\'t reach your backup mint. Check the URL, or pick a different one.');
                    }
                    if (empty($backupUnits) || !isset($backupUnits[$unit])) {
                        throw new Exception(
                            'Your backup mint needs to support ' . htmlspecialchars($unit)
                            . ', same as your main mint. This one doesn\'t.'
                        );
                    }

                    Config::updateStore($storeId, [
                        'mint_url' => $primaryUrl,
                        'mint_unit' => $unit,
                        'primary_mint_source' => 'manual',
                    ]);

                    // The trusted-list applier can have pre-seeded backups on a
                    // previous pass through this screen; adding a duplicate
                    // would trip the (store_id, mint_url) unique constraint.
                    $alreadyBacked = false;
                    foreach (Config::getStoreBackupMints($storeId) as $existing) {
                        if (strcasecmp(rtrim($existing['mint_url'], '/'), $backupUrl) === 0) {
                            $alreadyBacked = true;
                            break;
                        }
                    }
                    if (!$alreadyBacked) {
                        Config::addStoreBackupMint($storeId, $backupUrl, $unit, 100);
                    }

                    // Seed for this store's mint wallet. Generated silently and
                    // shown on the completion screen — the operator is told to
                    // write it down there rather than being gated on it here.
                    $existingSeed = Config::getStore($storeId)['seed_phrase'] ?? null;
                    if (empty($existingSeed)) {
                        $mnemonic = \Cashu\Mnemonic::generate();
                        Config::updateStore($storeId, ['seed_phrase' => $mnemonic]);
                        $_SESSION['setup_generated_seed'] = $mnemonic;
                        $generatedSeedThisRequest = true;
                    }

                    // Now that mints are wanted, let the trusted list top up the
                    // backup chain (it leaves a 'manual' primary alone).
                    require_once __DIR__ . '/includes/trusted_mints.php';
                    try {
                        TrustedMints::applyToNewStore($storeId);
                    } catch (Exception $e) {
                        error_log('TrustedMints::applyToNewStore failed in setup: ' . $e->getMessage());
                    }
                }

                // Every rail answer is in, so the auto-cashout rail can now be
                // settled: Lightning when there's a destination, else a
                // submarine swap to the xpub when mints + swaps make that
                // possible, else off. See SetupFlow::resolveAutoCashout.
                SetupFlow::resolveAutoCashout($storeId);

                if ($mode === 'add_store') {
                    // Don't redirect straight to admin when a seed was just
                    // generated — it would never be shown. Fall through to the
                    // add_store completion screen, which displays it once and
                    // links on. With no seed there's nothing to show, so go
                    // straight back to admin as before.
                    if ($generatedSeedThisRequest) {
                        $step = 'store_created';
                        break;
                    }
                    $createdStoreId = $storeId;
                    unset($_SESSION['setup_store_id'], $_SESSION['setup_store_mode']);
                    header('Location: ' . Urls::admin() . '?store_created=' . urlencode($createdStoreId));
                    exit;
                }

                // The install is usable from here; everything after this screen
                // is advisory, so complete the setup before showing it.
                Config::set('setup_complete', true);
                $step = SetupFlow::nextStep('mints', $flowSteps) ?? 'cron';
                break;

            case 'cron':
                $step = 'done';
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$generatedSeed = $_SESSION['setup_generated_seed'] ?? null;


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

/**
 * Emit the client-side URL-mode probe (standalone only): detect which routing
 * style the host supports (clean > direct > router) and save it through the
 * save_url_mode AJAX action. Client-side because PHP's built-in server can't
 * make HTTP requests to itself.
 *
 * The security screen renders progress into its #url-mode-* elements; when
 * that screen is skipped (data directory outside the web root) the same probe
 * runs silently from the terms screen instead — so every UI touch below is
 * guarded on the elements existing.
 */
function renderUrlModeDetectionScript(): void { ?>
    <script>
    (function() {
        async function detectAndSaveUrlMode() {
            const loadingEl = document.getElementById('url-mode-loading');
            const resultEl = document.getElementById('url-mode-result');
            const statusEl = document.getElementById('url-mode-status');
            const messageEl = document.getElementById('url-mode-message');
            const detailsEl = document.getElementById('url-mode-details');

            const baseUrl = <?= json_encode(Urls::siteBase()) ?>;
            const setupUrl = <?= json_encode(setupSelfUrl()) ?>;

            // Probe each routing style. The /health probe tells
            // "clean" (pretty URLs via the front-controller
            // rewrite) apart from "direct": /health is cron-key
            // gated, so it answers 403 when the extension-less
            // path routes and 404 when it does not. 200/503 also
            // count as "resolved". The /api/v1 probes accept
            // 200/503 (503 = setup not complete yet).
            const tests = {
                clean:  { url: baseUrl + '/health', works: false, ok: [200, 403, 503] },
                direct: { url: baseUrl + '/api/v1/server/info', works: false, ok: [200, 503] },
                router: { url: baseUrl + '/router.php/api/v1/server/info', works: false, ok: [200, 503] }
            };

            for (const [mode, test] of Object.entries(tests)) {
                try {
                    const response = await fetch(test.url, { method: 'GET', mode: 'same-origin' });
                    test.works = test.ok.includes(response.status);
                } catch (e) {
                    test.works = false;
                }
            }

            // Prefer the nicest routing the host supports:
            // clean > direct > router.
            let selectedMode = null;
            if (tests.clean.works) {
                selectedMode = 'clean';
            } else if (tests.direct.works) {
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

            // Update UI (present on the security screen only)
            if (!loadingEl || !resultEl || !statusEl || !messageEl || !detailsEl) {
                return;
            }
            loadingEl.style.display = 'none';
            resultEl.style.display = 'flex';

            if (selectedMode === 'clean') {
                statusEl.className = 'status OK';
                messageEl.textContent = 'Clean URLs working';
                detailsEl.textContent = 'Pretty URLs like /admin and /pay/... are supported.';
            } else if (selectedMode === 'direct') {
                statusEl.className = 'status OK';
                messageEl.textContent = 'Direct URLs working';
                detailsEl.textContent = 'API URLs like /api/v1/... are supported.';
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
    })();
    </script>
<?php }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BareBits Setup</title>
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
        input[type="number"],
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

        /* Dark dropdown options so the open <select> menu stays legible
           against the dark theme — <option> elements ignore the parent
           <select>'s color/background by default. */
        select option {
            background-color: #1a202c;
            color: #e2e8f0;
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

        .info {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.4);
            color: #bfdbfe;
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

        /* Inline info-tip: a small "i" badge with a popup on hover/focus. */
        .info-tip {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            margin-left: 0.4rem;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
            color: #a0aec0;
            font-size: 10px;
            font-weight: 700;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            cursor: help;
            user-select: none;
        }
        .info-tip:hover, .info-tip:focus-within {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }
        .info-tip .info-tip-text {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: 130%;
            left: 50%;
            transform: translateX(-50%);
            min-width: 260px;
            max-width: 320px;
            background: #1a202c;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px;
            padding: 0.6rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 400;
            line-height: 1.4;
            color: #e2e8f0;
            text-align: left;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            z-index: 10;
            transition: opacity 0.15s;
            pointer-events: none;
        }
        .info-tip:hover .info-tip-text,
        .info-tip:focus-within .info-tip-text {
            visibility: visible;
            opacity: 1;
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

        /* Loading spinner (mint discovery modal) */
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

        /* Small inline spinner for the mint auto-pick status row */
        .spinner-inline {
            width: 18px;
            height: 18px;
            border: 3px solid rgba(255, 255, 255, 0.2);
            border-top-color: #f7931a;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            flex-shrink: 0;
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
            <?php
            // Render-time view of the sequence. The zero-conf screen is only
            // part of it once the store actually has an on-chain destination,
            // so the counter never promises a screen that won't appear.
            $renderStoreId = $_SESSION['setup_store_id'] ?? null;
            $renderOnchain = SetupFlow::onchainState($renderStoreId);
            $renderSteps = SetupFlow::stepSequence(
                $mode, $renderOnchain['configured'], $securityScreenNeeded, $isDesktop, $externalCron, $passwordPreseeded
            );
            $displayIndex = array_search($step, $renderSteps, true);
            $totalSteps = count($renderSteps);
            // The add_store hand-off panel sits outside the sequence; show it
            // as the final step rather than resetting the counter to 1.
            $displayStep = $step === SetupFlow::ADD_STORE_COMPLETE
                ? $totalSteps
                : ($displayIndex === false ? 1 : $displayIndex + 1);
            $backStep = SetupFlow::backStep($step, $renderSteps);
            $backUrl = $backStep === null
                ? null
                : setupSelfUrl() . '?step=' . urlencode($backStep)
                    . ($mode === 'add_store' ? '&mode=add_store' : '');
            ?>
            <h1><?= $mode === 'add_store' ? 'Add New Store' : 'BareBits Setup' ?></h1>
            <p class="subtitle">Step <?= (int)$displayStep ?> of <?= (int)$totalSteps ?></p>

            <div class="steps">
                <?php for ($i = 1; $i <= $totalSteps; $i++): ?>
                    <div class="step-dot <?= $i < $displayStep ? 'completed' : ($i === $displayStep ? 'active' : '') ?>"></div>
                <?php endfor; ?>
            </div>

            <?php if ($error): ?>
                <div class="error" id="setup-error"><?= htmlspecialchars($error) ?></div>
                <script>
                // A failed save re-renders this page via POST, and browsers
                // restore the pre-submit scroll position on that navigation —
                // on the longer screens (lightning, mints) the operator can be
                // scrolled well past this banner and never see why the save
                // failed. Pin the view to the top whenever an error rendered.
                (function () {
                    if ('scrollRestoration' in history) {
                        history.scrollRestoration = 'manual';
                    }
                    window.scrollTo(0, 0);
                    // Scroll restoration can also fire after the document
                    // finishes loading; win that race too.
                    window.addEventListener('load', function () {
                        window.scrollTo(0, 0);
                    });
                })();
                </script>
            <?php endif; ?>

            <?php if ($step === 'terms'): ?>
                <!-- Screen: terms of service acknowledgement (first-run gate) -->
                <?php
                // Rate shown on the fee acknowledgement below. This is the
                // BareBits development fee only (CASHUPAY_DEV_FEE_PERCENT);
                // trailing zeros are trimmed so "1" renders as "1%" and a
                // fractional rate like 1.5 renders as "1.5%".
                $devFeeDisplay = rtrim(rtrim(number_format((float) CASHUPAY_DEV_FEE_PERCENT, 2), '0'), '.');
                ?>
                <h2 style="margin-bottom: 1rem;">🤝 Let's agree on a few things</h2>
                <p style="margin-bottom: 0.75rem;">
                    Almost ready to roll! First, a quick read-through. 👀
                </p>
                <p style="margin-bottom: 1.5rem;">
                    Tick all three boxes and we'll get you set up.
                </p>

                <form method="post">
                    <input type="hidden" name="step" value="terms">

                    <div class="checkbox-group" style="margin: 1.5rem 0;">
                        <input type="checkbox" id="terms_legal" name="terms_legal" required>
                        <label for="terms_legal">
                            I promise not to use this software for anything
                            illegal, and I agree with the terms of the
                            <a href="https://github.com/BareBits/cashupayserver/blob/main/LICENSE.md" target="_blank" rel="noopener" style="color: #63b3ed;">license</a>
                            and
                            <a href="https://github.com/BareBits/cashupayserver/blob/main/USE_POLICY.md" target="_blank" rel="noopener" style="color: #63b3ed;">use policy</a>.
                        </label>
                    </div>

                    <div class="checkbox-group" style="margin: 1.5rem 0;">
                        <input type="checkbox" id="terms_warranty" name="terms_warranty" required>
                        <label for="terms_warranty">
                            I agree that this software comes as-is — no warranty —
                            and the developers can't be blamed for any lost funds.
                        </label>
                    </div>

                    <div class="checkbox-group" style="margin: 1.5rem 0;">
                        <input type="checkbox" id="terms_fee" name="terms_fee" required>
                        <label for="terms_fee">
                            I understand that a <?= htmlspecialchars($devFeeDisplay) ?>% fee is assessed on all incoming payments.
                        </label>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Continue →</button>
                </form>

                <?php if (!$securityScreenNeeded) {
                    // The security screen normally hosts the URL-mode probe;
                    // with that screen skipped (data directory outside the web
                    // root) it runs silently from here instead.
                    renderUrlModeDetectionScript();
                } ?>

            <?php elseif ($step === 'security'): ?>
                <!-- Screen: security check (requirements + DB exposure + URL mode) -->
                <h2 style="margin-bottom: 1rem;">🔒 Quick safety check</h2>
                <p style="margin-bottom: 0.75rem;">
                    Your payment database lives in a folder on this server. 📁
                </p>
                <p style="margin-bottom: 1.5rem;">
                    Before we go further, let's make sure the web can't peek at
                    it. We ran the checks below &mdash; everything should say OK. ✅
                </p>

                <?php
                // Check PHP requirements silently - only show if something
                // fails. Shared with the "can this screen be skipped?"
                // decision (SetupFlow::stepSequence) so the two never
                // disagree about what was checked.
                $failedChecks = SetupFlow::missingRequirements();
                $allPassed = $failedChecks === [];
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
                    <?php if (!function_exists('gmp_init')): ?>
                        <!-- BCMath satisfies the hard requirement (Cashu,
                             noffers, and NWC all fall back to it), but xpub
                             derivation and swap signing run through libraries
                             that only speak GMP. Flagged here, on the first
                             screen with a requirements list, so a shared-host
                             operator learns before investing time in the
                             wizard — the affected steps repeat it.
                             function_exists, not extension_loaded: hardened
                             hosts sometimes disable the functions while the
                             extension itself reports as loaded. -->
                        <div class="warning" style="margin-bottom: 1.5rem;">
                            <strong>Heads up: PHP's GMP extension is not enabled.</strong>
                            Payments, Lightning (NWC/noffers), and Cashu still work on
                            the slower BCMath fallback, but xpub-based on-chain wallets
                            and submarine swaps need GMP &mdash; ask your hosting
                            provider to enable <code>php-gmp</code> for the fastest,
                            best-tested cryptography and to unlock everything.
                        </div>
                    <?php endif; ?>
                    <!-- Security Check Section -->
                    <h3 style="margin-bottom: 0.75rem;">🛡️ Protecting your database</h3>
                    <p style="margin-bottom: 1rem; color: #a0aec0; font-size: 0.9rem;">
                        This file holds ecash with real monetary value, so it must never be downloadable over the web.
                    </p>
                    <p style="margin-bottom: 1rem; color: #a0aec0; font-size: 0.9rem;">
                        Not sure? Verify it manually, or ask someone you trust.
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

                    <!-- URL Mode Detection -->
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

                    <form method="post">
                        <input type="hidden" name="step" value="security">

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
                    })();
                    </script>
                    <?php renderUrlModeDetectionScript(); ?>
                <?php endif; ?>

            <?php elseif ($step === 'password'): ?>
                <!-- Screen: admin password -->
                <h2 style="margin-bottom: 1rem;">🔑 Create your admin password</h2>
                <p style="margin-bottom: 0.75rem;">
                    This is the password you'll use to sign in to your BareBits
                    dashboard. 🖥️
                </p>
                <p style="margin-bottom: 1.5rem;">
                    Pick something long and memorable &mdash; you'll only type it
                    now and then.
                </p>

                <form method="post">
                    <input type="hidden" name="step" value="password">

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <p class="help-text">Minimum 8 characters</p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>

                    <div class="form-group">
                        <label for="admin_email">Recovery Email <span style="color: #a0aec0; font-weight: normal;">(optional)</span></label>
                        <input type="email" id="admin_email" name="admin_email"
                               placeholder="you@example.com">
                        <p class="help-text">
                            Optional, but handy! Add an email and we can send you
                            a password-reset link. No email? No problem &mdash;
                            you can also reset it right from the server.
                        </p>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Continue</button>
                </form>

            <?php elseif ($step === 'store'): ?>
                <!-- Screen: store name -->
                <h2 style="margin-bottom: 1rem;">🏪 Let's name your store</h2>
                <?php if ($mode !== 'add_store'): ?>
                <p style="margin-bottom: 0.75rem;">
                    Welcome aboard! 🎉 Just a few quick questions and you'll be
                    up and running.
                </p>
                <p style="margin-bottom: 1rem;">
                    Nothing's set in stone &mdash; you can tweak all of it later
                    in your store settings.
                </p>
                <?php endif; ?>
                <p style="margin-bottom: 1.5rem; color: #a0aec0; font-size: 0.9rem;">
                    Up next, we'll hook up the off-server wallets your funds go
                    to. Feel free to use as many as you need.
                </p>

                <form method="post">
                    <input type="hidden" name="step" value="store">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <?php
                    // Prefill with the store under construction when the
                    // operator navigated back to this screen.
                    $storeUnderConstruction = $renderStoreId ? Config::getStore($renderStoreId) : null;
                    $storeNameExisting = $storeUnderConstruction['name'] ?? '';
                    $storeNameValue = $_POST['store_name'] ?? ($storeNameExisting ?: 'My Store');
                    // Prefill currency from the posted value (validation retry),
                    // then the store row, then default to sat.
                    $currencyValue = $_POST['default_currency']
                        ?? ($storeUnderConstruction['default_currency'] ?? 'sat');
                    $currencyValue = (strtolower((string)$currencyValue) === 'sat'
                        || strtolower((string)$currencyValue) === 'sats')
                        ? 'sat' : strtoupper((string)$currencyValue);
                    $currencyOptions = Config::getSupportedDisplayCurrencies();
                    if (!in_array($currencyValue, $currencyOptions, true)) {
                        $currencyValue = 'sat';
                    }
                    ?>
                    <div class="form-group">
                        <label for="store_name">Store name</label>
                        <input type="text" id="store_name" name="store_name"
                               value="<?= htmlspecialchars($storeNameValue) ?>" required>
                        <p class="help-text">Your customers see this on payment pages and receipts.</p>
                    </div>

                    <div class="form-group">
                        <label for="default_currency">Default currency</label>
                        <select id="default_currency" name="default_currency">
                            <?php foreach ($currencyOptions as $cur): ?>
                                <option value="<?= htmlspecialchars($cur) ?>"<?= $cur === $currencyValue ? ' selected' : '' ?>>
                                    <?= $cur === 'sat' ? 'Bitcoin (sats)' : htmlspecialchars($cur) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help-text">
                            Note: Payments are always received as Bitcoin. This only
                            affects how invoices are displayed and quoted.
                        </p>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Continue</button>
                </form>
                <?php if ($mode === 'add_store'): ?>
                    <a href="<?= htmlspecialchars(Urls::admin()) ?>" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem; text-align: center;">Cancel</a>
                <?php endif; ?>

            <?php elseif ($step === 'onchain'): ?>
                <!-- Screen: on-chain destination -->
                <?php
                $ocStore = $renderStoreId ? Config::getStore($renderStoreId) : null;
                $ocSavedXpub = (string)($ocStore['onchain_xpub'] ?? '');
                $ocSavedStatic = (string)($ocStore['onchain_static_address'] ?? '');
                $ocSavedMode = ($ocStore['onchain_address_mode'] ?? 'xpub') === 'static' ? 'static' : 'xpub';
                $ocSavedNetwork = (string)($ocStore['onchain_network'] ?? 'mainnet');
                require_once __DIR__ . '/includes/onchain/wallet.php';
                $ocEnvError = OnchainWallet::environmentError();
                // Don't greet a GMP-less operator with a pre-selected mode
                // that cannot work on their server.
                if ($ocEnvError !== null && $ocSavedXpub === '') {
                    $ocSavedMode = 'static';
                }
                ?>
                <h2 style="margin-bottom: 1rem;">⛓️ On-chain Bitcoin payments</h2>
                <p style="margin-bottom: 0.75rem;">
                    On-chain payments from your customers always land straight
                    in your off-server wallet. 👛
                </p>
                <p style="margin-bottom: 0.75rem;">
                    We <strong>strongly</strong> recommend using an xpub
                    (extended public key), though plain bare addresses work too.
                </p>
                <p style="margin-bottom: 1.25rem;">
                    An xpub lets BareBits create a fresh payment address for
                    every invoice. Without one, all invoices share a single
                    address &mdash; which can cause trouble if several are paid
                    at once, or a customer pays in multiple parts.
                </p>

                <?php if ($ocEnvError !== null): ?>
                    <div class="warning" style="margin-bottom: 1rem;">
                        <strong>Xpub wallets won't work on this server yet:</strong>
                        <?= htmlspecialchars($ocEnvError) ?>
                        A single Bitcoin address works fine in the meantime.
                    </div>
                <?php endif; ?>

                <form id="onchain-form" method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>">
                    <input type="hidden" name="step" value="onchain">
                    <input type="hidden" name="onchain_action" value="save">
                    <input type="hidden" name="onchain_address_mode" id="onchain_address_mode" value="<?= htmlspecialchars($ocSavedMode) ?>">
                    <input type="hidden" name="onchain_address_type" id="onchain_address_type" value="P2WPKH">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <div class="form-group" id="onchain-xpub-row"<?= $ocSavedMode === 'static' ? ' style="display:none;"' : '' ?>>
                        <label for="onchain_xpub">Extended public key (xpub)</label>
                        <textarea id="onchain_xpub" name="onchain_xpub" rows="2"
                                  style="width: 100%; font-family: monospace; font-size: 0.85rem;"
                                  placeholder="xpub&hellip; ypub&hellip; zpub&hellip;"><?= htmlspecialchars($ocSavedXpub) ?></textarea>
                        <p class="help-text">
                            Don't have an xpub? We suggest
                            <a href="https://electrum.org" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Electrum wallet</a>.
                            To find your xpub in Electrum, go to
                            Wallet &rarr; Information &rarr; Extended public key.
                        </p>
                    </div>

                    <!-- Only revealed for testnet-family keys: SLIP-32 version
                         bytes are shared by testnet, signet and regtest, so the
                         key itself cannot tell us which one this is. Mainnet
                         operators never see this control. -->
                    <div class="form-group" id="onchain-testnet-row" style="display:none;">
                        <label for="onchain_testnet_network">That looks like a test-network key. Which network?</label>
                        <select id="onchain_testnet_network" name="onchain_testnet_network">
                            <option value="testnet"<?= $ocSavedNetwork === 'testnet' ? ' selected' : '' ?>>testnet</option>
                            <option value="signet"<?= $ocSavedNetwork === 'signet' ? ' selected' : '' ?>>signet</option>
                            <option value="regtest"<?= $ocSavedNetwork === 'regtest' ? ' selected' : '' ?>>regtest</option>
                        </select>
                    </div>

                    <div class="form-group" id="onchain-static-row"<?= $ocSavedMode === 'static' ? '' : ' style="display:none;"' ?>>
                        <label for="onchain_static_address">Bitcoin address</label>
                        <input type="text" id="onchain_static_address" name="onchain_static_address"
                               style="width: 100%; font-family: monospace; font-size: 0.85rem;"
                               value="<?= htmlspecialchars($ocSavedStatic) ?>"
                               placeholder="bc1q&hellip; / 3&hellip; / 1&hellip;">
                        <p class="help-text">
                            The same address is reused for every invoice. Each
                            invoice gets a unique sat-offset so totals don't
                            collide, but customers must pay the exact amount in
                            a single transaction.
                        </p>
                    </div>

                    <p style="margin-bottom: 1rem;">
                        <a href="#" id="onchain-mode-toggle" style="color: #63b3ed; font-size: 0.9rem;">
                            <?= $ocSavedMode === 'static' ? 'Use an xpub instead (recommended)' : 'Use a single Bitcoin address instead' ?>
                        </a>
                    </p>

                    <div id="onchain-validation" style="display:none; margin: 1rem 0; padding: 0.75rem; border-radius: 6px;"></div>

                    <button type="button" class="btn btn-secondary" id="onchain-validate-btn"
                            style="width: 100%; margin-bottom: 0.5rem;<?= $ocSavedMode === 'static' ? ' display:none;' : '' ?>">
                        Check my xpub
                    </button>

                    <button type="submit" class="btn" id="onchain-save-btn" style="width: 100%;"<?= $ocSavedMode === 'static' ? '' : ' disabled' ?>>
                        Continue
                    </button>
                </form>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>" style="margin-top: 0.75rem;">
                    <input type="hidden" name="step" value="onchain">
                    <input type="hidden" name="onchain_action" value="skip">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary" style="width: 100%;">Skip for now</button>
                </form>

                <div class="warning" style="margin-top: 0.75rem;">
                    Without an on-chain wallet we can't send you Bitcoin
                    directly, use submarine swaps, or sweep funds out of a Cashu
                    mint. You can add one later in store settings.
                </div>

                <script>
                (function () {
                    var setupUrl = <?= json_encode(setupSelfUrl()) ?>;
                    var validateBtn = document.getElementById('onchain-validate-btn');
                    var saveBtn = document.getElementById('onchain-save-btn');
                    var box = document.getElementById('onchain-validation');
                    var modeField = document.getElementById('onchain_address_mode');
                    var typeField = document.getElementById('onchain_address_type');
                    var xpubRow = document.getElementById('onchain-xpub-row');
                    var staticRow = document.getElementById('onchain-static-row');
                    var testnetRow = document.getElementById('onchain-testnet-row');
                    var testnetSel = document.getElementById('onchain_testnet_network');
                    var toggle = document.getElementById('onchain-mode-toggle');

                    function applyMode() {
                        var isStatic = modeField.value === 'static';
                        xpubRow.style.display = isStatic ? 'none' : '';
                        staticRow.style.display = isStatic ? '' : 'none';
                        validateBtn.style.display = isStatic ? 'none' : '';
                        if (isStatic) { testnetRow.style.display = 'none'; }
                        toggle.textContent = isStatic
                            ? 'Use an xpub instead (recommended)'
                            : 'Use a single Bitcoin address instead';
                        // Static addresses are validated server-side on submit;
                        // xpubs must pass the preview check first so the
                        // operator has actually eyeballed the addresses.
                        saveBtn.disabled = !isStatic;
                        box.style.display = 'none';
                    }

                    toggle.addEventListener('click', function (e) {
                        e.preventDefault();
                        modeField.value = modeField.value === 'static' ? 'xpub' : 'static';
                        applyMode();
                    });

                    // A tpub/upub/vpub prefix means testnet-family; reveal the
                    // sub-network picker as soon as one is pasted rather than
                    // waiting for the validate round-trip.
                    function syncTestnetRow() {
                        var v = document.getElementById('onchain_xpub').value.trim().toLowerCase();
                        var isTestnet = /^(tpub|upub|vpub)/.test(v);
                        testnetRow.style.display = (isTestnet && modeField.value !== 'static') ? '' : 'none';
                    }
                    document.getElementById('onchain_xpub').addEventListener('input', function () {
                        syncTestnetRow();
                        saveBtn.disabled = true;
                        box.style.display = 'none';
                    });

                    function renderResult(data, triedType) {
                        box.style.display = 'block';
                        if (!data.valid) {
                            box.style.background = 'rgba(245, 101, 101, 0.15)';
                            box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                            box.textContent = data.error
                                || 'We couldn\'t read that as an extended public key. Check for a missing character and paste it again.';
                            saveBtn.disabled = true;
                            return;
                        }
                        typeField.value = triedType;
                        box.innerHTML = '';
                        box.style.background = 'rgba(72, 187, 120, 0.1)';
                        box.style.border = '1px solid rgba(72, 187, 120, 0.3)';
                        var intro = document.createElement('div');
                        intro.textContent = 'Looks good. Here are the first three addresses we\'d generate '
                            + '— check they match your wallet.';
                        box.appendChild(intro);
                        var pre = document.createElement('pre');
                        pre.style.cssText = 'margin:0.5rem 0 0; font-size:0.85rem;';
                        pre.textContent = (data.preview || []).map(function (a, i) {
                            return 'm/0/' + i + ' = ' + a;
                        }).join('\n');
                        box.appendChild(pre);
                        (data.warnings || []).forEach(function (w) {
                            var warn = document.createElement('div');
                            warn.style.cssText = 'margin-top:0.5rem; color:#f6ad55;';
                            warn.textContent = '⚠ ' + w;
                            box.appendChild(warn);
                        });
                        // A BIP44-prefixed key (xpub/tpub) says nothing about
                        // the address type, so wrapped-SegWit wallets need a
                        // way out without an "advanced settings" panel.
                        var other = triedType === 'P2WPKH' ? 'P2SH-P2WPKH' : 'P2WPKH';
                        var alt = document.createElement('a');
                        alt.href = '#';
                        alt.style.cssText = 'display:inline-block; margin-top:0.5rem; color:#63b3ed; font-size:0.85rem;';
                        alt.textContent = triedType === 'P2WPKH'
                            ? 'Not the addresses your wallet shows? Try wrapped SegWit'
                            : 'Try native SegWit instead';
                        alt.addEventListener('click', function (e) {
                            e.preventDefault();
                            runValidate(other);
                        });
                        box.appendChild(alt);
                        saveBtn.disabled = false;
                    }

                    async function runValidate(type) {
                        var xpub = document.getElementById('onchain_xpub').value.trim();
                        if (!xpub) {
                            box.style.display = 'block';
                            box.style.background = 'rgba(245, 101, 101, 0.15)';
                            box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                            box.textContent = 'Paste your extended public key to continue.';
                            saveBtn.disabled = true;
                            return;
                        }
                        var isTestnet = /^(tpub|upub|vpub)/.test(xpub.toLowerCase());
                        var network = isTestnet ? testnetSel.value : 'mainnet';
                        var body = new FormData();
                        body.append('action', 'validate_xpub');
                        body.append('xpub', xpub);
                        body.append('network', network);
                        body.append('address_type', type);
                        // Two very different failures used to share one message:
                        // fetch() rejecting (genuinely unreachable) and the server
                        // answering with a non-JSON error page (reachable but
                        // broken — e.g. a PHP fatal from a missing extension).
                        // Telling the operator "could not reach the server" for
                        // the latter sent them debugging their network instead of
                        // their PHP setup.
                        var resp = null;
                        try {
                            resp = await fetch(setupUrl, { method: 'POST', body: body });
                        } catch (e) {
                            box.style.display = 'block';
                            box.style.background = 'rgba(245, 101, 101, 0.15)';
                            box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                            box.textContent = 'Could not reach the server to check that key. Try again.';
                            saveBtn.disabled = true;
                            return;
                        }
                        try {
                            renderResult(await resp.json(), type);
                        } catch (e) {
                            box.style.display = 'block';
                            box.style.background = 'rgba(245, 101, 101, 0.15)';
                            box.style.border = '1px solid rgba(245, 101, 101, 0.3)';
                            box.textContent = 'The server hit an error while checking that key'
                                + (resp.status >= 400 ? ' (HTTP ' + resp.status + ')' : '')
                                + '. Check the server\'s PHP error log for details.';
                            saveBtn.disabled = true;
                        }
                    }

                    validateBtn.addEventListener('click', function () {
                        // Default to native SegWit; renderResult offers the
                        // wrapped-SegWit alternative when the preview is wrong.
                        runValidate('P2WPKH');
                    });
                    testnetSel.addEventListener('change', function () {
                        saveBtn.disabled = true;
                        box.style.display = 'none';
                    });

                    applyMode();
                    syncTestnetRow();
                })();
                </script>

            <?php elseif ($step === 'zeroconf'): ?>
                <!-- Screen: zero-conf vs one-confirmation -->
                <h2 style="margin-bottom: 1rem;">⚡ Zero-conf payments</h2>
                <p style="margin-bottom: 0.75rem;">
                    Want to enable zero-conf on-chain transactions? 🤔
                </p>
                <p style="margin-bottom: 0.75rem;">
                    With it on, invoices are marked paid instantly instead of
                    waiting for an on-chain confirmation (seconds to minutes,
                    depending on fees).
                </p>
                <p style="margin-bottom: 1.5rem;">
                    There's a small catch: a customer
                    <a href="https://getbarebits.com/help/Understanding%20Bitcoin/Zero-conf%20transactions/" target="_blank" rel="noopener" style="color: #63b3ed;">could try to game it</a>.
                    For most merchants, though, the extra speed is well worth
                    that tiny risk.
                </p>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>">
                    <input type="hidden" name="step" value="zeroconf">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>
                    <button type="submit" name="zero_conf" value="1" class="btn" style="width: 100%;">
                        Enable zero-conf
                    </button>
                    <button type="submit" name="zero_conf" value="0" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">
                        Wait for 1 confirmation
                    </button>
                </form>

            <?php elseif ($step === 'lightning'): ?>
                <!-- Screen: Lightning destinations (LNURL + NWC + CLINK noffer) -->
                <?php
                $lnExistingAddress = '';
                $lnExistingNoffers = [];
                // A stored NWC connection prefills as a masked label + keep:<id>
                // ref — the raw URI embeds the wallet secret and never reaches
                // the browser.
                $lnExistingNwcRef = '';
                $lnExistingNwcLabel = '';
                // A stored Strike API key prefills the same way — masked label
                // + keep:<id> ref; the key itself never reaches the browser.
                $lnExistingStrikeRef = '';
                $lnExistingStrikeLabel = '';
                foreach ($renderStoreId ? StoreLnAddresses::listForStore($renderStoreId) : [] as $lnRow) {
                    if ($lnRow['type'] === StoreLnAddresses::TYPE_NOFFER) {
                        $lnExistingNoffers[] = $lnRow['address'];
                    } elseif ($lnRow['type'] === StoreLnAddresses::TYPE_NWC) {
                        if ($lnExistingNwcRef === '') {
                            $lnExistingNwcRef = StoreLnAddresses::KEEP_REF_PREFIX . $lnRow['id'];
                            $lnExistingNwcLabel = StoreLnAddresses::displayValue($lnRow['type'], $lnRow['address']);
                        }
                    } elseif ($lnRow['type'] === StoreLnAddresses::TYPE_STRIKE) {
                        if ($lnExistingStrikeRef === '') {
                            $lnExistingStrikeRef = StoreLnAddresses::KEEP_REF_PREFIX . $lnRow['id'];
                            $lnExistingStrikeLabel = StoreLnAddresses::displayValue($lnRow['type'], $lnRow['address']);
                        }
                    } elseif ($lnExistingAddress === '') {
                        $lnExistingAddress = $lnRow['address'];
                    }
                }
                // Environment gate: the CLINK client needs bignum math (GMP
                // or BCMath), so on a host with neither the noffer field is
                // disabled with the reason instead of accepting a destination
                // that would silently fail at checkout. NWC signs its Nostr
                // requests through the same stack and gets the same gate.
                require_once __DIR__ . '/includes/clink/client.php';
                require_once __DIR__ . '/includes/nwc/client.php';
                $lnNofferEnvError = ClinkClient::environmentError();
                $lnNwcEnvError = NwcClient::environmentError();
                // Unlike the address/noffer fields, a POSTed NWC value is
                // NEVER echoed back into the re-rendered form: the paste
                // embeds the wallet secret, and the invariant "the secret
                // appears in no server response" beats saving the operator a
                // re-paste after a failed save. Only the opaque keep ref of a
                // stored connection round-trips.
                $lnNwcValue = trim((string)($_POST['nwc_keep_ref'] ?? $lnExistingNwcRef));
                $lnNwcShowsSaved = $lnNwcValue !== '' && str_starts_with($lnNwcValue, StoreLnAddresses::KEEP_REF_PREFIX);
                // Same invariant for the Strike API key: a POSTed key is never
                // echoed back into the re-rendered form — only the opaque keep
                // ref of a stored key round-trips.
                $lnStrikeValue = trim((string)($_POST['strike_keep_ref'] ?? $lnExistingStrikeRef));
                $lnStrikeShowsSaved = $lnStrikeValue !== '' && str_starts_with($lnStrikeValue, StoreLnAddresses::KEEP_REF_PREFIX);
                // Strike on-chain checkbox: reflect the POSTed state on a
                // re-render after a failed save, else the stored flag.
                require_once __DIR__ . '/includes/onchain/config.php';
                $lnStrikeOnchainChecked = isset($_POST['lightning_action'])
                    ? (($_POST['strike_onchain'] ?? '') === '1')
                    : ($renderStoreId !== null && OnchainConfig::strikeEnabledForStore($renderStoreId));
                ?>
                <h2 style="margin-bottom: 1rem;">⚡ Lightning payments</h2>
                <p style="margin-bottom: 0.75rem;">
                    Lightning is the fast, low-fee way to get paid &mdash; quick
                    for your customers, cheap for you. 🚀
                </p>
                <p style="margin-bottom: 1.25rem;">
                    We'd really suggest turning it on! BareBits supports several types of lightning wallet backends. When invoices are generated for your customers, BareBits will try each option in sequence until it can successfully generate an invoice. <strong>Only one method is needed for lightning payments to work</strong>, you can use multiple methods if you want.
                </p>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>">
                    <input type="hidden" name="step" value="lightning">
                    <input type="hidden" name="lightning_action" value="save">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <!-- Open on every render (unlike the NWC/noffer sections
                         below): the address is the primary, no-wallet-software
                         path, so it stays visible by default while remaining
                         collapsible. -->
                    <details class="form-group" id="lnurl-section" open>
                        <summary style="cursor: pointer; font-weight: 500; margin-bottom: 0.5rem;">Method 1: LNURL/lightning address eg myname@wallet.com</summary>
                        <input type="text" id="lightning_address" name="lightning_address" aria-label="LNURL/lightning address"
                               style="font-family: monospace; font-size: 0.9rem; margin-bottom: 0.5rem;"
                               value="<?= htmlspecialchars($_POST['lightning_address'] ?? $lnExistingAddress) ?>"
                               placeholder="myname@wallet.com">
                        <div id="strike-address-warning" class="warning" style="display: none; margin-bottom: 0.5rem;">
                            ⚠️ Strike lightning addresses (&hellip;@strike.me) can't be used
                            here &mdash; they don't fully support payment verification
                            (LUD-21), so payments to them could never be confirmed at
                            checkout. Use the
                            <a href="#strike-section" id="strike-address-warning-link" style="color: #63b3ed;">Strike API method below</a>
                            instead.
                        </div>
                        <div id="ln-help-box" style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; font-size: 0.9rem; color: #a0aec0;">
                            <p style="margin-bottom: 0.75rem;">
                                Have a Strike account (or want a free one with instant
                                fiat/USD conversion, available in 100+ countries at
                                <a href="https://strike.me" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">strike.me</a>)?
                                Don't enter your &hellip;@strike.me address here &mdash;
                                Strike addresses don't support LUD-21 payment
                                verification. Use the <strong>Strike API</strong> method
                                below instead.
                            </p>
                            <p style="margin-bottom: 0.75rem;">
                                Don't want to use strike? Want full self-custody? You can
                                use the
                                <a href="https://github.com/BareBits/electrum_clink" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">clink plugin</a>
                                or the built-in NWC (nostr wallet connect) plugin for the
                                <a href="https://electrum.org" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Electrum wallet</a>
                                to have lightning payments delivered directly to your
                                wallet (when online). We also suggest the
                                <a href="https://github.com/BareBits/electrum_liquidity" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Electrum Liquidity Management plugin</a>,
                                just set to automatic mode and you'll always have
                                inbound liquidity once you reach a balance of around
                                $100 worth of BTC. BareBits provides graceful fallback
                                options for if your wallet is offline or doesn't have
                                inbound liquidity.
                            </p>
                            <p style="margin-bottom: 0.75rem;">
                                ⚠️ Using
                                <a href="https://electrum.org" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Electrum</a>
                                with the
                                <a href="https://github.com/BareBits/electrum_clink" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">CLINK plugin</a>
                                is STRONGLY recommended as opposed to other wallets
                                with CLINK support. CLINK payment receipts are
                                temporary when issued, which means they can be lost
                                during small network outages and report invoices as
                                "unpaid". There is no risk to funds from this, but it
                                does mean an order may show unpaid when it was, in
                                fact, paid.
                            </p>
                            <p style="margin: 0;">
                                Alternate option: use a cashu mint. A cashu mint holds
                                onto your funds (no need to worry about managing
                                liquidity or keeping wallet online) and funds are
                                automatically withdrawn to your on-chain wallet when a
                                sufficient amount has accumulated. Just skip this
                                section if you prefer to use a cashu mint.
                            </p>
                        </div>
                    </details>

                    <!-- Collapsed by default (like the NWC/noffer sections
                         below); open only when a key is already saved. Sits
                         right beneath the LNURL section because it's the
                         Strike-account alternative to a @strike.me address —
                         but at payment time it is the FIRST method tried. -->
                    <details class="form-group" id="strike-section"<?= $lnStrikeShowsSaved ? ' open' : '' ?>>
                        <summary style="cursor: pointer; font-weight: 500; margin-bottom: 0.5rem;">Method 2: Strike API</summary>
                        <p style="margin-bottom: 0.5rem; font-size: 0.9rem;">
                            Take Lightning payments straight into your
                            <a href="https://strike.me" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Strike</a>
                            account. Strike lightning addresses can't be used in the
                            LNURL box above, but a Strike API key works fully &mdash;
                            and when one is configured it's the <strong>first</strong>
                            method tried when generating invoices.
                        </p>
                        <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; font-size: 0.9rem; color: #a0aec0; margin-bottom: 0.5rem;">
                            <p style="margin-bottom: 0.5rem;">To create a key:</p>
                            <ol style="margin: 0 0 0.5rem 1.25rem; padding: 0;">
                                <li>Open the
                                    <a href="https://dashboard.strike.me/" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Strike dashboard</a>
                                    (opens in a new tab), log in, and go to the
                                    <strong>API Keys</strong> section.</li>
                                <li>Create a new key with <em>only</em> these three scopes:
                                    <strong>Create invoices</strong>
                                    (<code>partner.invoice.create</code>),
                                    <strong>Generate invoice quotes</strong>
                                    (<code>partner.invoice.quote.generate</code>) and
                                    <strong>Read invoices</strong>
                                    (<code>partner.invoice.read</code>).
                                    Add a fourth scope, <strong>Create receive
                                    requests</strong>
                                    (<code>partner.receive-request.create</code>),
                                    only if you also tick the on-chain checkbox
                                    below.</li>
                                <li>Copy the key and paste it below.</li>
                            </ol>
                            <p style="margin: 0;">
                                With just those scopes the key can create and verify
                                invoices but <strong>cannot spend or withdraw funds</strong>
                                from your account. When you continue, the key is tested
                                with a 1-sat test invoice (it's never paid).
                            </p>
                        </div>
                        <?php if ($lnStrikeShowsSaved): ?>
                            <!-- A key is stored. It never reaches the browser: the
                                 hidden keep ref keeps it on save, pasting a new key
                                 replaces it, and the checkbox removes it. -->
                            <input type="hidden" name="strike_keep_ref" value="<?= htmlspecialchars($lnStrikeValue) ?>">
                            <input type="text" readonly
                                   style="font-family: monospace; font-size: 0.9rem; opacity: 0.7; margin-bottom: 0.5rem;"
                                   value="<?= htmlspecialchars($lnExistingStrikeLabel !== '' ? $lnExistingStrikeLabel : 'Saved Strike API key') ?>">
                            <label style="display:block; font-size: 0.85rem; margin-bottom: 0.5rem;">
                                <input type="checkbox" name="strike_clear" value="1">
                                Remove this saved key
                            </label>
                            <input type="text" id="strike_api_key" name="strike_api_key" aria-label="Strike API key"
                                   autocomplete="off" spellcheck="false"
                                   style="font-family: monospace; font-size: 0.9rem;"
                                   value=""
                                   placeholder="paste a new API key to replace">
                        <?php else: ?>
                            <input type="text" id="strike_api_key" name="strike_api_key" aria-label="Strike API key"
                                   autocomplete="off" spellcheck="false"
                                   style="font-family: monospace; font-size: 0.9rem;"
                                   value=""
                                   placeholder="paste your Strike API key">
                        <?php endif; ?>
                        <label style="display:block; font-size: 0.9rem; margin-top: 0.75rem;">
                            <input type="checkbox" name="strike_onchain" value="1"<?= $lnStrikeOnchainChecked ? ' checked' : '' ?>>
                            Also accept on-chain payments via Strike
                        </label>
                        <p style="margin: 0.35rem 0 0 1.5rem; font-size: 0.8rem; color: #a0aec0;">
                            Each invoice's Bitcoin address is then created in your
                            Strike account (with your on-chain wallet as fallback if
                            Strike is unreachable). The key additionally needs the
                            <strong>Create receive requests</strong> scope
                            (<code>partner.receive-request.create</code>) — it is
                            tested when you continue.
                        </p>
                    </details>

                    <!-- Collapsed by default; open only when the section already
                         shows stored content (the saved-connection label here, a
                         rendered noffer value below — including the POST echo
                         after a failed validation, so the input the error names
                         stays visible). An environment warning alone doesn't
                         force a section open. -->
                    <details class="form-group" id="nwc-section"<?= $lnNwcShowsSaved ? ' open' : '' ?>>
                        <summary style="cursor: pointer; font-weight: 500; margin-bottom: 0.5rem;">Method 3: Nostr Wallet Connect (NWC) (Electrum)</summary>
                        <div class="warning" style="margin-bottom: 0.5rem;">
                            ⚠️ Your wallet must be ONLINE to receive lightning payments
                        </div>
                        <?php if ($lnNwcEnvError !== null): ?>
                            <div class="warning" style="margin-bottom: 0.5rem;">
                                <strong>NWC isn't available on this server yet:</strong>
                                <?= htmlspecialchars($lnNwcEnvError) ?>
                            </div>
                        <?php elseif (($lnNwcNotice = NwcClient::environmentNotice()) !== null): ?>
                            <div class="info" style="margin-bottom: 0.5rem;">
                                <?= htmlspecialchars($lnNwcNotice) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($lnNwcShowsSaved): ?>
                            <!-- A connection is stored. Its secret-bearing URI never
                                 reaches the browser: the hidden keep ref keeps it on
                                 save, pasting a new string replaces it, and the
                                 checkbox removes it. -->
                            <input type="hidden" name="nwc_keep_ref" value="<?= htmlspecialchars($lnNwcValue) ?>">
                            <input type="text" readonly
                                   style="font-family: monospace; font-size: 0.9rem; opacity: 0.7; margin-bottom: 0.5rem;"
                                   value="<?= htmlspecialchars($lnExistingNwcLabel !== '' ? $lnExistingNwcLabel : 'Saved NWC connection') ?>">
                            <label style="display:block; font-size: 0.85rem; margin-bottom: 0.5rem;">
                                <input type="checkbox" name="nwc_clear" value="1">
                                Remove this saved connection
                            </label>
                            <input type="text" id="nwc" name="nwc" aria-label="Nostr Wallet Connect (NWC)"
                                   style="font-family: monospace; font-size: 0.9rem;<?= $lnNwcEnvError !== null ? ' opacity: 0.5;' : '' ?>"
                                   value=""
                                   placeholder="paste a new nostr+walletconnect://&hellip; to replace"
                                   <?= $lnNwcEnvError !== null ? 'disabled' : '' ?>>
                        <?php else: ?>
                            <input type="text" id="nwc" name="nwc" aria-label="Nostr Wallet Connect (NWC)"
                                   style="font-family: monospace; font-size: 0.9rem;<?= $lnNwcEnvError !== null ? ' opacity: 0.5;' : '' ?>"
                                   value=""
                                   placeholder="nostr+walletconnect://&hellip;"
                                   <?= $lnNwcEnvError !== null ? 'disabled' : '' ?>>
                        <?php endif; ?>
                        <p style="margin-top: 0.35rem; font-size: 0.85rem; opacity: 0.75;">
                            Lets BareBits request invoices straight from your own
                            Lightning wallet and confirm payments automatically. Use a
                            <strong>receive-only</strong> connection (make_invoice +
                            lookup_invoice). When you continue, the connection is
                            tested with a 1-sat test invoice (it's never paid).
                        </p>
                    </details>

                    <?php
                    // One input per noffer. A failed validation echoes the
                    // POSTed rows back so the value the error names stays
                    // visible; otherwise the stored entries prefill.
                    $lnNofferPosted = $_POST['noffers'] ?? null;
                    if ($lnNofferPosted !== null && !is_array($lnNofferPosted)) {
                        $lnNofferPosted = [(string)$lnNofferPosted];
                    }
                    $lnNofferValues = [];
                    foreach ($lnNofferPosted ?? $lnExistingNoffers as $lnNofferRow) {
                        $lnNofferRow = trim((string)$lnNofferRow);
                        if ($lnNofferRow !== '') { $lnNofferValues[] = $lnNofferRow; }
                    }
                    ?>
                    <details class="form-group" id="noffer-section"<?= $lnNofferValues !== [] ? ' open' : '' ?>>
                        <summary style="cursor: pointer; font-weight: 500; margin-bottom: 0.5rem;">Method 4: noffer (CLINK) (Electrum)</summary>
                        <div class="warning" style="margin-bottom: 0.5rem;">
                            ⚠️ Your wallet must be ONLINE to receive lightning payments
                        </div>
                        <div class="warning" style="margin-bottom: 0.5rem;">
                            ⚠️ Using
                            <a href="https://electrum.org" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">Electrum</a>
                            with the
                            <a href="https://github.com/BareBits/electrum_clink" target="_blank" rel="noopener noreferrer" style="color: #63b3ed;">CLINK plugin</a>
                            is STRONGLY recommended as opposed to other wallets with
                            CLINK support. CLINK payment receipts are temporary when
                            issued, which means they can be lost during small network
                            outages and report invoices as "unpaid". There is no risk
                            to funds from this, but it does mean an order may show
                            unpaid when it was, in fact, paid.
                        </div>
                        <?php if ($lnNofferEnvError !== null): ?>
                            <div class="warning" style="margin-bottom: 0.5rem;">
                                <strong>noffers aren't available on this server yet:</strong>
                                <?= htmlspecialchars($lnNofferEnvError) ?>
                            </div>
                        <?php elseif (($lnNofferNotice = ClinkClient::environmentNotice()) !== null): ?>
                            <div class="info" style="margin-bottom: 0.5rem;">
                                <?= htmlspecialchars($lnNofferNotice) ?>
                            </div>
                        <?php endif; ?>
                        <div id="noffer-list">
                            <?php foreach (($lnNofferValues === [] ? [''] : $lnNofferValues) as $lnNofferIndex => $lnNofferRow): ?>
                                <input type="text" name="noffers[]" aria-label="noffer"
                                       <?= $lnNofferIndex === 0 ? 'id="noffer" ' : '' ?>style="font-family: monospace; font-size: 0.9rem; margin-bottom: 0.5rem;<?= $lnNofferEnvError !== null ? ' opacity: 0.5;' : '' ?>"
                                       value="<?= htmlspecialchars($lnNofferRow) ?>"
                                       placeholder="noffer1&hellip;"
                                       <?= $lnNofferEnvError !== null ? 'disabled' : '' ?>>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-secondary" id="add-noffer"
                                style="<?= $lnNofferEnvError !== null ? 'opacity: 0.4;' : '' ?>"
                                <?= $lnNofferEnvError !== null ? 'disabled' : '' ?>>
                            + Add another noffer
                        </button>
                        <p style="margin-top: 0.35rem; font-size: 0.85rem; opacity: 0.75;">
                            We suggest creating two noffers on separate relays to
                            keep payments flowing smoothly if one of your relays
                            experiences unexpected downtime
                        </p>
                    </details>

                    <button type="submit" class="btn" style="width: 100%;">Continue</button>
                </form>

                <script>
                (function () {
                    var addBtn = document.getElementById('add-noffer');
                    if (!addBtn) { return; }
                    addBtn.addEventListener('click', function () {
                        var list = document.getElementById('noffer-list');
                        var row = list.lastElementChild.cloneNode(false);
                        row.removeAttribute('id');
                        row.removeAttribute('value');
                        row.value = '';
                        list.appendChild(row);
                        row.focus();
                    });
                })();
                (function () {
                    // Strike lightning addresses don't support LUD-21, so the
                    // save would be refused anyway — warn while typing and
                    // point at the Strike API section instead of letting the
                    // operator find out from the server error.
                    var input = document.getElementById('lightning_address');
                    var warning = document.getElementById('strike-address-warning');
                    if (!input || !warning) { return; }
                    function update() {
                        var isStrike = /@strike\.me$/i.test(input.value.trim());
                        warning.style.display = isStrike ? '' : 'none';
                    }
                    input.addEventListener('input', update);
                    update();
                    var link = document.getElementById('strike-address-warning-link');
                    var strikeSection = document.getElementById('strike-section');
                    if (link && strikeSection) {
                        link.addEventListener('click', function () {
                            strikeSection.open = true;
                        });
                    }
                })();
                </script>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>" style="margin-top: 0.5rem;">
                    <input type="hidden" name="step" value="lightning">
                    <input type="hidden" name="lightning_action" value="skip">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary" style="width: 100%;">Skip for now</button>
                </form>

            <?php elseif ($step === 'swaps'): ?>
                <!-- Screen: submarine swaps -->
                <h2 style="margin-bottom: 1rem;">🌊 Submarine swaps</h2>
                <p style="margin-bottom: 0.75rem;">
                    Want submarine swaps on? We'd recommend it! 👍
                </p>
                <p style="margin-bottom: 0.75rem;">
                    Think of them as a safety net for when Lightning can't get
                    through &mdash; maybe your Lightning provider is down, or your
                    Electrum wallet is offline or short on liquidity.
                </p>
                <p style="margin-bottom: 1.5rem;">
                    When that happens, your customer pays via Lightning plus a
                    small swap fee (about 1%), and the money lands in your
                    on-chain wallet.
                </p>

                <?php
                require_once __DIR__ . '/includes/onchain/wallet.php';
                $swapEnvError = OnchainWallet::environmentError();
                ?>
                <?php if ($swapEnvError !== null): ?>
                    <div class="warning" style="margin-bottom: 1rem;">
                        <strong>Swaps aren't available on this server yet:</strong>
                        <?= htmlspecialchars($swapEnvError) ?>
                    </div>
                <?php elseif (!$renderOnchain['hasXpub']): ?>
                    <div class="warning" style="margin-bottom: 1rem;">
                        Submarine swaps send Bitcoin to your on-chain wallet, so
                        they need an xpub. Go back and add one to turn this on.
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>">
                    <input type="hidden" name="step" value="swaps">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>
                    <button type="submit" name="swaps_enabled" value="1" class="btn" style="width: 100%;"
                            <?= ($renderOnchain['hasXpub'] && $swapEnvError === null) ? '' : 'disabled' ?>>
                        Enable submarine swaps
                    </button>
                    <button type="submit" name="swaps_enabled" value="0" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">
                        No thanks
                    </button>
                </form>

            <?php elseif ($step === 'mints'): ?>
                <!-- Screen: Cashu mints (auto-picked main + backup, or manual) -->
                <h2 style="margin-bottom: 1rem;">🪙 Cashu mints</h2>
                <p style="margin-bottom: 0.75rem;">
                    Want to switch on a Cashu mint? We'd suggest it. 👍
                </p>
                <p style="margin-bottom: 0.75rem;">
                    Think of it as the last fallback for Lightning &mdash; it
                    only steps in when you've got no working LNURL/noffer AND a
                    submarine swap won't work (like tiny payments, since swap
                    providers rarely go under ~$10).
                </p>
                <p style="margin-bottom: 0.75rem;">
                    Any funds that land in your mint get swept to your on-chain
                    or lightning wallet automatically, as soon as the fees make
                    sense.
                </p>
                <p style="margin-bottom: 1.5rem;">
                    ⚠️ One thing to know: mints are custodial &mdash; they could
                    vanish with your funds, so we move your money out the moment
                    we can.
                </p>

                <?php if (!function_exists('gmp_init') && !function_exists('bcadd')): ?>
                    <div class="error" style="margin-bottom: 1rem;">
                        Cashu mints need PHP's GMP or BCMath extension, and this
                        server has neither. Ask your hosting provider to enable
                        <code>php-gmp</code> before switching a mint on.
                    </div>
                <?php elseif (!function_exists('gmp_init')): ?>
                    <div class="warning" style="margin-bottom: 1rem;">
                        Mint payments will work on this server, but the automatic
                        sweep to your on-chain wallet runs over a submarine swap,
                        which needs PHP's GMP extension. Until your hosting
                        provider enables <code>php-gmp</code>, funds will stay at
                        the mint (or drain via your Lightning address if you set
                        one up).
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>" id="mints-form">
                    <input type="hidden" name="step" value="mints">
                    <input type="hidden" name="mints_enabled" value="1">
                    <input type="hidden" name="mint_url" id="mint_url" value="<?= htmlspecialchars($_POST['mint_url'] ?? '') ?>">
                    <input type="hidden" name="backup_mint_url" id="backup_mint_url" value="<?= htmlspecialchars($_POST['backup_mint_url'] ?? '') ?>">
                    <input type="hidden" name="mint_unit" id="mint_unit" value="<?= htmlspecialchars($_POST['mint_unit'] ?? 'sat') ?>">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>

                    <div id="mint-autopick-status" style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; color: #a0aec0; display: flex; align-items: center; gap: 0.75rem;">
                        <span class="spinner-inline"></span>
                        <span>🔍 Searching for well-reviewed mints&hellip; please wait.</span>
                    </div>

                    <div id="mint-autopick-result" style="display: none; margin-bottom: 1rem;">
                        <p style="font-weight: 500; margin-bottom: 0.75rem;">We picked two well-reviewed mints for you</p>
                        <div style="background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px; margin-bottom: 0.5rem;">
                            <p style="font-size: 0.8rem; color: #a0aec0; margin: 0;">Main mint</p>
                            <code id="mint-primary-display" style="word-break: break-all; font-size: 0.85rem;"></code>
                        </div>
                        <div style="background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px;">
                            <p style="font-size: 0.8rem; color: #a0aec0; margin: 0;">Backup mint</p>
                            <code id="mint-backup-display" style="word-break: break-all; font-size: 0.85rem;"></code>
                        </div>
                    </div>

                    <!-- Manual entry. Shown on demand, and automatically when
                         discovery can't produce two usable sat mints. -->
                    <div id="mint-manual" style="display: none; margin-bottom: 1rem;">
                        <div class="form-group">
                            <label for="mint_url_manual">Main mint URL</label>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="url" id="mint_url_manual" placeholder="https://&hellip;" style="flex: 1;">
                                <button type="button" class="btn btn-secondary" onclick="openMintDiscovery('primary')" style="white-space: nowrap;">Browse</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="backup_mint_url_manual">Backup mint URL</label>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="url" id="backup_mint_url_manual" placeholder="https://&hellip;" style="flex: 1;">
                                <button type="button" class="btn btn-secondary" onclick="openMintDiscovery('backup')" style="white-space: nowrap;">Browse</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="mint_unit_manual">Currency unit</label>
                            <select id="mint_unit_manual">
                                <option value="sat">Bitcoin (sats)</option>
                                <option value="usd">USD</option>
                                <option value="eur">EUR</option>
                            </select>
                            <p class="help-text">Both mints must support the unit you pick.</p>
                        </div>
                    </div>

                    <p style="margin-bottom: 1rem;">
                        <a href="#" id="mint-manual-toggle" style="color: #63b3ed; font-size: 0.9rem;">Choose my own mints</a>
                    </p>

                    <button type="submit" class="btn" style="width: 100%;" id="mints-continue-btn" disabled>Continue</button>
                </form>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>" style="margin-top: 0.5rem;">
                    <input type="hidden" name="step" value="mints">
                    <input type="hidden" name="mints_enabled" value="0">
                    <?php if ($mode === 'add_store'): ?>
                        <input type="hidden" name="mode" value="add_store">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary" style="width: 100%;">No thanks, run without mints</button>
                </form>

            <?php elseif ($step === SetupFlow::ADD_STORE_COMPLETE): ?>
                <!-- Screen: add_store hand-off. Only reached when the mints
                     answer generated a wallet seed, which has to be shown once
                     before we return the operator to the admin panel. -->
                <?php
                $createdStoreId = $_SESSION['setup_store_id'] ?? null;
                $createdStore = $createdStoreId ? Config::getStore($createdStoreId) : null;
                unset($_SESSION['setup_store_id'], $_SESSION['setup_store_mode']);
                $adminReturn = Urls::admin()
                    . ($createdStoreId ? '?store_created=' . urlencode($createdStoreId) : '');
                ?>
                <h2 style="margin-bottom: 1rem;">🎉 Your store is ready!</h2>

                <div class="success">
                    <?= htmlspecialchars($createdStore['name'] ?? 'Your store') ?> is all set up and good to go. 🙌
                </div>

                <?php if ($generatedSeed): ?>
                <div class="warning" style="margin-bottom: 1rem;">
                    <strong>🔐 Jot down your recovery phrase</strong>
                    <p style="margin: 0.5rem 0 0; font-size: 0.9rem;">
                        These 12 little words are your lifeline &mdash; they
                        recover any ecash at this store's mints if the server ever
                        disappears.
                    </p>
                    <p style="margin: 0.5rem 0 0; font-size: 0.9rem;">
                        Write them somewhere safe and offline. You can peek at
                        them again in store settings, but remember: whoever has
                        them can spend your funds.
                    </p>
                </div>
                <div class="seed-display"><?= htmlspecialchars($generatedSeed) ?></div>
                <?php unset($_SESSION['setup_generated_seed']); ?>
                <?php endif; ?>

                <a href="<?= htmlspecialchars($adminReturn) ?>" class="btn" style="width: 100%; text-align: center; display: block;">
                    Go to BareBits Admin
                </a>

            <?php elseif ($step === 'cron'): ?>
                <!-- Screen: cron reminder -->
                <?php
                $cronKey = Config::get('cron_key');
                if (!$cronKey) {
                    $cronKey = bin2hex(random_bytes(32));
                    Config::set('cron_key', $cronKey);
                }
                // Same shape the admin Settings page renders, so operators see
                // one canonical line in both places. The key travels in a
                // header rather than the query string so it stays out of
                // access logs. A Windows server host (the desktop package
                // never reaches this screen) gets the Task Scheduler
                // equivalent — a crontab line is unusable there.
                $cronSchedule = SetupFlow::cronScheduleLine(PHP_OS_FAMILY, $cronKey, Urls::cron());
                ?>
                <h2 style="margin-bottom: 1rem;">⏰ Enable cron</h2>
                <p style="margin-bottom: 1.25rem;">
                    Important: we strongly recommend enabling cron. ✅ Without it,
                    background jobs &mdash; like sweeping funds out of a mint
                    &mdash; only run when someone visits your site, instead of
                    regularly on their own.
                </p>

                <p style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #a0aec0;">
                    <?= htmlspecialchars($cronSchedule['intro']) ?>
                </p>
                <pre style="background: rgba(0,0,0,0.3); padding: 0.75rem; border-radius: 6px; font-size: 0.8rem; user-select: all;"><?= htmlspecialchars($cronSchedule['line']) ?></pre>

                <form method="POST" action="<?= htmlspecialchars(setupSelfUrl()) ?>" style="margin-top: 1.5rem;">
                    <input type="hidden" name="step" value="cron">
                    <button type="submit" class="btn" style="width: 100%;">Continue</button>
                </form>

            <?php elseif ($step === 'done'): ?>
                <!-- Screen: complete -->
                <h2 style="margin-bottom: 1rem;">🎉 You're all set!</h2>

                <div class="success">
                    BareBits is ready to accept payments. ✅
                </div>

                <?php if ($isDesktop): ?>
                <p style="margin-bottom: 1.25rem; font-size: 0.9rem; color: #a0aec0;">
                    ⏰ Background jobs run automatically while the desktop
                    launcher is open &mdash; nothing to set up.
                </p>
                <?php endif; ?>

                <?php
                // Warn when the operator skipped every payment rail — the
                // store exists but no invoice created against it could be
                // paid. Mirrors the capability test Invoice::create makes.
                //
                // Only claim this when we actually know which store was just
                // built. Reaching this screen on a fresh session (browser
                // restarted, cookie expired, link opened in another tab) leaves
                // no store id, and a store we cannot inspect must not be
                // reported as unpayable — that reads as "your setup failed"
                // when nothing is wrong.
                $doneStoreId = $_SESSION['setup_store_id'] ?? null;
                $doneOnchain = SetupFlow::onchainState($doneStoreId);
                $doneStore = $doneStoreId ? Config::getStore($doneStoreId) : null;
                $doneHasLn = $doneStoreId ? (StoreLnAddresses::addressesForStore($doneStoreId) !== []) : false;
                $doneHasMint = !empty($doneStore['mint_url']) && !empty($doneStore['seed_phrase']);
                $doneHasSwaps = $doneStoreId ? SwapsConfig::isEnabledForStore($doneStoreId) : false;
                $doneHasAnyRail = $doneOnchain['configured'] || $doneHasLn || $doneHasMint || $doneHasSwaps;
                $doneKnowsStore = $doneStore !== null;
                ?>
                <?php if ($doneKnowsStore && !$doneHasAnyRail): ?>
                <div class="warning" style="margin-bottom: 1.5rem;">
                    <strong>⚠️ No payment method yet</strong>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem;">
                        Your store can't take payments just yet. Add an on-chain
                        wallet, a Lightning address, or a Cashu mint in store
                        settings.
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($generatedSeed): ?>
                <div class="warning" style="margin-bottom: 1rem;">
                    <strong>🔐 Save your recovery phrase</strong>
                    <p style="margin: 0.5rem 0 0; font-size: 0.9rem;">
                        These 12 words can recover any ecash held at your mints
                        if this server is ever lost.
                    </p>
                    <p style="margin: 0.5rem 0 0; font-size: 0.9rem;">
                        Write them down somewhere safe and offline &mdash; we can
                        show them again in store settings, but anyone who has them
                        can spend your funds.
                    </p>
                </div>
                <div class="seed-display"><?= htmlspecialchars($generatedSeed) ?></div>
                <?php unset($_SESSION['setup_generated_seed']); ?>
                <?php endif; ?>

                <?php
                // Managed installs with a provisioned return URL hand the
                // operator straight back to the orchestrator (the WordPress
                // plugin's onboarding page), which collects credentials and
                // wires the shop itself — the manual connect-your-e-commerce
                // instructions below would only mislead. target="_top" breaks
                // out of the orchestrator's embedding iframe; the URL is pure
                // provisioned data and nothing here ever requests it.
                $managedReturn = ManagedInstall::isManaged() ? ManagedInstall::returnUrl() : '';
                ?>
                <?php if ($managedReturn !== ''): ?>

                <a id="managed-return-link" href="<?= htmlspecialchars($managedReturn) ?>" target="_top"
                   class="btn" style="width: 100%; text-align: center; display: block;">
                    Finish &mdash; return to WordPress
                </a>
                <p style="color: #a0aec0; font-size: 0.85rem; margin-top: 0.75rem; text-align: center;">
                    Your shop finishes connecting automatically on the next screen.
                </p>
                <div id="managed-return-waiting" class="warning" style="display: none; margin-top: 0.75rem;">
                    WordPress is briefly updating itself (maintenance mode) &mdash;
                    continuing automatically as soon as it's back, usually under a
                    minute. Clicking the button again goes there right away.
                </div>
                <script>
                // WordPress auto-updates (wp-cron: core/plugin/translation
                // updates) put the WHOLE WordPress site behind its
                // "Briefly unavailable for scheduled maintenance" screen —
                // every wp URL answers 503 until the update finishes. This
                // server is not WordPress, so THIS screen keeps working;
                // navigating to the return URL at that exact moment would
                // dump the merchant on the maintenance screen mid-setup,
                // which reads as "the install broke". WordPress checks its
                // .maintenance flag before any plugin loads, so the plugin
                // cannot intercept it over there — the handoff has to wait
                // it out from here: probe the WordPress origin first and
                // only navigate once it answers. 503 is the ONLY status
                // that waits; anything else (including errors a broken
                // probe might fabricate) falls through to plain navigation
                // — this guard may delay the merchant, never trap them.
                // Without JavaScript the link works exactly as before.
                (function () {
                    const link = document.getElementById('managed-return-link');
                    const waitingNote = document.getElementById('managed-return-waiting');
                    // The return URL minus its query: admin-post.php with no
                    // action is a cheap no-op that still answers 503 while
                    // WordPress is in maintenance mode. Probing the full
                    // return URL would run the credential collection once
                    // per probe for nothing.
                    const probeUrl = link.href.split('?')[0];
                    let waiting = false;
                    const go = function () { window.top.location.href = link.href; };
                    const probe = function () {
                        fetch(probeUrl, { credentials: 'same-origin', cache: 'no-store' })
                            .then(function (res) {
                                if (res.status === 503) {
                                    waitingNote.style.display = 'block';
                                    setTimeout(probe, 5000);
                                } else {
                                    go();
                                }
                            })
                            .catch(go);
                    };
                    link.addEventListener('click', function (e) {
                        // A second click while waiting is the manual
                        // override: let the browser follow the link.
                        if (waiting) {
                            return;
                        }
                        e.preventDefault();
                        waiting = true;
                        probe();
                    });
                })();
                </script>

                <?php else: ?>

                <?php
                $baseUrl = Urls::siteBase();
                ?>

                <!-- Server URL (already detected in Step 1) -->
                <?php
                $serverUrl = Urls::server();
                $urlMode = Config::getUrlMode();
                $urlModeLabel = Urls::urlModeLabel($urlMode);
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
                    // The real file, not the pretty /api-keys/authorize rewrite:
                    // rewrite-hostile hosts 404 the extension-less spelling, and
                    // authorize.php executes anywhere this very page is being served.
                    $pairingUrl = $serverUrl . '/api-keys/authorize.php?' . $pairingParams;
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
                            <?= $urlMode ?>: OK (detected in Step 1)
                        </div>
                    </div>
                </details>

                <a href="<?= Urls::admin() ?>" class="btn" style="width: 100%; text-align: center; display: block;">
                    Go to BareBits Admin
                </a>

                <?php endif; // managed return vs. manual connect ?>

                <?php
                // Clear temporary session data.
                unset($_SESSION['setup_store_id'], $_SESSION['setup_store_mode'], $_SESSION['setup_generated_seed']);
                ?>
            <?php endif; ?>

            <?php if ($backUrl !== null): ?>
                <p style="margin-top: 1.25rem; text-align: center;">
                    <a href="<?= htmlspecialchars($backUrl) ?>" style="color: #a0aec0; font-size: 0.9rem;">Back</a>
                </p>
            <?php endif; ?>
        </div>
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
    // URLs returned by ?action=get_suggested_mints — these get the
    // "Suggested by BareBits" badge and float to the top of the list.
    var suggestedMintUrls = [];
    // Mint URL -> ISO 3166-1 alpha-2 country code (uppercase). Populated
    // lazily as cards render; null means "lookup failed / pending".
    var mintCountryCache = {};

    var FLAG_BASE = <?= json_encode(Urls::assets('img/flags/')) ?>;
    var SETUP_URL = <?= json_encode(setupSelfUrl()) ?>;

    function normalizeMintUrl(u) {
        return String(u || '').replace(/\/+$/, '');
    }

    function openMintDiscovery(target) {
        // Remember which manual field the "Select" buttons should fill.
        mintDiscoveryTarget = (target === 'backup') ? 'backup' : 'primary';
        document.getElementById('mint-discovery-modal').style.display = 'flex';
        // Reset disclaimer checkbox state when opening
        var checkbox = document.getElementById('mint-disclaimer-checkbox');
        if (checkbox) {
            checkbox.checked = false;
            disclaimerAcknowledged = false;
        }
        // Setup hover listeners for disabled buttons
        setupDisabledButtonHover();
        // Pull the suggested-mints list first (best effort), then start
        // Nostr discovery. The two run in parallel; sort happens on each
        // render so order stabilizes as info trickles in.
        fetchSuggestedMints();
        startMintDiscovery();
    }

    function fetchSuggestedMints() {
        return fetch(SETUP_URL + '?action=get_suggested_mints', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { mints: [] }; })
            .then(function (data) {
                var urls = Array.isArray(data && data.mints) ? data.mints : [];
                suggestedMintUrls = urls.map(normalizeMintUrl);
                // For any suggested URL not in the Nostr-discovered set, fetch
                // its /v1/info directly so it can render with full metadata.
                suggestedMintUrls.forEach(function (url) {
                    var present = discoveredMints.some(function (m) { return normalizeMintUrl(m.url) === url; });
                    if (present) return;
                    fetch(url + '/v1/info', { credentials: 'omit' })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (info) {
                            var existing = discoveredMints.findIndex(function (m) { return normalizeMintUrl(m.url) === url; });
                            var entry = {
                                url: url,
                                info: info,
                                error: !info,
                                averageRating: null,
                                reviewsCount: 0,
                            };
                            if (existing >= 0) {
                                discoveredMints[existing] = entry;
                            } else {
                                discoveredMints.push(entry);
                            }
                            renderMintList();
                        })
                        .catch(function () {
                            // Even on failure, surface the suggested mint so
                            // the operator sees it. They can still pick it.
                            var existing = discoveredMints.findIndex(function (m) { return normalizeMintUrl(m.url) === url; });
                            if (existing < 0) {
                                discoveredMints.push({
                                    url: url,
                                    info: null,
                                    error: true,
                                    averageRating: null,
                                    reviewsCount: 0,
                                });
                                renderMintList();
                            }
                        });
                });
                renderMintList();
            })
            .catch(function () {
                // No suggested-mints config or unreachable — fall back to
                // plain discovery, which is the documented "no suggestions"
                // behavior.
                suggestedMintUrls = [];
            });
    }

    function isSuggested(mintUrl) {
        return suggestedMintUrls.indexOf(normalizeMintUrl(mintUrl)) !== -1;
    }

    // Debounced batch country lookup: collect pending URLs for one tick,
    // then issue a single ?action=mint_country_batch request so the server
    // builds its CSV index once per render rather than once per card.
    var countryFetchQueue = [];
    var countryFetchTimer = null;

    function fetchCountryForCard(url, cardEl) {
        var normalized = normalizeMintUrl(url);
        if (mintCountryCache[normalized] !== undefined) {
            applyCountryToCard(cardEl, mintCountryCache[normalized]);
            return;
        }
        countryFetchQueue.push({ url: url, cardEl: cardEl });
        if (countryFetchTimer) return;
        countryFetchTimer = setTimeout(flushCountryFetchQueue, 50);
    }

    function flushCountryFetchQueue() {
        countryFetchTimer = null;
        if (!countryFetchQueue.length) return;
        var pending = countryFetchQueue.splice(0, countryFetchQueue.length);
        // Dedup by URL while preserving the card -> URL mapping so we apply
        // results to every card that asked.
        var uniqueUrls = [];
        var seen = {};
        pending.forEach(function (p) {
            var n = normalizeMintUrl(p.url);
            if (!seen[n]) { seen[n] = true; uniqueUrls.push(p.url); }
        });
        var qs = uniqueUrls.map(encodeURIComponent).join(',');
        fetch(SETUP_URL + '?action=mint_country_batch&urls=' + qs, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { countries: {} }; })
            .then(function (data) {
                var byUrl = (data && data.countries) || {};
                pending.forEach(function (p) {
                    var raw = byUrl[p.url];
                    var cc = raw ? String(raw).toUpperCase() : null;
                    mintCountryCache[normalizeMintUrl(p.url)] = cc;
                    applyCountryToCard(p.cardEl, cc);
                });
            })
            .catch(function () {
                pending.forEach(function (p) {
                    mintCountryCache[normalizeMintUrl(p.url)] = null;
                });
            });
    }

    function applyCountryToCard(cardEl, cc) {
        if (!cardEl || !cc) return;
        var holder = cardEl.querySelector('.mint-country-slot');
        if (!holder) return;
        var safe = cc.toLowerCase().replace(/[^a-z]/g, '');
        if (safe.length !== 2) return;
        holder.innerHTML = '<img src="' + FLAG_BASE + safe + '.svg" alt="' +
            cc + '" class="mint-flag" style="width: 16px; height: 12px; vertical-align: middle; border-radius: 2px; margin-right: 0.25rem; box-shadow: 0 0 0 1px rgba(0,0,0,0.2);"> ' +
            '<span style="font-size: 0.75rem; color: #a0aec0; letter-spacing: 0.5px;">' + cc + '</span>';
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

    // Escape a value for use inside an HTML attribute. textContent->innerHTML
    // does not escape " or ', so a Nostr-published mint URL containing " could
    // otherwise break out of the attribute and inject markup.
    function escapeAttr(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
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

        // Pin suggested mints (in the order returned by the server) to the
        // top, regardless of review counts. Everything else keeps its
        // existing review-count / rating sort applied earlier.
        var pinned = [];
        var rest = [];
        filtered.forEach(function (m) {
            if (isSuggested(m.url)) pinned.push(m); else rest.push(m);
        });
        pinned.sort(function (a, b) {
            return suggestedMintUrls.indexOf(normalizeMintUrl(a.url)) -
                   suggestedMintUrls.indexOf(normalizeMintUrl(b.url));
        });
        var ordered = pinned.concat(rest);

        if (ordered.length === 0) {
            listEl.innerHTML = '<p style="color: #a0aec0; text-align: center; padding: 2rem;">No mints found matching your criteria</p>';
            return;
        }

        var html = ordered.map(function(m) {
            var name = (m.info && m.info.name) ? m.info.name : 'Unknown Mint';
            var isOnline = !m.error && m.info;
            var units = getUnitsFromInfo(m.info);
            var suggested = isSuggested(m.url);
            var border = suggested
                ? '1px solid rgba(247, 147, 26, 0.5)'
                : '1px solid rgba(255,255,255,0.1)';
            var bg = suggested ? 'rgba(247, 147, 26, 0.08)' : 'rgba(0,0,0,0.2)';
            var badge = suggested
                ? '<span style="display: inline-block; background: rgba(247, 147, 26, 0.2); color: #f7931a; ' +
                  'border: 1px solid rgba(247, 147, 26, 0.4); padding: 0.15rem 0.45rem; border-radius: 4px; ' +
                  'font-size: 0.7rem; font-weight: 600; margin-right: 0.4rem;">\u2605 Suggested by BareBits</span>'
                : '';
            var countrySlot = '<span class="mint-country-slot" data-mint-url="' + escapeAttr(m.url) + '"></span>';

            return '<div class="mint-discovery-card" data-mint-url="' + escapeAttr(m.url) + '" style="background: ' + bg + '; border: ' + border + '; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">' +
                '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; gap: 0.5rem;">' +
                    '<div style="font-size: 0.9rem;">' + renderStars(m.averageRating) +
                        ' <span style="color: #a0aec0; font-size: 0.8rem;">(' + (m.reviewsCount || 0) + ' reviews)</span></div>' +
                    '<span style="font-size: 0.8rem; color: ' + (isOnline ? '#48bb78' : '#e53e3e') + ';">' +
                        (isOnline ? '\u25CF Online' : '\u25CB Offline') + '</span>' +
                '</div>' +
                '<div style="margin-bottom: 0.35rem;">' + badge + countrySlot + '</div>' +
                '<h4 style="margin: 0 0 0.25rem 0; font-size: 1rem;">' + escapeHtml(name) + '</h4>' +
                '<p style="font-size: 0.8rem; color: #a0aec0; margin: 0 0 0.5rem 0; word-break: break-all;">' + escapeHtml(m.url) + '</p>' +
                '<div style="font-size: 0.8rem; color: #a0aec0; margin-bottom: 0.75rem;">' +
                    (units.length > 0 ? units.map(function(u) { return u.toUpperCase(); }).join(' \u2022 ') : 'Unknown units') +
                '</div>' +
                '<button type="button" class="btn select-discovered-mint" data-mint-url="' + escapeAttr(m.url) + '" style="width: 100%;">Select</button>' +
            '</div>';
        }).join('');

        listEl.innerHTML = html;
        // Kick off per-card country lookups. Each request is fast (binary
        // search over a static index); browsers happily run them in parallel.
        listEl.querySelectorAll('.mint-discovery-card').forEach(function (card) {
            var url = card.getAttribute('data-mint-url');
            if (url) fetchCountryForCard(url, card);
        });
        listEl.querySelectorAll('button.select-discovered-mint').forEach(function(btn) {
            btn.addEventListener('click', function() {
                selectDiscoveredMint(btn.dataset.mintUrl);
            });
        });

        var statusEl = document.getElementById('mint-discovery-status');
        statusEl.textContent = 'Showing ' + filtered.length + ' of ' + discoveredMints.length + ' mints';

        // Update button states based on disclaimer checkbox
        updateSelectButtons();
    }


    // Which field the discovery modal is filling — set by the Browse button
    // that opened it. Defaults to the main mint.
    var mintDiscoveryTarget = 'primary';

    function selectDiscoveredMint(url) {
        var id = mintDiscoveryTarget === 'backup' ? 'backup_mint_url_manual' : 'mint_url_manual';
        var el = document.getElementById(id);
        if (el) {
            el.value = url;
            el.dispatchEvent(new Event('input'));
        }
        closeMintDiscovery();
    }

    // ---- Cashu mint auto-pick (mints screen) -------------------------------
    //
    // Runs the same Nostr discovery the Browse modal uses and picks the two
    // best-reviewed mints that advertise 'sat', preferring the BareBits
    // suggested list (which the modal also pins to the top). Discovery is
    // best-effort: relays can be slow, blocked, or return too few usable
    // mints, so anything short of two results falls back to manual entry
    // rather than leaving the operator on a dead screen.
    (function () {
        var form = document.getElementById('mints-form');
        if (!form) return;

        var statusEl = document.getElementById('mint-autopick-status');
        var resultEl = document.getElementById('mint-autopick-result');
        var manualEl = document.getElementById('mint-manual');
        var manualToggle = document.getElementById('mint-manual-toggle');
        var continueBtn = document.getElementById('mints-continue-btn');
        var primaryField = document.getElementById('mint_url');
        var backupField = document.getElementById('backup_mint_url');
        var unitField = document.getElementById('mint_unit');
        var primaryManual = document.getElementById('mint_url_manual');
        var backupManual = document.getElementById('backup_mint_url_manual');
        var unitManual = document.getElementById('mint_unit_manual');
        var manualShown = false;

        function normalize(u) {
            return String(u || '').replace(/\/+$/, '');
        }

        function showManual(force) {
            manualShown = true;
            manualEl.style.display = 'block';
            manualToggle.style.display = 'none';
            if (force) { resultEl.style.display = 'none'; }
            syncFromManual();
        }

        // The hidden fields are what actually POST; the visible manual inputs
        // mirror into them so both paths submit through one code path.
        function syncFromManual() {
            primaryField.value = normalize(primaryManual.value.trim());
            backupField.value = normalize(backupManual.value.trim());
            unitField.value = unitManual.value;
            continueBtn.disabled = !(primaryField.value && backupField.value);
        }

        manualToggle.addEventListener('click', function (e) {
            e.preventDefault();
            // Seed the manual inputs with whatever was auto-picked so the
            // operator edits rather than retypes.
            if (primaryField.value && !primaryManual.value) { primaryManual.value = primaryField.value; }
            if (backupField.value && !backupManual.value) { backupManual.value = backupField.value; }
            showManual(true);
        });
        primaryManual.addEventListener('input', syncFromManual);
        backupManual.addEventListener('input', syncFromManual);
        unitManual.addEventListener('change', syncFromManual);

        function applyAutoPick(primary, backup) {
            primaryField.value = primary;
            backupField.value = backup;
            unitField.value = 'sat';
            document.getElementById('mint-primary-display').textContent = primary;
            document.getElementById('mint-backup-display').textContent = backup;
            resultEl.style.display = 'block';
            statusEl.style.display = 'none';
            continueBtn.disabled = false;
        }

        function failToManual(message) {
            statusEl.textContent = message;
            showManual(true);
        }

        function supportsSat(mint) {
            var units = getUnitsFromInfo(mint.info);
            return units.indexOf('sat') !== -1;
        }

        function rank(mints) {
            // Suggested mints first (in server order), then by review count and
            // rating — the same ordering the Browse modal presents, so the
            // auto-pick is never surprising relative to the manual list.
            var pinned = [];
            var rest = [];
            mints.forEach(function (m) {
                if (isSuggested(m.url)) { pinned.push(m); } else { rest.push(m); }
            });
            pinned.sort(function (a, b) {
                return suggestedMintUrls.indexOf(normalize(a.url)) - suggestedMintUrls.indexOf(normalize(b.url));
            });
            rest.sort(function (a, b) {
                var countDiff = (b.reviewsCount || 0) - (a.reviewsCount || 0);
                if (countDiff !== 0) return countDiff;
                return (b.averageRating || 0) - (a.averageRating || 0);
            });
            return pinned.concat(rest);
        }

        function finish() {
            if (manualShown) return;
            var usable = rank(discoveredMints.filter(function (m) {
                return !m.error && m.info && supportsSat(m);
            }));
            // Dedup by normalized URL — the suggested-list fetch and the Nostr
            // stream can surface the same mint twice.
            var seen = {};
            var unique = [];
            usable.forEach(function (m) {
                var n = normalize(m.url);
                if (!n || seen[n]) return;
                seen[n] = true;
                unique.push(n);
            });
            if (unique.length < 2) {
                failToManual(
                    'We couldn\'t reach the mint directory just now. You can enter mint URLs '
                    + 'yourself, or skip and add them later in store settings.'
                );
                return;
            }
            applyAutoPick(unique[0], unique[1]);
        }

        if (typeof MintDiscovery === 'undefined') {
            failToManual('The mint list didn\'t load. Enter mint URLs manually below.');
            return;
        }

        fetchSuggestedMints();
        var instance = MintDiscovery.create({ httpTimeout: 8000, nostrTimeout: 15000 });
        var settled = false;
        function settle() {
            if (settled) return;
            settled = true;
            finish();
        }
        // Belt and braces: discoverStreaming's onComplete does not fire if a
        // relay hangs past its own timeout, and an operator staring at
        // "Finding well-reviewed mints…" forever is worse than manual entry.
        var guard = setTimeout(settle, 20000);
        instance.discoverStreaming({
            onMint: function (mint) {
                var i = discoveredMints.findIndex(function (m) { return m.url === mint.url; });
                if (i >= 0) { discoveredMints[i] = mint; } else { discoveredMints.push(mint); }
                statusEl.textContent = 'Found ' + discoveredMints.length + ' mints…';
            },
            onComplete: function (mints) {
                if (Array.isArray(mints) && mints.length) { discoveredMints = mints; }
                clearTimeout(guard);
                settle();
            }
        }).catch(function () {
            clearTimeout(guard);
            settle();
        });
    })();
    </script>
</body>
</html>
