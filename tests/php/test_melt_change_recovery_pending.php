<?php
/**
 * Melt result consumers treat a PAID melt whose change collection is still
 * pending (`changeRecoveryPending === true`, change `[]`) as SUCCESS: the
 * destination was paid; the library keeps the journal and cron collects the
 * change. None of the three sites may retry the withdrawal:
 *
 *   - LightningAddress::meltToBolt11 (manual BOLT-11 melt, fee melts via
 *     DevFee::payViaLnurl);
 *   - LightningAddress::meltToAddress (LNURL-pay withdrawals, auto-cashout);
 *   - SwapAutoMelt::meltAgainstInvoice (submarine-swap sweeps).
 *
 * The wallet is a Wallet subclass injected into Invoice's wallet cache whose
 * melt() answers exactly what the library returns in that situation (one
 * melt call is counted per site); an LNURL stub serves meltToAddress.
 * Also: every site selects inputs through selectProofsWithFees().
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lightning_address.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/auto_melt.php';

use Cashu\MeltQuote;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Wallet;

final class ChangePendingWallet extends Wallet {
    public int $meltCalls = 0;
    public int $selectCalls = 0;
    public array $lastInputs = [];
    public int $quoteAmount = 50;
    public int $feeReserve = 3;

    public function requestMeltQuote(string $bolt11): MeltQuote {
        return MeltQuote::fromArray([
            'quote' => 'mlq_pending_change', 'amount' => $this->quoteAmount, 'fee_reserve' => $this->feeReserve,
            'state' => 'UNPAID', 'expiry' => time() + 600, 'unit' => 'sat',
        ]);
    }

    public function calculateFee(array $proofs): int {
        return 0;
    }

    public function selectProofsWithFees(array $proofs, int $amount): array {
        $this->selectCalls++;
        return parent::selectProofsWithFees($proofs, $amount);
    }

    public function melt(string $quoteId, array $proofs): array {
        $this->meltCalls++;
        $this->lastInputs = $proofs;
        // What Wallet::melt() returns when the mint said PAID but the change
        // could not be unblinded/restored yet (journal kept for cron).
        return [
            'paid' => true,
            'pending' => false,
            'state' => 'PAID',
            'preimage' => str_repeat('ab', 32),
            'change' => [],
            'changeRecoveryPending' => true,
            'changeRecoveryError' => 'NUT-09 restore unavailable (stub)',
        ];
    }
}

$mintUrl = 'http://127.0.0.1:1'; // never contacted: the wallet is stubbed
make_store('s1', $mintUrl);
$ks = '00ad268c4d1f5826';
$C = '02' . str_repeat('33', 32);

$storage = StoreSafety::storage($mintUrl, 'sat');
$storage->storeProofs([
    new Proof($ks, 64, 'melt-a', $C), new Proof($ks, 32, 'melt-b', $C), new Proof($ks, 8, 'melt-c', $C),
]);

$wallet = new ChangePendingWallet($mintUrl, 'sat', Database::getDbPath());
$wallet->initFromMnemonic(Database::fetchOne("SELECT seed_phrase FROM stores WHERE id = 's1'")['seed_phrase']);
$cache = new ReflectionProperty(Invoice::class, 'walletCache');
$cache->setAccessible(true);
$cache->setValue(null, ['s1|' . $mintUrl . '|sat' => $wallet]);

function admin_log_count(): int {
    return (int)Database::fetchOne("SELECT COUNT(*) AS c FROM admin_event_log WHERE context = 'change_pending'")['c'];
}

// ---- meltToBolt11 -------------------------------------------------------------------------
$bolt11 = 'lnbc500n1p0stub' . str_repeat('q', 40); // amount-bearing (50 sat) HRP
$res = LightningAddress::meltToBolt11('s1', $bolt11, 50);
assert_true($res['success'], 'meltToBolt11: PAID with pending change is a success');
assert_eq(1, $wallet->meltCalls, 'meltToBolt11: exactly one melt, no retry');
assert_eq(1, $wallet->selectCalls, 'meltToBolt11 selects through selectProofsWithFees');
assert_true($res['changeRecoveryPending'], 'meltToBolt11 reports the pending change');
assert_eq(0, $res['changeAmount'], 'no change counted yet');
assert_eq(Wallet::sumProofs($wallet->lastInputs) - 50, $res['fee'], 'fee = inputs - amount while the change is outstanding (overstated, documented)');
assert_eq(1, admin_log_count(), 'change_pending noted in the admin log');

// ---- meltAgainstInvoice (sweep) -----------------------------------------------------------
$m = new ReflectionMethod(SwapAutoMelt::class, 'meltAgainstInvoice');
$m->setAccessible(true);
$res = $m->invoke(null, 's1', $bolt11, 50);
assert_true($res['paid'] && !$res['pending'], 'sweep melt: paid, not pending');
assert_true($res['changeRecoveryPending'], 'sweep melt reports the pending change');
assert_eq(2, $wallet->meltCalls, 'sweep melt: one melt call');
assert_eq(2, $wallet->selectCalls, 'sweep selects through selectProofsWithFees');
assert_eq(2, admin_log_count(), 'noted again');

// ---- meltToAddress (LNURL-pay) ------------------------------------------------------------
$lnDir = sys_get_temp_dir() . '/melt_lnurl_' . bin2hex(random_bytes(4));
mkdir($lnDir, 0750, true);
file_put_contents($lnDir . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$port = $_SERVER['SERVER_PORT'];
header('Content-Type: application/json');
if (str_starts_with($path, '/.well-known/lnurlp/')) {
    echo json_encode(['callback' => "http://127.0.0.1:$port/callback", 'minSendable' => 1000, 'maxSendable' => 100000000, 'tag' => 'payRequest', 'metadata' => '[]']);
    exit;
}
if ($path === '/callback') {
    $msat = (int)($_GET['amount'] ?? 0);
    $sats = intdiv($msat, 1000);
    echo json_encode(['pr' => 'lnbc' . ($sats * 10) . 'n1p0stub' . str_repeat('q', 40), 'routes' => []]);
    exit;
}
http_response_code(404);
echo '{}';
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$name = stream_socket_get_name($probe, false);
$lnPort = (int)substr($name, strrpos($name, ':') + 1);
fclose($probe);
$lnPid = (int)shell_exec(sprintf('%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $lnPort, escapeshellarg($lnDir), escapeshellarg($lnDir . '/router.php')));
register_shutdown_function(static function () use ($lnPid, $lnDir) {
    @posix_kill($lnPid, 9);
    @unlink($lnDir . '/router.php');
    @rmdir($lnDir);
});
$up = false;
for ($i = 0; $i < 100 && !$up; $i++) {
    $s = @fsockopen('127.0.0.1', $lnPort, $errno, $errstr, 0.2);
    if ($s) { fclose($s); $up = true; } else { usleep(50000); }
}
assert_true($up, 'lnurl stub up');
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$lnPort/.well-known/lnurlp/{user}");

$res = LightningAddress::meltToAddress('s1', 'shop@example.com', 50);
assert_true($res['success'], 'meltToAddress: PAID with pending change is a success');
assert_true($res['changeRecoveryPending'], 'meltToAddress reports the pending change');
assert_eq(3, $wallet->meltCalls, 'meltToAddress: one melt call, no retry');
assert_eq(3, $wallet->selectCalls, 'meltToAddress selects through selectProofsWithFees');
assert_eq(3, admin_log_count(), 'noted for the LNURL withdrawal too');
putenv('CASHU_LNURL_URL_TEMPLATE');

// The stub wallet never touched storage: the inputs are still UNSPENT here, which
// is irrelevant to the consumers (the real melt() reserves them before the POST).
assert_eq(ProofState::UNSPENT, $storage->getProofsStatesBySecrets(['melt-a'])['melt-a'], 'stub left storage alone');

echo "PASS test_melt_change_recovery_pending\n";
exit(0);
