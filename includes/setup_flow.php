<?php
/**
 * Screen sequencing for the onboarding wizard (setup.php).
 *
 * Screens are identified by slug rather than by number, so the sequence reads
 * in execution order and inserting one never requires a renumber. This lives
 * outside setup.php so the ordering rules — which screens appear in which
 * mode, what "next" and "back" resolve to — can be tested without rendering a
 * page.
 */

require_once __DIR__ . '/database.php';

final class SetupFlow {
    /**
     * Every screen the wizard knows, in canonical order.
     *
     * zeroconf deliberately sits AFTER lightning: the confirmation policy
     * applies to every on-chain receive source, and the last of those — the
     * "accept on-chain payments via Strike" option — is only answered on the
     * lightning screen. Asking earlier would skip the question entirely for a
     * Strike-only store (no xpub/static address, so the screen dropped out
     * before the operator could create the rail it times).
     */
    public const STEPS = [
        'terms', 'security', 'password', 'store', 'onchain', 'lightning',
        'zeroconf', 'swaps', 'mints', 'cron', 'done',
    ];

    /** The screens add_store mode walks before returning to admin. */
    public const ADD_STORE_STEPS = [
        'store', 'onchain', 'lightning', 'zeroconf', 'swaps', 'mints',
    ];

    /**
     * Terminal screen for add_store, shown only when the mints answer minted a
     * fresh wallet seed. It is deliberately outside ADD_STORE_STEPS: it is a
     * hand-off panel, not a question, and counting it would make the progress
     * indicator jump for the operators who never see it. Its whole reason to
     * exist is that a silently generated seed must be displayed once.
     */
    public const ADD_STORE_COMPLETE = 'store_created';

    /**
     * Screens that must never be a "Back" target: the store already exists by
     * the time any of them could be reached, and re-submitting the password
     * screen trips its "an admin already exists" guard.
     */
    public const NO_BACK_TARGET = ['security', 'password'];

    /**
     * Screens that render after setup_complete has been set, and therefore
     * have to survive setup.php's redirect-if-set-up guard.
     */
    public const POST_COMPLETION = ['cron', 'done'];

    public static function isKnownStep(string $step): bool {
        return in_array($step, self::STEPS, true) || $step === self::ADD_STORE_COMPLETE;
    }

    /** The first screen for a given mode. */
    public static function firstStep(string $mode): string {
        // Terms of service is a first-run-only gate: add_store belongs to an
        // operator who already accepted them when they set the instance up.
        return $mode === 'add_store' ? 'store' : 'terms';
    }

    /**
     * The screens in display order for a given context.
     *
     * $includeZeroConf is false when the store has no on-chain receive source
     * at all (neither an xpub/static destination nor Strike on-chain minting
     * — see zeroConfApplicable()): the zero-conf question has nothing to
     * apply to, so it is dropped from the sequence entirely, which also keeps
     * the "Step X of Y" counter honest rather than advertising a screen that
     * will never render.
     *
     * $includeSecurity is false when the data directory is outside the web
     * root (and the PHP requirements all pass): the screen exists to prove
     * the database can't be fetched over HTTP, and with the directory outside
     * the web root that exposure is impossible — showing the warning anyway
     * only confuses the operator. Callers keep it true whenever a requirement
     * is missing, because the screen is also where that blocking error lives.
     *
     * $isDesktop drops the cron screen: the Windows desktop package's
     * launcher already ticks cron-runner.php on a timer (see windows/), so
     * there is nothing for the operator to set up — and the crontab line the
     * screen shows is meaningless on a system without cron.
     *
     * $externalCron drops the cron screen for the same reason on installs
     * provisioned by an external orchestrator (e.g. the GPL WordPress
     * companion plugin, which pings cron.php from WP-cron): the installer
     * declared at deploy time that something else already ticks the cron
     * endpoint, so there is nothing for the operator to set up. See
     * externalCronConfigured() for how the declaration is made (managed
     * installs imply it — setup.php folds ManagedInstall::isManaged() in).
     *
     * $passwordPreseeded drops the password screen: the orchestrator
     * provisioned the admin account up front (CASHUPAY_ADMIN_PASSWORD_HASH,
     * seeded by ManagedInstall::seedAdminIfProvisioned), so there is no
     * credential left for the wizard to collect. Decided statically from the
     * deployment config so the step counter stays stable across the run.
     *
     * @return string[]
     */
    public static function stepSequence(
        string $mode, bool $includeZeroConf,
        bool $includeSecurity = true, bool $isDesktop = false,
        bool $externalCron = false, bool $passwordPreseeded = false
    ): array {
        if ($mode === 'add_store') {
            $steps = self::ADD_STORE_STEPS;
        } else {
            $steps = self::STEPS;
            if (!$includeSecurity) {
                $steps = array_values(array_diff($steps, ['security']));
            }
            if ($isDesktop || $externalCron) {
                $steps = array_values(array_diff($steps, ['cron']));
            }
            if ($passwordPreseeded) {
                $steps = array_values(array_diff($steps, ['password']));
            }
        }
        if (!$includeZeroConf) {
            $steps = array_values(array_diff($steps, ['zeroconf']));
        }
        return array_values($steps);
    }

    /**
     * PHP requirements the wizard's security screen reports, as
     * name => passed. Lives here rather than inline in the render so the
     * "can the security screen be skipped?" decision and the screen itself
     * can never disagree about what was checked.
     *
     * GMP-or-BCMath deliberately uses extension_loaded (matching the screen's
     * original check): a hardened host that disables the functions but keeps
     * the extension loaded is handled by the softer per-feature gates later
     * in the wizard, not blocked here.
     *
     * @return string[] Names of the requirements that FAILED (empty = all ok).
     */
    public static function missingRequirements(): array {
        $checks = [
            'PHP ' . PHP_VERSION => version_compare(PHP_VERSION, '8.0.0', '>='),
            'cURL extension' => extension_loaded('curl'),
            'JSON extension' => extension_loaded('json'),
            'PDO SQLite' => extension_loaded('pdo_sqlite'),
            'GMP or BCMath' => extension_loaded('gmp') || extension_loaded('bcmath'),
        ];
        $failed = [];
        foreach ($checks as $name => $passed) {
            if (!$passed) {
                $failed[] = $name;
            }
        }
        return $failed;
    }

    /**
     * The instruction the cron screen shows for wiring up the scheduler,
     * keyed on the host OS. Unix hosts get the classic crontab line; a
     * Windows server (IIS, XAMPP — not the desktop package, which never
     * shows the screen) has no cron, so it gets the Task Scheduler
     * equivalent instead. curl.exe ships with Windows 10 1803+; the inner
     * quotes are backslash-escaped per schtasks' /TR quoting rules, and
     * there is no > /dev/null because /TR runs the command directly, not
     * through a shell.
     *
     * @return array{intro: string, line: string}
     */
    public static function cronScheduleLine(
        string $osFamily, string $cronKey, string $cronUrl
    ): array {
        if ($osFamily === 'Windows') {
            return [
                'intro' => 'This server runs on Windows, which has no cron — '
                    . 'create a Task Scheduler entry instead (run in a Command Prompt):',
                'line' => 'schtasks /Create /F /SC MINUTE /MO 1 /TN "CashuPayServer cron" '
                    . '/TR "curl.exe -fsS -H \"X-CRON-KEY: ' . $cronKey . '\" ' . $cronUrl . '"',
            ];
        }
        return [
            'intro' => "Add this line to your crontab (or your host's cron panel):",
            'line' => '* * * * * curl -fsS -H \'X-CRON-KEY: ' . $cronKey . '\' '
                . $cronUrl . ' > /dev/null',
        ];
    }

    /**
     * Next screen after $current, or null when $current is the last one (or
     * isn't in this sequence at all).
     *
     * @param string[] $steps
     */
    public static function nextStep(string $current, array $steps): ?string {
        $i = array_search($current, $steps, true);
        if ($i === false || $i + 1 >= count($steps)) {
            return null;
        }
        return $steps[$i + 1];
    }

    /**
     * Previous screen before $current, or null when $current is the first (or
     * isn't in this sequence at all).
     *
     * @param string[] $steps
     */
    public static function prevStep(string $current, array $steps): ?string {
        $i = array_search($current, $steps, true);
        if ($i === false || $i < 1) {
            return null;
        }
        return $steps[$i - 1];
    }

    /**
     * The screen a "Back" link on $current should point at, or null when there
     * is no safe target.
     *
     * @param string[] $steps
     */
    public static function backStep(string $current, array $steps): ?string {
        if (in_array($current, self::NO_BACK_TARGET, true)
            || in_array($current, self::POST_COMPLETION, true)
            || $current === self::ADD_STORE_COMPLETE) {
            return null;
        }
        $prev = self::prevStep($current, $steps);
        return in_array($prev, self::NO_BACK_TARGET, true) ? null : $prev;
    }

    /**
     * Has an external orchestrator declared that it drives cron.php, making
     * the wizard's crontab screen pointless? Declared at deploy time — the
     * same way CASHUPAY_DATA_DIR is — via a `CASHUPAY_EXTERNAL_CRON` constant
     * in user_config.php (the GPL WordPress companion plugin's installer
     * writes one) or the environment variable of the same name. Mirrors the
     * Desktop::isWindowsDesktop() convention: constant wins, env accepted,
     * "0" means off.
     */
    public static function externalCronConfigured(): bool {
        if (defined('CASHUPAY_EXTERNAL_CRON')) {
            return (bool)CASHUPAY_EXTERNAL_CRON;
        }
        $env = getenv('CASHUPAY_EXTERNAL_CRON');
        return $env !== false && $env !== '' && $env !== '0';
    }

    /**
     * Settle the store's auto-cashout (threshold-melt) configuration once
     * every rail answer is in. This cannot be decided on the Lightning screen
     * alone: whether the mint balance is swept over Lightning or via a
     * submarine swap depends on the swaps and mints answers that come after.
     *
     * The rules, in priority order:
     *   1. Any Lightning destination (LNURL address, NWC connection, or CLINK
     *      noffer) → sweep over Lightning. Cheapest and fastest, so it wins
     *      when available.
     *   2. Otherwise, a mint plus swaps plus an xpub → sweep via submarine
     *      swap to the on-chain wallet. This is the case the mints screen
     *      promises: "funds are automatically withdrawn to your on-chain
     *      wallet when a sufficient amount has accumulated".
     *   3. Otherwise there is nowhere to sweep to, so auto-cashout stays off.
     *
     * Writes go through Database::update because the auto_melt_* columns are
     * intentionally outside Config::updateStore's allowlist — the same path
     * admin.php's save_auto_melt handler uses.
     *
     * @return 'lightning'|'swap'|'off' What was configured, for logging/tests.
     */
    public static function resolveAutoCashout(string $storeId): string {
        require_once __DIR__ . '/store_ln_addresses.php';
        require_once __DIR__ . '/swap/config.php';
        require_once __DIR__ . '/swap/auto_melt.php';

        $store = Database::fetchOne(
            "SELECT id, mint_url, seed_phrase FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$store) {
            return 'off';
        }

        $hasLightning = StoreLnAddresses::addressesForStore($storeId) !== [];
        $hasMint = !empty($store['mint_url']) && !empty($store['seed_phrase']);
        // isEnabledForStore already requires an xpub, and SwapAutoMelt::modeForStore
        // re-checks that the address mode is xpub before it will pick 'swap'.
        $canSwap = $hasMint && SwapsConfig::isEnabledForStore($storeId)
            && self::onchainState($storeId)['hasXpub'];

        if ($hasLightning) {
            $enabled = 1;
            $useSwap = SwapAutoMelt::FORCE_LIGHTNING;
            $result = 'lightning';
        } elseif ($canSwap) {
            $enabled = 1;
            $useSwap = SwapAutoMelt::FORCE_SWAP;
            $result = 'swap';
        } else {
            $enabled = 0;
            $useSwap = SwapAutoMelt::FORCE_LIGHTNING;
            $result = 'off';
        }

        Database::update('stores', [
            'auto_melt_enabled' => $enabled,
            'auto_melt_use_swap' => $useSwap,
        ], 'id = ?', [$storeId]);

        return $result;
    }

    /**
     * Does the zero-conf question apply to this store — is there ANY source
     * of on-chain receive addresses whose settlement onchain_min_confs would
     * time? True for an xpub/static destination and equally for Strike
     * on-chain minting (Strike-minted addresses are settled by the same
     * chain watcher against the same onchain_min_confs). Drives whether the
     * zeroconf screen appears in the wizard sequence.
     */
    public static function zeroConfApplicable(?string $storeId): bool {
        if ($storeId === null || $storeId === '') {
            return false;
        }
        if (self::onchainState($storeId)['configured']) {
            return true;
        }
        require_once __DIR__ . '/onchain/config.php';
        return OnchainConfig::strikeEnabledForStore($storeId);
    }

    /**
     * Does this store have somewhere on-chain to send money? Drives whether
     * submarine swaps can be enabled (swaps derive a fresh address per swap,
     * so they need an xpub — a single reused address will not do). The
     * zeroconf screen gates on zeroConfApplicable() instead, which also
     * counts Strike on-chain minting as a receive source.
     *
     * @return array{configured: bool, hasXpub: bool}
     */
    public static function onchainState(?string $storeId): array {
        if ($storeId === null || $storeId === '') {
            return ['configured' => false, 'hasXpub' => false];
        }
        $row = Database::fetchOne(
            "SELECT onchain_address_mode, onchain_xpub, onchain_static_address
               FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row) {
            return ['configured' => false, 'hasXpub' => false];
        }
        $hasXpub = trim((string)($row['onchain_xpub'] ?? '')) !== '';
        $hasStatic = trim((string)($row['onchain_static_address'] ?? '')) !== '';
        // A store can hold both columns if the operator switched modes; the
        // active one is whichever address_mode names.
        $mode = ($row['onchain_address_mode'] ?? 'xpub') === 'static' ? 'static' : 'xpub';
        $configured = $mode === 'static' ? $hasStatic : $hasXpub;
        return ['configured' => $configured, 'hasXpub' => $mode === 'xpub' && $hasXpub];
    }
}
