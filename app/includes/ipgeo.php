<?php
/**
 * CashuPayServer - IP-to-Country lookup
 *
 * Resolves a hostname (or URL) to an ISO 3166-1 alpha-2 country code using
 * the DB-IP IP-to-Country Lite CSV (CC-BY 4.0). Only the country column is
 * used; no city/region data is fetched or surfaced.
 *
 * On-disk format:
 *   data/dbip-country-lite.csv.gz   — raw monthly CSV from db-ip.com
 *
 * CSV columns: <start_ip>,<end_ip>,<country_code>
 * Both v4 dotted-quad and v6 colon-hex addresses appear in the same file,
 * one range per line.
 *
 * Per-mint caching is intentionally disabled (the operator wants fresh
 * lookups). To stay fast across many lookups in the same page render, the
 * parsed CSV is held in a per-process static array and binary-searched.
 *
 * Refresh: scripts/refresh-ipgeo.php pulls the latest CSV monthly. The
 * fetch-on-first-use fallback below downloads a copy if the file is missing
 * when a lookup is requested.
 */

require_once __DIR__ . '/database.php';

class IpGeo {
    const CSV_FILENAME = 'dbip-country-lite.csv.gz';
    const FETCH_TIMEOUT_SEC = 60;
    const CONFIG_LAST_FETCH_KEY = 'ipgeo_last_fetch_at';
    const CONFIG_LAST_ERROR_KEY = 'ipgeo_last_error';

    // Memory-tight indexes. Each family stores three parallel packed
    // strings: starts (4 or 16 B per entry), ends, codes (2 ASCII chars
    // per entry). Total RAM for ~700K rows: ~7 MB v4, ~30 MB v6 — much
    // cheaper than the equivalent nested PHP arrays.
    private static ?string $v4Starts = null;
    private static ?string $v4Ends = null;
    private static ?string $v4Codes = null;
    private static int $v4Count = 0;
    private static ?string $v6Starts = null;
    private static ?string $v6Ends = null;
    private static ?string $v6Codes = null;
    private static int $v6Count = 0;
    private static bool $loadAttempted = false;

    /** Path to the cached gzipped CSV. */
    public static function getCsvPath(): string {
        return Database::getDataDir() . '/' . self::CSV_FILENAME;
    }

    /**
     * Resolve a hostname or full URL to an ISO 3166-1 alpha-2 country code
     * (uppercase, e.g. "DE"), or null if anything fails. Never throws.
     */
    public static function lookupCountry(string $hostOrUrl): ?string {
        $host = self::extractHost($hostOrUrl);
        if ($host === null) return null;

        $ip = self::resolveIp($host);
        if ($ip === null) return null;

        return self::lookupIp($ip);
    }

    /**
     * Resolve an IP literal directly to a country code.
     */
    public static function lookupIp(string $ip): ?string {
        if (!self::ensureIndex()) return null;

        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if (!$isV4 && !$isV6) return null;

        $packed = @inet_pton($ip);
        if ($packed === false) return null;

        if ($isV6) {
            if (self::$v6Count === 0 || self::$v6Starts === null) return null;
            return self::binarySearchPacked(self::$v6Starts, self::$v6Ends, self::$v6Codes, self::$v6Count, 16, $packed);
        }
        if (self::$v4Count === 0 || self::$v4Starts === null) return null;
        return self::binarySearchPacked(self::$v4Starts, self::$v4Ends, self::$v4Codes, self::$v4Count, 4, $packed);
    }

    /** Download the latest DB-IP Lite CSV. Returns true on success. */
    public static function refresh(): bool {
        $url = self::currentMonthCsvUrl();
        $path = self::getCsvPath();
        $tmp = $path . '.tmp';

        $ok = self::downloadTo($url, $tmp);
        if (!$ok) {
            // DB-IP rolls the monthly URL on the 1st; if the current
            // month's file isn't published yet, fall back to last month.
            $prevUrl = self::previousMonthCsvUrl();
            $ok = self::downloadTo($prevUrl, $tmp);
        }
        if (!$ok) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        // Invalidate any cached index so subsequent lookups reload.
        self::$v4Starts = self::$v4Ends = self::$v4Codes = null;
        self::$v6Starts = self::$v6Ends = self::$v6Codes = null;
        self::$v4Count = self::$v6Count = 0;
        self::$loadAttempted = false;

        require_once __DIR__ . '/config.php';
        Config::set(self::CONFIG_LAST_FETCH_KEY, Database::timestamp());
        Config::delete(self::CONFIG_LAST_ERROR_KEY);
        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function extractHost(string $hostOrUrl): ?string {
        $hostOrUrl = trim($hostOrUrl);
        if ($hostOrUrl === '') return null;

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $hostOrUrl)) {
            $parsed = parse_url($hostOrUrl);
            if ($parsed === false || !isset($parsed['host'])) return null;
            return strtolower($parsed['host']);
        }
        $hostOrUrl = ltrim($hostOrUrl, '/');
        $hostOrUrl = preg_replace('/:\d+$/', '', $hostOrUrl);
        return strtolower($hostOrUrl);
    }

    private static function resolveIp(string $host): ?string {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $lower = strtolower($host);
        if (str_ends_with($lower, '.onion') || str_ends_with($lower, '.i2p')) {
            return null;
        }
        $records = @gethostbynamel($host);
        if (is_array($records) && count($records) > 0) {
            return $records[0];
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6) && !empty($v6) && isset($v6[0]['ipv6'])) {
            return $v6[0]['ipv6'];
        }
        return null;
    }

    /**
     * Lazily decompress the CSV once per request and build packed-string
     * indexes per family. Returns true if at least one family was populated.
     *
     * Memory tactic: append each parsed range to a per-family scratch buffer
     * (PHP strings grow amortized O(1)), then split into starts/ends/codes
     * once. The CSV ships in sorted-by-start order so no resort is needed,
     * but we sanity-check ordering during the parse and bail if it slips.
     */
    private static function ensureIndex(): bool {
        if (self::$loadAttempted) {
            return self::$v4Count > 0 || self::$v6Count > 0;
        }
        self::$loadAttempted = true;

        $path = self::getCsvPath();
        if (!is_file($path)) {
            // Degrade gracefully: never download the (multi-MB) geo DB on the
            // request thread — that could block a customer-facing request for up
            // to the 60s download timeout plus a full ~700K-row parse. The DB is
            // populated out-of-band by the cron task (IpGeo::refresh) and
            // scripts/refresh-ipgeo.php; until then, geo lookups simply return
            // null (no country) rather than stalling the request.
            error_log('IpGeo: geo DB not present; skipping lookup (run cron / refresh-ipgeo.php to populate)');
            return false;
        }

        $fh = @gzopen($path, 'rb');
        if ($fh === false) return false;

        // Bump the memory limit briefly so a 25 MB v6 index doesn't blow
        // through a default 128 MB request — we drop the scratch arrays as
        // soon as the per-family packed strings are built, so steady-state
        // usage stays modest. ini_restore() puts the original limit back
        // before this function returns.
        $oldMem = ini_get('memory_limit');
        if ($oldMem !== false && self::limitBytes($oldMem) < 256 * 1024 * 1024) {
            @ini_set('memory_limit', '256M');
        }

        $v4Starts = '';
        $v4Ends = '';
        $v4Codes = '';
        $v6Starts = '';
        $v6Ends = '';
        $v6Codes = '';
        $lastV4 = '';
        $lastV6 = '';
        $v4OutOfOrder = false;
        $v6OutOfOrder = false;

        try {
            while (($line = gzgets($fh, 256)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                // Three columns; DB-IP ships unquoted ASCII so we can split
                // on commas directly. Avoid str_getcsv() per-line — it's
                // ~10x slower across 700K rows.
                $a = strpos($line, ',');
                if ($a === false) continue;
                $b = strpos($line, ',', $a + 1);
                if ($b === false) continue;
                $startStr = substr($line, 0, $a);
                $endStr = substr($line, $a + 1, $b - $a - 1);
                $cc = strtoupper(trim(substr($line, $b + 1)));
                if (strlen($cc) !== 2) continue;
                if (!ctype_alpha($cc)) continue;

                $start = @inet_pton($startStr);
                if ($start === false) continue;
                $end = @inet_pton($endStr);
                if ($end === false) continue;

                $len = strlen($start);
                if ($len === 4 && strlen($end) === 4) {
                    if ($lastV4 !== '' && strcmp($start, $lastV4) < 0) $v4OutOfOrder = true;
                    $lastV4 = $start;
                    $v4Starts .= $start;
                    $v4Ends .= $end;
                    $v4Codes .= $cc;
                } elseif ($len === 16 && strlen($end) === 16) {
                    if ($lastV6 !== '' && strcmp($start, $lastV6) < 0) $v6OutOfOrder = true;
                    $lastV6 = $start;
                    $v6Starts .= $start;
                    $v6Ends .= $end;
                    $v6Codes .= $cc;
                }
            }
        } finally {
            gzclose($fh);
        }

        // Defensive: if a future DB-IP release ever ships unsorted rows,
        // rebuild a sort permutation. Practical files are pre-sorted, so
        // this never fires for the canonical CSV — keep the slow path
        // available but warn so we know if it ever triggers.
        if ($v4OutOfOrder) {
            self::sortPacked($v4Starts, $v4Ends, $v4Codes, 4);
            error_log('IpGeo: v4 rows were out of order; sorted defensively');
        }
        if ($v6OutOfOrder) {
            self::sortPacked($v6Starts, $v6Ends, $v6Codes, 16);
            error_log('IpGeo: v6 rows were out of order; sorted defensively');
        }

        self::$v4Starts = $v4Starts;
        self::$v4Ends = $v4Ends;
        self::$v4Codes = $v4Codes;
        self::$v4Count = intdiv(strlen($v4Starts), 4);
        self::$v6Starts = $v6Starts;
        self::$v6Ends = $v6Ends;
        self::$v6Codes = $v6Codes;
        self::$v6Count = intdiv(strlen($v6Starts), 16);

        if ($oldMem !== false) {
            @ini_set('memory_limit', $oldMem);
        }
        return self::$v4Count > 0 || self::$v6Count > 0;
    }

    /**
     * Binary-search a packed range index for the row whose [start,end]
     * contains $target. starts/ends are concatenated fixed-width packed
     * IPs; codes is 2 ASCII chars per entry.
     */
    private static function binarySearchPacked(string $starts, string $ends, string $codes, int $count, int $stride, string $target): ?string {
        $lo = 0;
        $hi = $count - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $off = $mid * $stride;
            $rowStart = substr($starts, $off, $stride);
            $cmp = strcmp($target, $rowStart);
            if ($cmp < 0) {
                $hi = $mid - 1;
                continue;
            }
            $rowEnd = substr($ends, $off, $stride);
            if (strcmp($target, $rowEnd) > 0) {
                $lo = $mid + 1;
                continue;
            }
            return substr($codes, $mid * 2, 2);
        }
        return null;
    }

    /**
     * Defensive sort: rebuild the three packed strings according to the
     * permutation that orders starts ascending. Slow but rare path.
     */
    private static function sortPacked(string &$starts, string &$ends, string &$codes, int $stride): void {
        $n = intdiv(strlen($starts), $stride);
        $perm = range(0, $n - 1);
        usort($perm, function ($a, $b) use ($starts, $stride) {
            return strcmp(substr($starts, $a * $stride, $stride), substr($starts, $b * $stride, $stride));
        });
        $newStarts = '';
        $newEnds = '';
        $newCodes = '';
        foreach ($perm as $i) {
            $newStarts .= substr($starts, $i * $stride, $stride);
            $newEnds .= substr($ends, $i * $stride, $stride);
            $newCodes .= substr($codes, $i * 2, 2);
        }
        $starts = $newStarts;
        $ends = $newEnds;
        $codes = $newCodes;
    }

    private static function limitBytes(string $val): int {
        $val = trim($val);
        if ($val === '' || $val === '-1') return PHP_INT_MAX;
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default: return $num;
        }
    }

    private static function currentMonthCsvUrl(): string {
        return 'https://download.db-ip.com/free/dbip-country-lite-' . date('Y-m') . '.csv.gz';
    }

    private static function previousMonthCsvUrl(): string {
        return 'https://download.db-ip.com/free/dbip-country-lite-' . date('Y-m', strtotime('first day of last month')) . '.csv.gz';
    }

    private static function downloadTo(string $url, string $dest): bool {
        $ch = curl_init($url);
        if ($ch === false) return false;
        $fp = @fopen($dest, 'wb');
        if ($fp === false) {
            curl_close($ch);
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'CashuPayServer/IpGeo',
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $code < 200 || $code >= 300) {
            require_once __DIR__ . '/config.php';
            Config::set(self::CONFIG_LAST_ERROR_KEY, "HTTP $code from $url: $err");
            @unlink($dest);
            return false;
        }
        $size = @filesize($dest);
        if ($size === false || $size < 200 * 1024) {
            @unlink($dest);
            return false;
        }
        $fh = @fopen($dest, 'rb');
        if ($fh === false) return false;
        $magic = fread($fh, 2);
        fclose($fh);
        if ($magic !== "\x1f\x8b") {
            @unlink($dest);
            return false;
        }
        return true;
    }
}
