<?php
/**
 * Repository layout guard.
 *
 * The application tree — the exact set of files a release zip extracts to and
 * the auto-updater overlays onto an install root — lives under app/ in the
 * repository, so the GitHub landing page shows the README instead of a wall
 * of PHP entry points. The repo root is documentation (*.md) plus tooling
 * directories only.
 *
 * Installed servers keep the flat layout: scripts/build-standalone.sh and the
 * Dockerfiles copy FROM app/ and flatten. This test pins both halves so a
 * future file added at the repo root, or a build step that silently reads a
 * root-relative path again, fails CI instead of shipping an incomplete zip.
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

$repo = dirname(__DIR__, 2);
$app = $repo . '/app';

// Every entry point router.php dispatches to, plus the support trees the
// release zip ships. Mirrors the copy list in scripts/build-standalone.sh and
// the "Verify standalone layout" step in the workflows.
$entryPoints = [
    'admin.php', 'api.php', 'cron.php', 'payment.php', 'pay.php', 'receive.php',
    'recover.php', 'router.php', 'setup.php', 'index.php', 'health.php',
    'product-image.php', 'update.php', 'provision.php', 'sso.php',
];
$appDirs = ['includes', 'assets', 'api-keys', 'images', 'cashu-wallet-php', 'mint-discovery'];
$appFiles = ['.htaccess', 'favicon.ico', 'user_config.example.php', 'composer.json', 'composer.lock'];

// ---- 1. The app tree is complete, and nothing of it is left at the root ----
foreach ($entryPoints as $f) {
    assert_true(is_file("$app/$f"), "app/$f exists");
    assert_true(!file_exists("$repo/$f"), "$f is not at the repo root");
}
foreach ($appDirs as $d) {
    assert_true(is_dir("$app/$d"), "app/$d exists");
    assert_true(!is_dir("$repo/$d"), "$d/ is not at the repo root");
}
foreach ($appFiles as $f) {
    assert_true(is_file("$app/$f"), "app/$f exists");
    assert_true(!file_exists("$repo/$f"), "$f is not at the repo root");
}

// Entry points locate includes/ relative to their own directory; the layout
// only works if includes/database.php is their sibling (as in the zip).
assert_true(is_file("$app/includes/database.php"), 'app/includes/database.php is a sibling of the entry points');
assert_true(is_file("$app/includes/config.php"), 'app/includes/config.php present (version source for releases)');

// ---- 2. Tracked root entries: *.md files and allow-listed directories ------
// git ls-files sees only tracked paths, so local untracked state (build/,
// .claude/, a stray BUILD_INFO) cannot make this flap between machines.
$allowedRootEntries = [
    '.github', '.gitignore', '.gitmodules',
    'app', 'docker', 'docs', 'scripts', 'tests', 'windows', 'wordpress',
];
$lsFiles = @shell_exec('cd ' . escapeshellarg($repo) . ' && git ls-files 2>/dev/null');
if (is_string($lsFiles) && trim($lsFiles) !== '') {
    $rootEntries = [];
    foreach (preg_split('/\R/', trim($lsFiles)) as $path) {
        $first = explode('/', $path, 2)[0];
        $rootEntries[$first] = true;
    }
    $rootEntries = array_keys($rootEntries);
    sort($rootEntries);
    foreach ($rootEntries as $entry) {
        if (in_array($entry, $allowedRootEntries, true)) {
            continue;
        }
        assert_true(
            preg_match('/\.md$/i', $entry) === 1,
            "repo root entry '$entry' is a .md file or an allow-listed directory (add app files under app/)"
        );
    }
    foreach (['README.md', 'LICENSE.md', 'USE_POLICY.md', 'PRIVACY.md'] as $doc) {
        assert_true(in_array($doc, $rootEntries, true), "$doc stays at the repo root");
    }
} else {
    echo "note: git not available, skipping the tracked-root-entries check\n";
}

// ---- 3. Build inputs read from app/, outputs keep the flat layout ----------
$script = file_get_contents("$repo/scripts/build-standalone.sh");
assert_true($script !== false, 'scripts/build-standalone.sh readable');
assert_true(str_contains($script, 'APP="app"'), 'build-standalone.sh declares APP="app"');
assert_true(str_contains($script, '"$APP/includes/config.php"'), 'build-standalone.sh reads the version from app/includes/config.php');

// Every cp source in the build script must come from $APP (the app tree) or
// $BUILD_DIR (re-arranging build output). Root-level sources are allowed only
// for the license/policy documents, which deliberately stay at the repo root.
$rootDocs = ['LICENSE.md', 'USE_POLICY.md', 'PRIVACY.md'];
foreach (preg_split('/\R/', $script) as $lineNo => $line) {
    $trim = ltrim($line);
    if (!str_starts_with($trim, 'cp ')) {
        continue;
    }
    $tokens = preg_split('/\s+/', trim($trim));
    array_shift($tokens);             // cp
    array_pop($tokens);               // destination
    foreach ($tokens as $src) {
        if ($src === '-r' || $src === '-a') {
            continue;
        }
        $src = trim($src, '"');
        $ok = str_starts_with($src, '$APP/') || str_starts_with($src, '$BUILD_DIR')
            || in_array($src, $rootDocs, true);
        assert_true($ok, 'build-standalone.sh line ' . ($lineNo + 1) . " copies from app/ (got '$src')");
    }
}

foreach (['docker/Dockerfile.production', 'docker/Dockerfile.standalone'] as $dockerfile) {
    $df = file_get_contents("$repo/$dockerfile");
    assert_true($df !== false, "$dockerfile readable");
    $joined = preg_replace('/\\\\\R\s*/', ' ', $df); // fold line continuations
    foreach (preg_split('/\R/', $joined) as $lineNo => $line) {
        if (!preg_match('/^COPY\s+(.*)$/', trim($line), $m)) {
            continue;
        }
        $tokens = preg_split('/\s+/', trim($m[1]));
        if (str_starts_with($tokens[0], '--from=')) {
            continue;                 // stage-to-stage copy (composer vendor/)
        }
        array_pop($tokens);           // destination
        foreach ($tokens as $src) {
            $ok = str_starts_with($src, 'app/') || str_starts_with($src, 'docker/')
                || str_starts_with($src, 'scripts/');
            assert_true($ok, "$dockerfile COPY source '$src' is under app/, docker/ or scripts/");
        }
    }
    assert_true(!str_contains($df, 'templates/'), "$dockerfile no longer copies the nonexistent templates/ dir");
}

// Release tagging reads the version from the moved config.php.
$verify = file_get_contents("$repo/scripts/verify-release-version.sh");
assert_true(str_contains($verify, 'app/includes/config.php'), 'verify-release-version.sh reads app/includes/config.php');
$testingRelease = file_get_contents("$repo/.github/workflows/testing-release.yml");
assert_true(str_contains($testingRelease, 'app/includes/config.php'), 'testing-release.yml reads app/includes/config.php');

// ---- 4. Local-state ignores follow the tree --------------------------------
$gitignore = file_get_contents("$repo/.gitignore");
assert_true(str_contains($gitignore, "app/data/**"), '.gitignore ignores app/data/**');
assert_true(str_contains($gitignore, "app/vendor/**"), '.gitignore ignores app/vendor/**');
assert_true(!preg_match('/^data\/\*\*$/m', $gitignore), '.gitignore has no stale root data/** rule');
// The e2e scratch dir sits inside the served tree on purpose (fixture data
// dirs must be under the web root to model a shared-hosting install — see
// tests/conftest.py SESSION_TMP); keep it ignored and never shipped.
assert_true(str_contains($gitignore, "app/.tmp/**"), '.gitignore ignores app/.tmp/**');
$conftest = file_get_contents("$repo/tests/conftest.py");
assert_true(str_contains($conftest, 'SESSION_TMP = TESTS_DIR.parent / "app" / ".tmp"'), 'conftest SESSION_TMP lives under app/');
assert_true(!str_contains($script, '.tmp'), 'build-standalone.sh never touches the scratch dir (copies named paths only)');

// ---- 5. The Windows package convention is unchanged: cron-runner.php looks
// for ./app next to itself (BareBits/app in the desktop zip). The repo's app/
// directory is named after that convention; tests point the runner at it via
// CASHUPAY_DESKTOP_APP_DIR.
$cronRunner = file_get_contents("$repo/windows/cron-runner.php");
assert_true(str_contains($cronRunner, "__DIR__ . '/app'"), 'windows/cron-runner.php defaults to ./app');

echo "test_repo_layout: OK\n";
