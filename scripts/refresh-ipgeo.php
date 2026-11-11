<?php
/**
 * scripts/refresh-ipgeo.php
 *
 * Standalone refresher for the DB-IP IP-to-Country Lite database. Drop
 * this into a monthly cron if you don't already run cron.php (it's also
 * called automatically from cron.php's task 12a).
 *
 *   php scripts/refresh-ipgeo.php
 *
 * Exits 0 on success, 1 on failure.
 */

require_once __DIR__ . '/../app/includes/ipgeo.php';

$ok = IpGeo::refresh();
$path = IpGeo::getCsvPath();
fwrite(STDOUT, ($ok ? 'OK ' : 'FAIL ') . $path . PHP_EOL);
exit($ok ? 0 : 1);
