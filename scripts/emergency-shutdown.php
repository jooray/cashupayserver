<?php
/**
 * Emergency shutdown notice for CashuPayServer — the whole procedure in one tool.
 *
 * Read docs/EMERGENCY-SHUTDOWN.md first; this is the "nuclear" option (every install on
 * an affected version stops serving anything until its operator upgrades).
 *
 *   php scripts/emergency-shutdown.php            interactive: ask, sign, write, upload
 *   php scripts/emergency-shutdown.php --check    what does the live manifest say now?
 *   php scripts/emergency-shutdown.php --lift     write a manifest without the notice
 *   php scripts/emergency-shutdown.php --print-pubkey   which key is this nsec?
 *   php scripts/emergency-shutdown.php --rehearse       practise the whole run with a
 *                                                       throwaway key; never uploads
 *
 * Options for the interactive run:
 *   --manifest-url URL   live manifest to start from and --check (default: the official one)
 *   --out FILE           where to write the new manifest (default: ./version.json.new)
 *   --upload DEST        scp destination for the manifest (e.g. user@host:path/version.json);
 *                        also read from CASHUPAY_MANIFEST_SCP
 *
 * The signing key never appears on the command line: it is read from
 * CASHUPAY_SAFE_MODE_SECKEY or a hidden prompt (nsec… or 64 hex), or you sign with an
 * external Nostr signer (nak, Amber via a bunker:// URL) and paste the result.
 * Whatever signs, the tool refuses a notice that installs would not accept.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

$root = dirname(__DIR__);
require_once $root . '/cashu-wallet-php/CashuWallet.php';
define('CASHUPAY_DATA_DIR', sys_get_temp_dir());

// Rehearsal: a throwaway key that only this process trusts, so the whole procedure can
// be practised end to end without the real keys and without touching the live manifest.
$rehearse = in_array('--rehearse', $argv, true);
if ($rehearse) {
    $rehearsalKey = bin2hex(random_bytes(32));
    define('CASHUPAY_SAFE_MODE_PUBKEYS', [pubkeyOf($rehearsalKey)]);
    putenv('CASHUPAY_SAFE_MODE_SECKEY=' . $rehearsalKey);
}
require_once $root . '/includes/config.php';
require_once $root . '/includes/safe_mode.php';

use Cashu\Secp256k1;
use Cashu\BigInt;

const DEFAULT_MANIFEST_URL = 'https://cashupayserver.org/version.json';

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "\nERROR: $s\n"); exit(1); }

function ask(string $question, ?string $default = null): string {
    $suffix = $default !== null && $default !== '' ? " [$default]" : '';
    fwrite(STDOUT, "$question$suffix: ");
    $line = fgets(STDIN);
    if ($line === false) {
        fail('No input.');
    }
    $line = trim($line);
    return $line === '' ? (string)$default : $line;
}

function askHidden(string $question): string {
    fwrite(STDOUT, "$question: ");
    $tty = stream_isatty(STDIN);
    if ($tty) {
        system('stty -echo');
    }
    $line = trim((string)fgets(STDIN));
    if ($tty) {
        system('stty echo');
    }
    out();
    return $line;
}

function isVersion(string $v): bool {
    return (bool)preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]{1,20})?$/', $v);
}

/** Minimal bech32 decode for an nsec (a typo shows up as a key that is not pinned). */
function nsecToHex(string $nsec): string {
    $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $nsec = strtolower(trim($nsec));
    $pos = strrpos($nsec, '1');
    if ($pos === false || substr($nsec, 0, $pos) !== 'nsec') {
        fail('Not an nsec key.');
    }
    $acc = 0; $bits = 0; $raw = '';
    foreach (str_split(substr($nsec, $pos + 1, -6)) as $c) {
        $v = strpos($charset, $c);
        if ($v === false) {
            fail('Invalid character in the nsec.');
        }
        $acc = (($acc << 5) | $v) & 0xffffff;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $raw .= chr(($acc >> $bits) & 0xff);
        }
    }
    if (strlen($raw) !== 32) {
        fail('The nsec does not decode to 32 bytes.');
    }
    return bin2hex($raw);
}

function secretKey(): string {
    $key = getenv('CASHUPAY_SAFE_MODE_SECKEY') ?: askHidden('Signing key (nsec… or 64 hex; input hidden)');
    if (str_starts_with(strtolower($key), 'nsec1')) {
        $key = nsecToHex($key);
    }
    if (!preg_match('/^[0-9a-fA-F]{64}$/', $key)) {
        fail('The signing key must be an nsec or 64 hex characters.');
    }
    return strtolower($key);
}

function pubkeyOf(string $secHex): string {
    $point = Secp256k1::scalarMult(BigInt::fromHex($secHex), Secp256k1::getGenerator());
    return substr(bin2hex(Secp256k1::compressPoint($point)), 2);
}

function fetchManifest(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "Cache-Control: no-cache\r\n"]]);
    $body = @file_get_contents($url . (str_contains($url, '?') ? '&' : '?') . 'nocache=' . time(), false, $ctx);
    if ($body === false) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** Describe what the manifest's notice does, as installs would see it. */
function describe(array $manifest): void {
    out('  latest version : ' . ($manifest['version'] ?? '(none)'));
    if (!isset($manifest['safe_mode'])) {
        out('  emergency notice: none. Installs run normally.');
        return;
    }
    $notice = is_array($manifest['safe_mode']) ? SafeMode::verify($manifest['safe_mode']) : null;
    if ($notice === null) {
        out('  emergency notice: PRESENT BUT INVALID (bad signature, unknown key, or malformed) — installs IGNORE it.');
        return;
    }
    out('  emergency notice: VALID, signed by ' . $manifest['safe_mode']['pubkey']);
    out('    id       : ' . $notice['id']);
    out('    signed at: ' . gmdate('Y-m-d H:i', $notice['created_at']) . ' UTC');
    out('    reason   : ' . $notice['reason']);
    out('    link     : ' . ($notice['url'] ?? '(none)'));
    foreach ($notice['ranges'] as $r) {
        out('    shuts down versions ' . ($r['from'] ?? 'any') . ' <= v < ' . ($r['fixed'] ?? '(no fixed version yet — every newer version too!)'));
    }
    foreach (['0.5.4-alpha', CASHUPAY_VERSION] as $v) {
        out("    {$v}: " . (SafeMode::matchingRange($notice['ranges'], $v) ? 'SHUT DOWN' : 'runs'));
    }
}

function writeManifest(array $manifest, string $path): void {
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (strlen($json) > 16384) {
        fail('The manifest would exceed the 16 KiB installs accept. Shorten the notes or reason.');
    }
    if (file_put_contents($path, $json) === false) {
        fail("Could not write $path");
    }
    out("Wrote $path");
}

function upload(string $path, ?string $dest, string $manifestUrl): void {
    if ($dest === null || $dest === '') {
        out();
        out('Upload it yourself, replacing the live file:');
        out("  scp " . escapeshellarg($path) . " USER@HOST:PATH/version.json");
        out("Then check it: php scripts/emergency-shutdown.php --check");
        return;
    }
    if (strtolower(ask("Upload to $dest now? (yes/no)", 'no')) !== 'yes') {
        out("Not uploaded. When ready: scp " . escapeshellarg($path) . ' ' . escapeshellarg($dest));
        return;
    }
    passthru('scp ' . escapeshellarg($path) . ' ' . escapeshellarg($dest), $rc);
    if ($rc !== 0) {
        fail("scp failed ($rc). The file is still at $path.");
    }
    out('Uploaded. The live manifest now reads:');
    $live = fetchManifest($manifestUrl);
    $live === null ? out('  (could not fetch it back — check by hand)') : describe($live);
}

// --- arguments --------------------------------------------------------------------

$opts = ['manifest-url' => DEFAULT_MANIFEST_URL, 'out' => 'version.json.new',
         'upload' => getenv('CASHUPAY_MANIFEST_SCP') ?: null];
$mode = 'create';
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--rehearse') {
        continue;
    } elseif (in_array($a, ['--check', '--lift', '--print-pubkey'], true)) {
        $mode = substr($a, 2);
    } elseif (in_array($a, ['--manifest-url', '--out', '--upload'], true)) {
        $opts[substr($a, 2)] = $args[++$i] ?? fail("$a needs a value");
    } elseif ($a === '--help' || $a === '-h') {
        $src = file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $src, $m);
        out(preg_replace('/^ ?\* ?/m', '', $m[1]));
        exit(0);
    } else {
        fail("Unknown option $a (see --help)");
    }
}

$pinned = SafeMode::pubkeys();
if ($rehearse) {
    $opts['out'] = 'version.json.rehearsal';
    $opts['upload'] = null;
    out('*** REHEARSAL: throwaway key, output ' . $opts['out'] . ', nothing is uploaded. ***');
    out('*** Real installs would ignore this notice. ***');
    out();
}

if ($mode === 'print-pubkey') {
    $pk = pubkeyOf(secretKey());
    out($pk . (in_array($pk, $pinned, true) ? '  (pinned: installs accept it)' : '  (NOT pinned: installs ignore it)'));
    exit(0);
}

out('CashuPayServer emergency shutdown');
out('=================================');
out('Live manifest: ' . $opts['manifest-url']);
$live = fetchManifest($opts['manifest-url']);
if ($live === null) {
    out('  Could not fetch it. You can still continue; the new manifest will contain only');
    out('  what you enter below plus the notice.');
    $live = [];
} else {
    describe($live);
}
out();

if ($mode === 'check') {
    exit(0);
}

if ($mode === 'lift') {
    if (!isset($live['safe_mode'])) {
        out('There is no notice to lift.');
        exit(0);
    }
    out('Lifting stops NEW shutdowns. Installs already shut down stay down until their');
    out('operators upgrade (or delete data/' . SafeMode::FLAG_FILE . ').');
    if (ask('Type LIFT to write a manifest without the notice') !== 'LIFT') {
        fail('Cancelled.');
    }
    unset($live['safe_mode']);
    writeManifest($live, $opts['out']);
    upload($opts['out'], $opts['upload'], $opts['manifest-url']);
    exit(0);
}

// --- create -----------------------------------------------------------------------

out('Every install whose version is in the range below will stop serving ANYTHING');
out('(checkout, API, admin) until its operator upgrades. They hear about it within');
out('about six hours. Use it only when a hole lets strangers take operators\' money.');
out();

$from = ask('First affected version (empty = every older version too)', '');
if ($from !== '' && !isVersion($from)) {
    fail("'$from' is not a version like 0.5.0-alpha.");
}
out('Fixed version: installs upgraded to it or later start again by themselves.');
out('If there is no fix yet, leave it empty — but then EVERY version from the first');
out('affected one on is shut down, including releases you publish later, until you');
out('issue a new notice that names the fixed version.');
$fixed = ask('Fixed version', '');
if ($fixed !== '' && !isVersion($fixed)) {
    fail("'$fixed' is not a version like 0.5.5-alpha.");
}
if ($from === '' && $fixed === '') {
    fail('Name at least the first affected or the fixed version.');
}
if ($from !== '' && $fixed !== '' && version_compare($from, $fixed, '>=')) {
    fail('The fixed version must be newer than the first affected one.');
}

out('Reason: one plain sentence a shop owner understands. It is shown on every');
out('affected install and is public.');
$reason = ask('Reason');
if ($reason === '') {
    fail('A reason is required.');
}
$defaultUrl = $fixed !== ''
    ? "https://github.com/jooray/cashupayserver/releases/tag/v$fixed"
    : 'https://github.com/jooray/cashupayserver/releases';
$url = ask('Upgrade link (github.com/jooray/cashupayserver/… or cashupayserver.org)', $defaultUrl);

$content = ['affected' => [array_filter(['from' => $from ?: null, 'fixed' => $fixed ?: null])],
            'reason' => $reason, 'url' => $url];
$contentJson = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

out();
out('Summary');
out('  shuts down : ' . ($from ?: 'any') . ' <= version < ' . ($fixed ?: 'NO FIXED VERSION (all newer too)'));
out('  0.5.4-alpha: ' . (SafeMode::matchingRange($content['affected'], '0.5.4-alpha') ? 'SHUT DOWN' : 'runs'));
out('  ' . CASHUPAY_VERSION . ' (this checkout): ' . (SafeMode::matchingRange($content['affected'], CASHUPAY_VERSION) ? 'SHUT DOWN' : 'runs'));
out('  reason     : ' . $reason);
out('  link       : ' . $url);
out();
if (ask('Type SHUTDOWN to sign this notice') !== 'SHUTDOWN') {
    fail('Cancelled. Nothing was signed or written.');
}

out();
out('How do you want to sign?');
out('  1) paste an nsec or hex key here (hidden, never stored)');
out('  2) sign with nak / Amber and paste the signed event back');
$how = ask('Choose 1 or 2', '1');

if ($how === '2') {
    $q = fn(string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";
    out();
    out('Run this (use your nsec, or a bunker:// URL from Amber, after --sec):');
    out();
    out('  nak event --sec <nsec-or-bunker-url> -k ' . SafeMode::EVENT_KIND
        . ' -t d=' . SafeMode::EVENT_D_TAG . ' -c ' . $q($contentJson) . ' </dev/null');
    out();
    out('Paste the JSON it prints, then press Enter on an empty line:');
    $pasted = '';
    while (($line = fgets(STDIN)) !== false && trim($line) !== '') {
        $pasted .= $line;
    }
    $event = json_decode($pasted, true);
    if (!is_array($event)) {
        fail('That was not JSON.');
    }
    if (($event['content'] ?? null) !== $contentJson) {
        fail('The signed content differs from what you confirmed above. Not publishing it.');
    }
} else {
    $sec = secretKey();
    $event = [
        'pubkey' => pubkeyOf($sec),
        'created_at' => time(),
        'kind' => SafeMode::EVENT_KIND,
        'tags' => [['d', SafeMode::EVENT_D_TAG]],
        'content' => $contentJson,
    ];
    $event['id'] = SafeMode::eventId($event);
    $event['sig'] = Secp256k1::schnorrSign($sec, hex2bin($event['id']));
    unset($sec);
}

if (!in_array(strtolower((string)($event['pubkey'] ?? '')), $pinned, true)) {
    fail('Signed by ' . ($event['pubkey'] ?? '?') . ", which installs do not trust.\nPinned keys: " . implode(', ', $pinned));
}
if (SafeMode::verify($event) === null) {
    fail('The signed event does not verify. Not publishing it.');
}
$event = ['id' => $event['id'], 'pubkey' => $event['pubkey'], 'created_at' => $event['created_at'],
          'kind' => $event['kind'], 'tags' => $event['tags'], 'content' => $event['content'], 'sig' => $event['sig']];
out('Signature OK (key ' . $event['pubkey'] . ').');

$live['safe_mode'] = $event;
out();
writeManifest($live, $opts['out']);
out('Check before uploading:');
describe($live);
upload($opts['out'], $opts['upload'], $opts['manifest-url']);

out();
out('Next steps (docs/EMERGENCY-SHUTDOWN.md):');
out('  - Installs on affected versions shut down at their next update check (within ~6 h).');
out('  - Publish the fixed release and the advisory; operators are sent to the link above.');
out('  - Optional, for transparency: publish the same event to Nostr relays,');
out('    e.g. echo \'<event json>\' | nak event <relay urls>');
