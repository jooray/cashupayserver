<?php
/**
 * Runs the whole test suite: every tests/*.php script, the extra modes some of them
 * take, and the cashu-wallet-php library suite. Exits non-zero if anything fails.
 *
 * Run: php tests/run.php            (all)
 *      php tests/run.php conversion (only tests whose name contains "conversion")
 */

declare(strict_types=1);

// Tests create disposable databases and spawn helper processes; they must never be
// reachable over HTTP, even on a deployment that serves this directory.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$filter = $argv[1] ?? null;

// Helpers that other tests start themselves; not tests on their own.
$notTests = ['run.php', 'mock_mint_router.php', 'api_test_harness.php'];

/** @var array<string, array{0: string[], 1: string}> name => [command, working dir] */
$jobs = [];
foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
    $name = basename($file);
    if (in_array($name, $notTests, true)) {
        continue;
    }
    $jobs[basename($name, '.php')] = [[$php, $file], $root];
}
// The migration test has a second mode: two stores sharing one wallet must be refused.
$jobs['wallet_account_migration collision'] = [[$php, __DIR__ . '/wallet_account_migration.php', 'collision'], $root];

$library = $root . '/cashu-wallet-php';
$skipped = [];
if (is_file($library . '/vendor/bin/phpunit')) {
    // The library's dev lock pins PHPUnit 12, which needs PHP 8.3.
    if (PHP_VERSION_ID >= 80300) {
        $jobs['cashu-wallet-php phpunit'] = [[$php, $library . '/vendor/bin/phpunit'], $library];
    } else {
        $skipped[] = 'cashu-wallet-php phpunit (PHPUnit 12 needs PHP 8.3+)';
    }
} else {
    $skipped[] = 'cashu-wallet-php phpunit (run "composer install" in cashu-wallet-php)';
}
if (is_file($library . '/tests/storage_safety.php')) {
    $jobs['cashu-wallet-php storage_safety'] = [[$php, $library . '/tests/storage_safety.php'], $library];
}

if ($filter !== null) {
    $jobs = array_filter($jobs, fn($name) => str_contains($name, $filter), ARRAY_FILTER_USE_KEY);
}
ksort($jobs);

$failed = [];
$passed = 0;
$started = microtime(true);
foreach ($jobs as $name => [$command, $cwd]) {
    $t = microtime(true);
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($process)) {
        $failed[$name] = 'could not start';
        printf("FAIL %s (could not start)\n", $name);
        continue;
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    $seconds = microtime(true) - $t;

    if ($code === 0) {
        $passed++;
        printf("ok   %s (%.1fs)\n", $name, $seconds);
    } else {
        $failed[$name] = $output;
        printf("FAIL %s (exit %d, %.1fs)\n", $name, $code, $seconds);
    }
}

foreach ($failed as $name => $output) {
    echo "\n--- output of $name ---\n" . rtrim((string) $output) . "\n";
}

echo "\n";
foreach ($skipped as $what) {
    echo "skipped: $what\n";
}
printf("%d passed, %d failed, %.1fs\n", $passed, count($failed), microtime(true) - $started);
if ($failed !== []) {
    echo 'Failed: ' . implode(', ', array_keys($failed)) . "\n";
}
exit($failed === [] && $jobs !== [] ? 0 : 1);
