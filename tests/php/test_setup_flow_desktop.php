<?php
/**
 * Windows desktop package: onboarding wizard shape and detection.
 *
 * The desktop package (windows/ launcher + bundled PHP) handles cron itself
 * (desktop-helper.php ticks cron-runner.php) and only listens on 127.0.0.1,
 * so the wizard must drop the cron and security screens there — while a
 * plain server install and add_store keep their exact shapes.
 * Also covers the cron screen's OS-keyed scheduler line: a Windows server
 * host has no crontab, so it must be handed a schtasks command instead.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/includes/setup_flow.php';
require_once dirname(__DIR__, 2) . '/includes/desktop.php';

// --- Desktop drops the cron screen ----------------------------------------

$desktop = SetupFlow::stepSequence('', false, true, true);
assert_eq(
    ['terms', 'analytics', 'install_type', 'security', 'password', 'email', 'store', 'onchain', 'lightning', 'mints', 'updates', 'done'],
    $desktop,
    'desktop drops the cron screen but keeps the auto-update question'
);
assert_eq('updates', SetupFlow::nextStep('mints', $desktop), 'on desktop, the auto-update question follows mints');
assert_eq('done', SetupFlow::nextStep('updates', $desktop), 'on desktop, done follows the auto-update question');

// The realistic desktop shape: data dir sits inside the web root (app/data),
// but the security screen is dropped by the caller anyway because the server
// only listens on loopback — both screens gone.
$desktopNoSecurity = SetupFlow::stepSequence('', false, false, true);
assert_eq(
    ['terms', 'analytics', 'install_type', 'password', 'email', 'store', 'onchain', 'lightning', 'mints', 'updates', 'done'],
    $desktopNoSecurity,
    'the shipped desktop flow has neither the security nor the cron screen'
);
assert_eq('password', SetupFlow::nextStep('install_type', $desktopNoSecurity), 'the installation type goes straight to password on desktop');

// Omitting the flag keeps the screen — every historical call site behaves
// as before.
assert_true(
    in_array('cron', SetupFlow::stepSequence('', false), true),
    'the cron screen stays by default'
);

// add_store never had the tail screens; the flag must not disturb it.
assert_eq(
    SetupFlow::stepSequence('add_store', true),
    SetupFlow::stepSequence('add_store', true, true, true),
    'add_store is unaffected by the desktop flag'
);

// --- Detection: explicit env var ------------------------------------------
//
// BareBits.bat sets CASHUPAY_DESKTOP=1 for every process it starts.
// Same env conventions as the updater's kill switches: non-empty and not "0".

putenv('CASHUPAY_DESKTOP=1');
assert_true(Desktop::isWindowsDesktop(), 'CASHUPAY_DESKTOP=1 marks the desktop package');
putenv('CASHUPAY_DESKTOP=0');
assert_false(Desktop::isWindowsDesktop(), 'CASHUPAY_DESKTOP=0 reads as unset');
putenv('CASHUPAY_DESKTOP=');
assert_false(Desktop::isWindowsDesktop(), 'an empty CASHUPAY_DESKTOP reads as unset');
putenv('CASHUPAY_DESKTOP');
assert_false(
    Desktop::isWindowsDesktop(),
    'without the env var this Linux test rig is not a desktop install'
);

// --- Detection: package-layout fallback ------------------------------------
//
// A merchant who starts php -S by hand (skipping the .bat) still gets the
// desktop wizard: <package>/app with the helper scripts one level up is the
// shape scripts/build-windows-desktop.sh stages, and it is only trusted on
// Windows under the built-in server.

$pkg = sys_get_temp_dir() . '/cashupay-desktop-' . bin2hex(random_bytes(4));
mkdir($pkg . '/app', 0755, true);
file_put_contents($pkg . '/cron-runner.php', "<?php\n");
file_put_contents($pkg . '/desktop-helper.php', "<?php\n");

assert_true(
    Desktop::looksLikeDesktopLayout('Windows', 'cli-server', $pkg . '/app'),
    'the staged package layout is recognised on Windows under the built-in server'
);
assert_false(
    Desktop::looksLikeDesktopLayout('Linux', 'cli-server', $pkg . '/app'),
    'the same layout on Linux must not flip the wizard'
);
assert_false(
    Desktop::looksLikeDesktopLayout('Windows', 'apache2handler', $pkg . '/app'),
    'a real web server on Windows is a server install, not the desktop package'
);

unlink($pkg . '/desktop-helper.php');
assert_false(
    Desktop::looksLikeDesktopLayout('Windows', 'cli-server', $pkg . '/app'),
    'a lone cron-runner.php sibling is not the package'
);
unlink($pkg . '/cron-runner.php');
rmdir($pkg . '/app');
rmdir($pkg);

// --- The cron screen's scheduler line is keyed on the host OS ---------------

$key = str_repeat('ab', 32);
$url = 'https://shop.example.com/cron.php';

$unix = SetupFlow::cronScheduleLine('Linux', $key, $url);
assert_true(str_contains($unix['intro'], 'crontab'), 'unix hosts are pointed at crontab');
assert_eq(
    "* * * * * curl -fsS -H 'X-CRON-KEY: $key' $url > /dev/null",
    $unix['line'],
    'the crontab line matches the shape the admin Settings page renders'
);

$win = SetupFlow::cronScheduleLine('Windows', $key, $url);
assert_true(str_contains($win['intro'], 'Task Scheduler'), 'Windows hosts are pointed at Task Scheduler');
assert_true(str_starts_with($win['line'], 'schtasks /Create'), 'the Windows line is a schtasks command');
assert_true(str_contains($win['line'], '/SC MINUTE'), 'scheduled every minute, like the crontab line');
assert_true(str_contains($win['line'], '\"X-CRON-KEY: ' . $key . '\"'), 'the key header survives schtasks /TR quoting');
assert_true(str_contains($win['line'], $url), 'the cron URL is included');
assert_false(str_contains($win['line'], '/dev/null'), 'no unix redirection on Windows');
assert_false(str_contains($win['line'], "'"), 'no single quotes — cmd.exe does not strip them');

echo "ok\n";
