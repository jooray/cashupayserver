<?php
/**
 * WordPress installer (wordpress/installer.php) — target resolution, data-dir
 * placement, checksum handling, unpack validation, and run_install's guards.
 *
 * The e2e install-mode test drives the happy path and the tampered-zip
 * refusal against a real WordPress; this file pins the branches an e2e can't
 * cheaply reach: the wp-content fallback target, the dirname validation, the
 * three data-dir placements, every SHA256SUMS failure mode (unreachable,
 * missing entry, mismatch) and the unverified no-sums path, the
 * not-a-release unpack refusal, and run_install's never-clobber /
 * resume-our-own-install behavior.
 *
 * The WordPress API surface is stubbed below (options, writability, HTTP,
 * download_url, unzip_file, filesystem) with real directories under a
 * tempdir, so path logic runs for real while writability and remote calls
 * are scripted per scenario.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- test root + WordPress constants -----------------------------------------
$T = sys_get_temp_dir() . '/barebits_installer_' . bin2hex(random_bytes(6));
mkdir($T . '/site/wordpress/wp-content', 0750, true);
define('ABSPATH', $T . '/site/wordpress/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
register_shutdown_function(function () use ($T) { @cleanup_db($T); });

// --- minimal WordPress stubs -------------------------------------------------
$GLOBALS['wp_options'] = [];
$GLOBALS['unwritable'] = [];             // paths wp_is_writable reports false for
$GLOBALS['http_routes'] = [];            // url-substring => ['code'=>…, 'body'=>…] | 'error'
$GLOBALS['http_log'] = [];               // every wp_remote_get url
$GLOBALS['download_content'] = null;     // bytes download_url writes; null = WP_Error
$GLOBALS['unzip_layout'] = 'release';    // 'release' | 'no-buildinfo' | 'no-provision' | 'error'

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function apply_filters($hook, $value) { return $value; }
function site_url($path = '') { return 'http://wp.test' . $path; }
function content_url($path = '') { return 'http://wp.test/wp-content' . $path; }
function home_url($path = '') { return 'http://wp.test' . $path; }
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . ltrim($path, '/'); }
function wp_is_writable($path) {
    return !in_array(rtrim((string)$path, '/'), $GLOBALS['unwritable'], true) && is_writable($path);
}
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0750, true); }
function wp_generate_password($len = 12, $special = true, $extra = false) {
    return substr(bin2hex(random_bytes(32)), 0, $len);
}
function barebits_can_install_plugins(): bool { return true; }

class WP_Error {
    public function __construct(private string $msg = 'stub error') {}
    public function get_error_message(): string { return $this->msg; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

function wp_remote_get($url, $args = []) {
    $GLOBALS['http_log'][] = $url;
    foreach ($GLOBALS['http_routes'] as $needle => $response) {
        if (str_contains($url, $needle)) {
            return $response === 'error' ? new WP_Error('unreachable') : $response;
        }
    }
    return new WP_Error('no stub route for ' . $url);
}
function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

function download_url($url, $timeout = 300) {
    if ($GLOBALS['download_content'] === null) {
        return new WP_Error('download refused');
    }
    $file = tempnam(sys_get_temp_dir(), 'barebits_dl_');
    file_put_contents($file, $GLOBALS['download_content']);
    return $file;
}

class StubFilesystem {
    public function delete($path, $recursive = false) { cleanup_db((string)$path); return true; }
    // Same contract as WP_Filesystem_Direct::move — a rename underneath;
    // installer.php falls back to copy_dir when it returns false.
    public function move($source, $destination, $overwrite = false) { return @rename((string)$source, (string)$destination); }
}
function WP_Filesystem() { $GLOBALS['wp_filesystem'] = new StubFilesystem(); return true; }

function unzip_file($zip, $to) {
    if ($GLOBALS['unzip_layout'] === 'error') {
        return new WP_Error('corrupt zip');
    }
    mkdir($to . '/barebits', 0750, true);
    if ($GLOBALS['unzip_layout'] === 'release' || $GLOBALS['unzip_layout'] === 'no-provision') {
        file_put_contents($to . '/barebits/BUILD_INFO', "stub build\n");
        file_put_contents($to . '/barebits/setup.php', "<?php // stub\n");
    }
    if ($GLOBALS['unzip_layout'] === 'release') {
        // The managed-install marker: a release the install flow can finish.
        file_put_contents($to . '/barebits/provision.php', "<?php // stub\n");
    }
    return true;
}
function copy_dir($from, $to) {
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        copy($from . '/' . $entry, rtrim($to, '/') . '/' . $entry);
    }
    return true;
}

require __DIR__ . '/wp_compat_stubs.php';
require dirname(__DIR__, 2) . '/wordpress/installer.php';

// =============================================================================
// barebits_resolve_install_target
// =============================================================================

// Dirname validation: a single path segment, nothing traversal-shaped.
foreach (['../evil', 'two words', '-leading', str_repeat('a', 65), 'dot.name'] as $bad) {
    $t = barebits_resolve_install_target($bad);
    assert_true(isset($t['error']), "dirname {$bad} is rejected");
}

// Default: a sibling of wp-admin in the web root, served at /barebits.
$t = barebits_resolve_install_target('');
assert_eq(rtrim(ABSPATH, '/') . '/barebits', $t['dir'] ?? null, 'default target is ABSPATH/barebits');
assert_eq('http://wp.test/barebits', $t['url'] ?? null, 'served at site_url(/barebits)');

// Web root not writable → wp-content fallback, with the matching content URL.
$GLOBALS['unwritable'] = [rtrim(ABSPATH, '/')];
$t = barebits_resolve_install_target('barebits');
assert_eq(WP_CONTENT_DIR . '/barebits', $t['dir'] ?? null, 'unwritable web root falls back to wp-content');
assert_eq('http://wp.test/wp-content/barebits', $t['url'] ?? null, 'fallback URL is content_url-based');

// Neither writable → a clean operator-facing error, no partial paths.
$GLOBALS['unwritable'] = [rtrim(ABSPATH, '/'), rtrim(WP_CONTENT_DIR, '/')];
$t = barebits_resolve_install_target('barebits');
assert_true(isset($t['error']), 'nothing writable is an error');
assert_true(str_contains($t['error'], 'cannot be installed automatically'), 'with the operator-facing wording');
$GLOBALS['unwritable'] = [];

// =============================================================================
// barebits_resolve_data_dir — outside the web root when possible, and
// namespaced per site so co-hosted WordPress installs never share a wallet DB
// =============================================================================

$installDir = rtrim(ABSPATH, '/') . '/barebits';
$outside = dirname(rtrim(ABSPATH, '/'))
    . '/barebits-data-' . substr(hash('sha256', ABSPATH), 0, 12);

// Parent of the web root not writable, no existing dir → inside the install.
$GLOBALS['unwritable'] = [rtrim(dirname($outside), '/')];
assert_eq($installDir . '/data', barebits_resolve_data_dir($installDir),
    'no writable parent falls back to the install\'s own data/');
$GLOBALS['unwritable'] = [];

// Parent writable → the site-hashed sibling dir is created outside the
// docroot. The suffix is a hash of ABSPATH: a second site whose docroot
// shares this parent hashes to a different name and can never collide.
assert_eq($outside, barebits_resolve_data_dir($installDir), 'writable parent places data outside the web root');
assert_true(is_dir($outside), 'and actually creates it');

// Already existing (a resumed install) → reused, not recreated.
assert_eq($outside, barebits_resolve_data_dir($installDir), 'an existing outside dir is reused');

// A RECORDED directory always wins — this is how pre-namespacing installs
// (plain barebits-data) keep their wallet across plugin updates.
$legacy = dirname(rtrim(ABSPATH, '/')) . '/barebits-data';
mkdir($legacy, 0750, true);
update_option('barebits_install_data_dir', $legacy);
assert_eq($legacy, barebits_resolve_data_dir($installDir),
    'a recorded legacy data dir is reused, never abandoned for the new name');
// A recorded dir that no longer exists is ignored, not resurrected blindly.
delete_option('barebits_install_data_dir');
cleanup_db($legacy);

// An UNRECORDED foreign dir at the legacy shared name is never adopted:
// nothing points the resolver at it any more.
mkdir($legacy, 0750, true);
file_put_contents($legacy . '/barebits.sqlite', 'another site\'s wallet');
assert_eq($outside, barebits_resolve_data_dir($installDir),
    'an unrecorded barebits-data dir (another site\'s) is never adopted');
cleanup_db($legacy);

// =============================================================================
// barebits_download_release — every SHA256SUMS posture
// =============================================================================

$zipBytes = 'ZIPDATA-' . bin2hex(random_bytes(8));
$GLOBALS['download_content'] = $zipBytes;
$release = [
    'ok' => true,
    'tag' => 'v9.9',
    'zip_url' => 'http://releases.test/dl/barebits.zip',
    'zip_name' => 'barebits.zip',
    'sums_url' => 'http://releases.test/dl/SHA256SUMS',
];

// Sums published but unreachable: abort — never install unverified code.
$GLOBALS['http_routes'] = ['SHA256SUMS' => 'error'];
$d = barebits_download_release($release);
assert_eq(false, $d['ok'], 'unreachable SHA256SUMS aborts');
assert_true(str_contains($d['message'], 'aborting rather than installing unverified code'), 'with the refusal wording');

// Sums reachable but no entry for our zip: abort.
$GLOBALS['http_routes'] = ['SHA256SUMS' => [
    'code' => 200,
    'body' => hash('sha256', $zipBytes) . "  some-other-file.zip\n",
]];
$d = barebits_download_release($release);
assert_eq(false, $d['ok'], 'a SHA256SUMS with no entry for the zip aborts');
assert_true(str_contains($d['message'], 'no entry for barebits.zip'), 'and names the missing entry');

// Mismatch: abort (the e2e proves this end to end; pinned here too).
$GLOBALS['http_routes'] = ['SHA256SUMS' => [
    'code' => 200,
    'body' => str_repeat('0', 64) . "  barebits.zip\n",
]];
$d = barebits_download_release($release);
assert_eq(false, $d['ok'], 'a checksum mismatch aborts');
assert_true(str_contains($d['message'], 'Checksum mismatch'), 'with the mismatch wording');

// Match (BSD-style asterisk marker included): verified download.
$GLOBALS['http_routes'] = ['SHA256SUMS' => [
    'code' => 200,
    'body' => hash('sha256', $zipBytes) . " *barebits.zip\n",
]];
$d = barebits_download_release($release);
assert_eq(true, $d['ok'], 'a matching checksum passes');
assert_eq(true, $d['verified'], 'and reports verified');
assert_true(is_file($d['file']), 'the caller receives the downloaded file');
@unlink($d['file']);

// No sums on the release at all: allowed, but explicitly unverified — the
// onboarding flow renders its not-checksum-verified warning off this flag.
$noSums = $release;
$noSums['sums_url'] = null;
$d = barebits_download_release($noSums);
assert_eq(true, $d['ok'], 'a sums-less release still downloads');
assert_eq(false, $d['verified'], 'but reports unverified');
@unlink($d['file']);

// =============================================================================
// barebits_unpack_release — refuses non-release zips
// =============================================================================

$zipFile = tempnam(sys_get_temp_dir(), 'barebits_zip_');
file_put_contents($zipFile, $zipBytes);

$GLOBALS['unzip_layout'] = 'no-buildinfo';
$u = barebits_unpack_release($zipFile, $installDir);
assert_eq(false, $u['ok'], 'a zip without barebits/BUILD_INFO is refused');
assert_true(str_contains($u['message'], 'does not look like a BareBits release'), 'with the refusal wording');
assert_false(is_dir($installDir), 'nothing is left at the install target');
// Staging lives under wp-content/upgrade (WordPress's own staging area),
// never in the served web root next to the install target.
assert_eq([], glob(dirname($installDir) . '/*barebits-staging-*') ?: [],
    'nothing is ever staged in the web root');
$staging = glob(WP_CONTENT_DIR . '/upgrade/barebits-staging-*') ?: [];
assert_eq([], $staging, 'the staging directory is cleaned up');

// A pre-managed-install release (v1.3.1 and older: no provision.php) must be
// refused up front — its wizard would demand a password nobody provisioned
// and there is no handshake to collect credentials from afterwards.
$GLOBALS['unzip_layout'] = 'no-provision';
$u = barebits_unpack_release($zipFile, $installDir);
assert_eq(false, $u['ok'], 'a release without provision.php is refused');
assert_true(str_contains($u['message'], 'does not support'), 'with the too-old wording');
assert_false(is_dir($installDir), 'nothing is left at the install target');
assert_eq([], glob(WP_CONTENT_DIR . '/upgrade/barebits-staging-*') ?: [],
    'the staging directory is cleaned up after the refusal');

$GLOBALS['unzip_layout'] = 'error';
$u = barebits_unpack_release($zipFile, $installDir);
assert_eq(false, $u['ok'], 'a corrupt zip is refused');
assert_true(str_contains($u['message'], 'Unzip failed'), 'with the unzip error');

$GLOBALS['unzip_layout'] = 'release';
$u = barebits_unpack_release($zipFile, $installDir);
assert_eq(true, $u['ok'], 'a real release layout unpacks');
assert_true(is_file($installDir . '/BUILD_INFO'), 'and lands at the install target');
assert_eq([], glob(WP_CONTENT_DIR . '/upgrade/barebits-staging-*') ?: [], 'staging cleaned after success too');
@unlink($zipFile);
cleanup_db($installDir);

// =============================================================================
// barebits_run_install — guards, then the full stubbed pipeline
// =============================================================================

$GLOBALS['http_routes']['releases/latest'] = [
    'code' => 200,
    'body' => json_encode(['tag_name' => 'v9.9', 'assets' => [
        ['name' => 'SHA256SUMS', 'browser_download_url' => 'http://releases.test/dl/SHA256SUMS'],
        // Decoys the asset picker must skip.
        ['name' => 'barebits-windows.zip', 'browser_download_url' => 'http://releases.test/dl/win.zip'],
        ['name' => 'barebits_wordpress_plugin.zip', 'browser_download_url' => 'http://releases.test/dl/wp.zip'],
        ['name' => 'barebits-v9.9.zip', 'browser_download_url' => 'http://releases.test/dl/barebits.zip'],
    ]]),
];
$GLOBALS['http_routes']['SHA256SUMS'] = [
    'code' => 200,
    'body' => hash('sha256', $zipBytes) . "  barebits-v9.9.zip\n",
];

// A directory we did not create is never touched — and never even fetched for.
mkdir($installDir, 0750, true);
file_put_contents($installDir . '/index.html', 'someone else lives here');
$GLOBALS['http_log'] = [];
$r = barebits_run_install('barebits');
assert_eq(false, $r['ok'], 'an existing non-empty directory refuses the install');
assert_true(str_contains($r['message'], 'already exists and is not empty'), 'with the move-it-aside wording');
assert_eq('someone else lives here', file_get_contents($installDir . '/index.html'), 'the foreign directory is untouched');
assert_eq([], $GLOBALS['http_log'], 'nothing was downloaded for a refused install');
cleanup_db($installDir);

// Full pipeline on a clean target: fetch → verify → unpack → config → state.
$r = barebits_run_install('barebits');
assert_eq(true, $r['ok'], 'the stubbed pipeline installs: ' . ($r['message'] ?? ''));
assert_eq(true, $r['verified'], 'checksum-verified end to end');
assert_eq('http://wp.test/barebits', $r['url'], 'served at the resolved URL');
assert_true(is_file($installDir . '/BUILD_INFO'), 'release unpacked into place');

$config = (string)file_get_contents($installDir . '/user_config.php');
foreach (['CASHUPAY_DATA_DIR', 'CASHUPAY_BASE_URL', 'CASHUPAY_MANAGED_INSTALL',
          'CASHUPAY_SHOP_URL', 'CASHUPAY_RETRY_URL_TEMPLATE',
          'CASHUPAY_ADMIN_PASSWORD_HASH', 'CASHUPAY_SSO_KEY_HASH',
          'CASHUPAY_PROVISION_TOKEN_HASH', 'CASHUPAY_MANAGED_RETURN_URL'] as $constant) {
    assert_true(str_contains($config, $constant), "user_config.php declares {$constant}");
}
// The return URL is the plugin's credential-collection endpoint — the wizard's
// completion screen sends the operator there and onboarding resumes itself.
assert_true(str_contains($config, 'http://wp.test/wp-admin/admin-post.php?action=barebits_provision_return'),
    'the managed return URL points at the provision-return admin-post action');
// Only hashes touch the install; the plaintexts live in WordPress options.
$token = (string)get_option('barebits_provision_token');
$ssoKey = (string)get_option('barebits_sso_key');
$password = (string)get_option('barebits_admin_password');
assert_true($token !== '' && $ssoKey !== '' && $password !== '', 'plaintexts persisted in options');
assert_false(str_contains($config, $token), 'the provision token plaintext never touches the install');
assert_false(str_contains($config, $ssoKey), 'the SSO key plaintext never touches the install');
assert_false(str_contains($config, $password), 'the admin password plaintext never touches the install');
assert_true(str_contains($config, hash('sha256', $token)), 'the token hash is what got written');
assert_true(str_contains($config, hash('sha256', $ssoKey)), 'the SSO key hash is what got written');
assert_eq('install', get_option('barebits_mode'), 'mode recorded');
assert_eq($installDir, get_option('barebits_install_dir'), 'install dir recorded');
assert_eq('http://wp.test/barebits', get_option('barebits_install_url'),
    'the install\'s own URL recorded (the cron heartbeat outlives mode changes)');
assert_eq($outside, get_option('barebits_install_data_dir'), 'data dir recorded (outside the web root)');

// Resuming with our own completed install in place: reused, nothing
// re-fetched — and the connection options a "Start over" reset cleared are
// restored, while the credentials (whose hashes live in the install's own
// user_config.php) are left untouched.
delete_option('barebits_mode');
delete_option('barebits_server_url');
delete_option('barebits_install_url');
$tokenBefore = get_option('barebits_provision_token');
$GLOBALS['http_log'] = [];
$r = barebits_run_install('barebits');
assert_eq(true, $r['ok'], 'our own completed install resumes');
assert_eq([], $GLOBALS['http_log'], 'a resume never re-downloads');
assert_eq('install', get_option('barebits_mode'), 'a resume restores the mode');
assert_eq('http://wp.test/barebits', get_option('barebits_server_url'), 'and the server URL');
assert_eq('http://wp.test/barebits', get_option('barebits_install_url'), 'and the install\'s own URL');
assert_eq($tokenBefore, get_option('barebits_provision_token'),
    'a resume never regenerates the credentials behind the install\'s hashes');

// Our own install, but from a pre-managed-install release (a leftover from a
// plugin version that still read /releases/latest): resuming would walk the
// merchant into the same dead end, so it is refused — and never deleted, the
// folder could hold a database.
unlink($installDir . '/provision.php');
$GLOBALS['http_log'] = [];
$r = barebits_run_install('barebits');
assert_eq(false, $r['ok'], 'a too-old own install refuses to resume');
assert_true(str_contains($r['message'], 'too old'), 'with the too-old wording');
assert_true(is_file($installDir . '/BUILD_INFO'), 'and the old install is left in place');
assert_eq([], $GLOBALS['http_log'], 'nothing was downloaded over it');

echo "test_wp_installer_paths: ok\n";
