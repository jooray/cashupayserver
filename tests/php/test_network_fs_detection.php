<?php
/**
 * Database::detectNetworkMount / networkFilesystemWarning and the dashboard
 * dismissal in Background: the data directory's OWN mount decides (longest
 * prefix, path-boundary aware), only true network / shared-folder types
 * trigger, Windows recognises UNC paths, and the WAL refusal path is a
 * separate signal. A healthy local install reports WAL and no warning.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/background.php';

/** Write $table to a fresh temp file (one per call — tests hold several at once). */
function with_mounts(string $table): string {
    $f = tempnam(sys_get_temp_dir(), 'mounts');
    file_put_contents($f, $table);
    register_shutdown_function(static function () use ($f) { @unlink($f); });
    return $f;
}

// --- healthy local install --------------------------------------------------
assert_eq('wal', Database::journalMode(), 'fresh database runs in WAL mode');
assert_null(Database::networkFilesystemWarning(), 'local tmp data dir: no warning');

// --- Linux mount table ------------------------------------------------------
$local = with_mounts("/dev/sda1 / ext4 rw,relatime 0 0\noverlay /var/lib/docker/overlay2/abc/merged overlay rw 0 0\ntmpfs /tmp tmpfs rw 0 0\n");
assert_null(Database::detectNetworkMount('/var/www/data', $local, 'Linux'), 'ext4 root: no hit');
assert_null(Database::detectNetworkMount('/var/lib/docker/overlay2/abc/merged/data', $local, 'Linux'), 'docker overlay: no hit');
assert_null(Database::detectNetworkMount('/tmp/x', $local, 'Linux'), 'tmpfs: no hit');

$nfs = with_mounts(
    "/dev/sda1 / ext4 rw 0 0\n"
    . "nas:/export /mnt/nas nfs4 rw,vers=4.2 0 0\n"
    . "//nas/share /mnt/smb cifs rw 0 0\n"
    . "user@host:/srv /mnt/ssh fuse.sshfs rw 0 0\n"
    . "shared /media/sf_data vboxsf rw 0 0\n"
    . "/dev/sdb1 /mnt/nas/local-again ext4 rw 0 0\n"
    . "nas:/other /mnt/with\\040space nfs rw 0 0\n"
    . "/dev/sdc1 /mnt/nas2 ext4 rw 0 0\n"
);
$hit = Database::detectNetworkMount('/mnt/nas/app/data', $nfs, 'Linux');
assert_eq(['fsType' => 'nfs4', 'mountPoint' => '/mnt/nas'], $hit, 'nfs4 mount detected with its mount point');
assert_eq('cifs', Database::detectNetworkMount('/mnt/smb', $nfs, 'Linux')['fsType'], 'cifs detected at the mount point itself');
assert_eq('fuse.sshfs', Database::detectNetworkMount('/mnt/ssh/d', $nfs, 'Linux')['fsType'], 'sshfs detected');
assert_eq('vboxsf', Database::detectNetworkMount('/media/sf_data/cashupay', $nfs, 'Linux')['fsType'], 'VirtualBox shared folder detected');
assert_null(
    Database::detectNetworkMount('/mnt/nas/local-again/data', $nfs, 'Linux'),
    'a local filesystem mounted INSIDE the share wins (longest prefix)'
);
assert_null(
    Database::detectNetworkMount('/mnt/nas2/data', $nfs, 'Linux'),
    '/mnt/nas2 is not under /mnt/nas (path-boundary match, not string prefix)'
);
assert_eq(
    '/mnt/with space',
    Database::detectNetworkMount('/mnt/with space/data', $nfs, 'Linux')['mountPoint'],
    'octal-escaped mount point decoded'
);
assert_null(Database::detectNetworkMount('/mnt/nas/app/data', '/nonexistent/mounts', 'Linux'), 'unreadable mount table: no guess');
assert_null(Database::detectNetworkMount('/mnt/nas/app/data', with_mounts(''), 'Linux'), 'empty mount table: no guess');

// --- the env seam picks the table for the test rigs -------------------------
putenv('CASHUPAY_MOUNTS_FILE=' . $nfs);
assert_eq('nfs4', Database::detectNetworkMount('/mnt/nas/x', null, 'Linux')['fsType'], 'CASHUPAY_MOUNTS_FILE honoured');
putenv('CASHUPAY_MOUNTS_FILE');

// --- Windows: UNC only -------------------------------------------------------
assert_eq('UNC share', Database::detectNetworkMount('\\\\fileserver\\payments\\data', null, 'Windows')['fsType'], 'UNC path detected');
assert_eq('UNC share', Database::detectNetworkMount('//fileserver/payments/data', null, 'Windows')['fsType'], 'forward-slash UNC detected');
assert_null(Database::detectNetworkMount('C:\\barebits\\data', null, 'Windows'), 'drive letter: no guess');
assert_null(Database::detectNetworkMount('Z:\\data', null, 'Windows'), 'mapped drive letter: documented false negative');

// --- other platforms: no mount table, no guess -------------------------------
assert_null(Database::detectNetworkMount('/Volumes/nas/data', $nfs, 'Darwin'), 'macOS: no detection');

// --- end to end on the real data dir via the env seam ----------------------
// Describe the test data dir's parent as an NFS mount and the warning fires
// with the evidence; dismissing it in Background hides it for THIS evidence
// only.
$parent = dirname(realpath($dir));
putenv('CASHUPAY_MOUNTS_FILE=' . with_mounts("/dev/sda1 / ext4 rw 0 0\nnas:/e {$parent} nfs4 rw 0 0\n"));
$w = Database::networkFilesystemWarning();
assert_not_null($w, 'warning fires for a data dir on a described nfs4 mount');
assert_eq('mount', $w['reason']);
assert_eq('nfs4', $w['fsType']);
assert_eq($parent, $w['mountPoint']);
assert_eq('wal', $w['journalMode'], 'journal mode reported alongside');
assert_not_null(Background::networkFsWarning(), 'dashboard state present before dismissal');

Background::dismissNetworkFsWarning();
assert_null(Background::networkFsWarning(), 'dismissed for this mount');
assert_not_null(Database::networkFilesystemWarning(), 'raw detection unaffected by dismissal');

// Same directory, different evidence (the share got re-exported over SMB):
// the dismissal no longer matches and the warning returns.
putenv('CASHUPAY_MOUNTS_FILE=' . with_mounts("/dev/sda1 / ext4 rw 0 0\n//nas/e {$parent} cifs rw 0 0\n"));
$w2 = Background::networkFsWarning();
assert_not_null($w2, 'different evidence re-arms the warning');
assert_eq('cifs', $w2['fsType']);

// Back on local disk: nothing to show and no stale dismissal side effects.
putenv('CASHUPAY_MOUNTS_FILE=' . $local);
assert_null(Background::networkFsWarning(), 'local disk again: quiet');
putenv('CASHUPAY_MOUNTS_FILE');

// --- the constant silences everything ---------------------------------------
putenv('CASHUPAY_MOUNTS_FILE=' . with_mounts("nas:/e {$parent} nfs4 rw 0 0\n"));
assert_not_null(Database::networkFilesystemWarning(), 'precondition: warning would fire');
define('CASHUPAY_IGNORE_NETWORK_FS', true);
assert_null(Database::networkFilesystemWarning(), 'CASHUPAY_IGNORE_NETWORK_FS silences the warning');
putenv('CASHUPAY_MOUNTS_FILE');

echo "PASS test_network_fs_detection\n";
exit(0);
