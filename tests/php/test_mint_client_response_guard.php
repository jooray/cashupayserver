<?php
/**
 * Theme 3 (submodule): MintClient::request must not treat a malformed or
 * error-carrying 2xx as a successful empty result. A mint that signed our
 * mint/swap but returned a truncated body, or returned HTTP 200 with a NUT-00
 * error envelope, would otherwise be read as "zero signatures / not paid" and
 * silently lose funds. Both cases must raise CashuProtocolException; a genuine
 * JSON 2xx must still decode normally.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/cashu-wallet-php/CashuWallet.php';

use Cashu\MintClient;
use Cashu\CashuProtocolException;

// Collision-free port: bind :0, read the assigned port, release it.
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$name = stream_socket_get_name($probe, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($probe);

$serverDir = sys_get_temp_dir() . '/mint_stub_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);

// Mock mint: route by the last path segment to a canned response shape.
$router = <<<'PHP'
<?php
$path = $_SERVER['REQUEST_URI'] ?? '/';
header('Content-Type: application/json');
if (str_contains($path, 'ok')) {
    echo json_encode(['signatures' => []]);
} elseif (str_contains($path, 'malformed')) {
    echo 'this is not json{{{';            // 200 + undecodable body
} elseif (str_contains($path, 'error200')) {
    echo json_encode(['detail' => 'quote already issued', 'code' => 20002]); // 200 + error envelope
} else {
    http_response_code(404);
    echo json_encode(['detail' => 'not found', 'code' => 404]);
}
PHP;
file_put_contents($serverDir . '/router.php', $router);

$pid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
));
register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });
$up = false;
for ($i = 0; $i < 120; $i++) { // up to ~6s; the suite can be busy
    $h = @fopen("http://127.0.0.1:$port/v1/ping-ok", 'r');
    if ($h) { fclose($h); $up = true; break; }
    usleep(50000);
}
if (!$up) { fail("mint stub failed to start on port $port"); }

$client = new MintClient("http://127.0.0.1:$port");

// Valid JSON 2xx still decodes.
$ok = $client->get('thing-ok');
assert_true(is_array($ok) && array_key_exists('signatures', $ok), 'valid 2xx JSON decodes to array');

// Malformed 2xx body must raise, not return [].
$threw = false;
try { $client->get('thing-malformed'); }
catch (CashuProtocolException $e) { $threw = true; }
assert_true($threw, 'malformed 2xx body raises CashuProtocolException');

// 200 carrying an error envelope must raise.
$threw = false;
try { $client->get('thing-error200'); }
catch (CashuProtocolException $e) { $threw = true; }
assert_true($threw, '200-with-error-envelope raises CashuProtocolException');

fwrite(STDERR, "test_mint_client_response_guard: all assertions passed\n");
