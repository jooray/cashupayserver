<?php
/**
 * CashuPayServer - Alerts outside the dashboard
 *
 * Every problem the dashboard explains used to be visible only to an operator who opened
 * it: a security release, checkout failing, automatic withdrawals failing. Operators who
 * live in WooCommerce and look at CashuPay once a month heard about them from customers
 * (A12, 2026-10-28). This collects the problems worth interrupting someone for and sends
 * each one at most once a day, by email and/or a private Nostr message (NIP-17).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/update_check.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Secp256k1;
use Cashu\BigInt;

class Alerts {
    /** The same problem is sent again at most this often. */
    const REPEAT_AFTER = 86400;
    /** Relays the private messages go to (NIP-17 inbox relays are not looked up). */
    const RELAYS = ['wss://relay.damus.io', 'wss://nos.lol', 'wss://relay.primal.net', 'wss://auth.nostr1.com'];

    /**
     * Problems worth telling the operator about now, each with a stable key (a new key
     * means a new problem) and a plain sentence.
     *
     * @return array<int, array{key: string, text: string}>
     */
    public static function problems(): array {
        $problems = [];

        try {
            $update = UpdateCheck::status();
            if (($update['outdated'] ?? false) && (($update['severity'] ?? '') === 'security' || ($update['unsupported'] ?? false))) {
                $problems[] = ['key' => 'update:' . $update['latest'],
                    'text' => 'A security update of CashuPayServer (v' . $update['latest'] . ') is available. '
                        . 'Please upgrade soon; the dashboard says how.'];
            }
        } catch (Throwable $e) {
        }

        foreach (Database::fetchAll('SELECT id, name, mint_url FROM stores WHERE mint_url IS NOT NULL') as $store) {
            $f = Config::get('checkout_failures_' . $store['id']);
            if (is_array($f) && time() - (int)($f['last'] ?? 0) < 7200 && (int)($f['count'] ?? 0) >= 2) {
                $why = [
                    'mint' => 'the payment service (' . $store['mint_url'] . ') is not answering',
                    'prices' => 'the Bitcoin price cannot be confirmed by two price sources',
                    'setting_up' => 'payments are still being set up with the payment service',
                ][$f['reason']] ?? 'of an error';
                $problems[] = ['key' => 'checkout:' . $store['id'] . ':' . (int)$f['first'],
                    'text' => 'Customers of "' . $store['name'] . '" cannot pay with Bitcoin right now, because ' . $why
                        . ' (' . (int)$f['count'] . ' failed attempts since ' . gmdate('Y-m-d H:i', (int)$f['first']) . ' UTC).'];
            }
        }

        $stale = self::staleBackground();
        if ($stale !== null) {
            $problems[] = $stale;
        }

        try {
            require_once __DIR__ . '/background_runner.php';
            foreach (BackgroundRunner::status()['failing'] ?? [] as $task) {
                $since = (int)($task['since'] ?? time());
                $urgent = !empty($task['paymentPath']) ? 3600 : ($task['task'] === 'auto_melt' ? 86400 : null);
                if ($urgent !== null && time() - $since > $urgent) {
                    $problems[] = ['key' => 'task:' . $task['task'] . ':' . $since,
                        'text' => $task['name'] . ' has been failing since ' . gmdate('Y-m-d H:i', $since) . ' UTC. '
                            . $task['impact']];
                }
            }
        } catch (Throwable $e) {
        }

        $exposure = Config::get('data_exposure_check');
        if (is_array($exposure) && !empty($exposure['warning'])) {
            $problems[] = ['key' => 'exposure', 'text' => 'Your wallet database can be downloaded from the internet. '
                . 'Open the dashboard: it says how to fix this.'];
        }

        // Cancelled rows (the shop was reconnected or the webhook changed) are not owed.
        require_once __DIR__ . '/webhook_sender.php';
        $failed = WebhookSender::countUndelivered(null, 5);
        if ($failed > 0) {
            $problems[] = ['key' => 'webhooks:' . intdiv(time(), 86400),
                'text' => 'Your shop has not been told about ' . $failed . ' payment(s), so those orders may still show '
                    . 'as unpaid in your shop. Check that the shop is still connected to CashuPayServer.'];
        }

        return $problems;
    }

    /** Grace after setup before "background tasks have never run" is a problem. */
    const NEVER_RAN_GRACE = 900;

    /**
     * Background work that has stopped, or never started.
     *
     * Common on cheap hosts (WP-Cron disabled, loopback requests blocked), and nothing
     * else says so: email and Nostr alerts are sent by the very runner that stopped, so
     * this reaches the operator through the dashboard and the wp-admin notice (run()
     * leaves it out: a running runner is the proof it is not stopped).
     *
     * Background work is started by traffic on most installs, so a quiet shop goes
     * stale with nothing wrong. A stale heartbeat is reported only when work has been
     * waiting for a run longer than the stale window: an invoice or a shop notification
     * created after the last run that is still open.
     *
     * @return ?array{key: string, text: string}
     */
    public static function staleBackground(?int $now = null): ?array {
        $now = $now ?? time();
        if (!Config::isSetupComplete()) {
            return null;
        }
        require_once __DIR__ . '/health.php';
        require_once __DIR__ . '/webhook_sender.php';
        $lastRun = (int)Config::get('cron_last_run', '0');
        $impact = 'Until they run again, paid orders are not being marked paid, your shop is not told about '
            . 'payments, and automatic withdrawals are paused. Open the dashboard: it says how to get them running.';
        if ($lastRun === 0) {
            $setupAt = self::setupCompletedAt();
            if ($setupAt === null || $now - $setupAt <= self::NEVER_RAN_GRACE) {
                return null;
            }
            return ['key' => 'stale:never:' . intdiv($now, 86400),
                'text' => 'The background tasks of CashuPayServer have never run since setup. ' . $impact];
        }
        $cutoff = $now - Health::HEARTBEAT_STALE_SECONDS;
        if ($lastRun >= $cutoff) {
            return null;
        }
        $waiting = Database::fetchOne(
            "SELECT 1 AS hit FROM invoices
              WHERE status IN ('New', 'Processing') AND created_at > ? AND created_at < ? LIMIT 1",
            [$lastRun, $cutoff]
        ) ?? Database::fetchOne(
            "SELECT 1 AS hit FROM webhook_deliveries d
              WHERE " . WebhookSender::OWED_SQL . " AND d.created_at > ? AND d.created_at < ? LIMIT 1",
            [$lastRun, $cutoff]
        );
        if ($waiting === null) {
            return null;
        }
        return ['key' => 'stale:' . intdiv($now, 86400),
            'text' => 'The background tasks of CashuPayServer have not run since ' . gmdate('Y-m-d H:i', $lastRun)
                . ' UTC. ' . $impact];
    }

    /** When setup was finished, from the config row it wrote (null when unknown). */
    private static function setupCompletedAt(): ?int {
        $row = Database::fetchOne("SELECT updated_at FROM config WHERE key = 'setup_complete'");
        $at = $row !== null ? (int)$row['updated_at'] : 0;
        return $at > 0 ? $at : null;
    }

    /** Whether any channel is configured. */
    public static function configured(): bool {
        return (string)Config::get('alert_email', '') !== '' || (string)Config::get('alert_npub', '') !== '';
    }

    /** Background task: send new problems, each at most once per REPEAT_AFTER. */
    public static function run(): string {
        if (!self::configured()) {
            return 'not configured';
        }
        $sent = Config::get('alerts_sent');
        $sent = is_array($sent) ? $sent : [];
        $now = time();
        $new = [];
        foreach (self::problems() as $p) {
            if (str_starts_with($p['key'], 'stale:')) {
                continue; // this very run shows the background is running again
            }
            if ($now - (int)($sent[$p['key']] ?? 0) >= self::REPEAT_AFTER) {
                $new[] = $p;
            }
        }
        // Forget keys older than a week so the record stays small.
        $sent = array_filter($sent, fn($t) => $now - (int)$t < 7 * 86400);
        if ($new === []) {
            Config::set('alerts_sent', $sent);
            return 'nothing new';
        }
        $body = "CashuPayServer at " . self::siteName() . " needs your attention:\n\n"
            . implode("\n\n", array_map(fn($p) => '- ' . $p['text'], $new))
            . "\n\nOpen your CashuPayServer dashboard for details.";
        $result = self::deliver('CashuPayServer needs your attention', $body);
        if ($result['sent'] > 0) {
            foreach ($new as $p) {
                $sent[$p['key']] = $now;
            }
        }
        Config::set('alerts_sent', $sent);
        if ($result['sent'] === 0) {
            // Shown on the dashboard as a failing task, so the operator learns the
            // channel does not work.
            throw new RuntimeException('could not send alerts: ' . implode('; ', $result['errors']));
        }
        return 'sent ' . count($new) . ' alert(s) via ' . implode(', ', $result['channels']);
    }

    /** Tests replace delivery so nothing leaves the machine. */
    public static $deliverOverride = null;

    /** @return array{sent: int, channels: string[], errors: string[]} */
    public static function deliver(string $subject, string $body): array {
        if (is_callable(self::$deliverOverride)) {
            return (self::$deliverOverride)($subject, $body);
        }
        $out = ['sent' => 0, 'channels' => [], 'errors' => []];
        $email = trim((string)Config::get('alert_email', ''));
        if ($email !== '') {
            if (self::sendEmail($email, $subject, $body)) {
                $out['sent']++;
                $out['channels'][] = 'email';
            } else {
                $out['errors'][] = 'the server could not send email';
            }
        }
        $npub = trim((string)Config::get('alert_npub', ''));
        if ($npub !== '') {
            try {
                $relays = self::sendNostrDm($npub, $subject . "\n\n" . $body);
                $out['sent']++;
                $out['channels'][] = 'Nostr (' . $relays . ' relays)';
            } catch (Throwable $e) {
                $out['errors'][] = 'Nostr: ' . $e->getMessage();
            }
        }
        return $out;
    }

    private static function siteName(): string {
        $host = parse_url((string)Config::get('base_url', ''), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'your website';
    }

    public static function sendEmail(string $to, string $subject, string $body): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
            return false;
        }
        $host = self::siteName() !== 'your website' ? self::siteName() : 'localhost';
        $headers = "From: CashuPayServer <noreply@{$host}>\r\nContent-Type: text/plain; charset=utf-8";
        return @mail($to, $subject, $body, $headers);
    }

    // ---------------------------------------------------------------------------------
    // Nostr: NIP-17 private direct message (kind 14 rumor, sealed in kind 13, gift-wrapped
    // in kind 1059), encrypted with NIP-44 v2. The server signs with its own key.
    // ---------------------------------------------------------------------------------

    /** The server's own Nostr key (hex), created on first use. */
    public static function serverKey(): string {
        $key = (string)Config::get('alert_nostr_key', '');
        if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
            $key = bin2hex(random_bytes(32));
            Config::set('alert_nostr_key', $key);
        }
        return $key;
    }

    public static function serverNpub(): string {
        return self::bech32Encode('npub', hex2bin(self::xOnlyPubkey(self::serverKey())));
    }

    /** @return int number of relays that accepted the message */
    public static function sendNostrDm(string $npub, string $text): int {
        $wrap = self::giftWrap(self::npubToHex($npub), $text);
        $accepted = 0;
        $errors = [];
        foreach (self::RELAYS as $relay) {
            try {
                if (self::publish($relay, $wrap)) {
                    $accepted++;
                }
            } catch (Throwable $e) {
                $errors[] = parse_url($relay, PHP_URL_HOST) . ': ' . $e->getMessage();
            }
        }
        if ($accepted === 0) {
            throw new RuntimeException('no relay accepted the message' . ($errors ? ' (' . implode('; ', $errors) . ')' : ''));
        }
        return $accepted;
    }

    /** The kind 1059 gift wrap of a kind 14 message from this server to $recipient (hex). */
    public static function giftWrap(string $recipient, string $text): array {
        $senderKey = self::serverKey();
        $senderPub = self::xOnlyPubkey($senderKey);
        $now = time();
        $rumor = ['pubkey' => $senderPub, 'created_at' => $now, 'kind' => 14,
            'tags' => [['p', $recipient]], 'content' => $text];
        $rumor['id'] = self::eventId($rumor);
        $seal = self::signEvent($senderKey, ['created_at' => $now - random_int(0, 172800), 'kind' => 13, 'tags' => [],
            'content' => self::nip44Encrypt($senderKey, $recipient, json_encode($rumor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        $wrapKey = bin2hex(random_bytes(32));
        $wrap = self::signEvent($wrapKey, ['created_at' => $now - random_int(0, 172800), 'kind' => 1059,
            'tags' => [['p', $recipient]],
            'content' => self::nip44Encrypt($wrapKey, $recipient, json_encode($seal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        return $wrap;
    }

    private static function xOnlyPubkey(string $privHex): string {
        $point = Secp256k1::scalarMult(BigInt::fromHex($privHex), Secp256k1::getGenerator());
        return substr(bin2hex(Secp256k1::compressPoint($point)), 2);
    }

    private static function eventId(array $e): string {
        return hash('sha256', json_encode([0, $e['pubkey'], $e['created_at'], $e['kind'], $e['tags'], $e['content']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function signEvent(string $privHex, array $e): array {
        $e['pubkey'] = self::xOnlyPubkey($privHex);
        $e['id'] = self::eventId($e);
        $e['sig'] = Secp256k1::schnorrSign($privHex, hex2bin($e['id']));
        return ['id' => $e['id'], 'pubkey' => $e['pubkey'], 'created_at' => $e['created_at'], 'kind' => $e['kind'],
            'tags' => $e['tags'], 'content' => $e['content'], 'sig' => $e['sig']];
    }

    /** NIP-44 v2 conversation key: HKDF-extract(salt "nip44-v2", x of privA * pubB). */
    private static function conversationKey(string $privHex, string $pubXHex): string {
        $point = Secp256k1::scalarMult(BigInt::fromHex($privHex), Secp256k1::decompressPointHex('02' . $pubXHex));
        $shared = substr(Secp256k1::compressPoint($point), 1);
        return hash_hmac('sha256', $shared, 'nip44-v2', true);
    }

    public static function nip44Encrypt(string $privHex, string $pubXHex, string $plaintext): string {
        $nonce = random_bytes(32);
        return self::nip44EncryptWithNonce(self::conversationKey($privHex, $pubXHex), $plaintext, $nonce);
    }

    /** Public for the test vectors. */
    public static function nip44EncryptWithNonce(string $conversationKey, string $plaintext, string $nonce): string {
        // HKDF-expand only (the conversation key is already the pseudorandom key);
        // hash_hkdf() would run the extract step again.
        $keys = '';
        $block = '';
        for ($i = 1; strlen($keys) < 76; $i++) {
            $block = hash_hmac('sha256', $block . $nonce . chr($i), $conversationKey, true);
            $keys .= $block;
        }
        $chachaKey = substr($keys, 0, 32);
        $chachaNonce = substr($keys, 32, 12);
        $hmacKey = substr($keys, 44, 32);
        $padded = self::nip44Pad($plaintext);
        // OpenSSL's chacha20 takes a 16-byte IV: 4-byte little-endian counter, then the nonce.
        $ciphertext = openssl_encrypt($padded, 'chacha20', $chachaKey, OPENSSL_RAW_DATA, "\0\0\0\0" . $chachaNonce);
        if ($ciphertext === false) {
            throw new RuntimeException('encryption is not available on this server');
        }
        $mac = hash_hmac('sha256', $nonce . $ciphertext, $hmacKey, true);
        return base64_encode("\x02" . $nonce . $ciphertext . $mac);
    }

    private static function nip44Pad(string $plaintext): string {
        $len = strlen($plaintext);
        if ($len < 1 || $len > 65535) {
            throw new RuntimeException('message length out of range');
        }
        if ($len <= 32) {
            $padded = 32;
        } else {
            $nextPower = 1 << (int)(floor(log($len - 1, 2)) + 1);
            $chunk = $nextPower <= 256 ? 32 : intdiv($nextPower, 8);
            $padded = $chunk * (intdiv($len - 1, $chunk) + 1);
        }
        return pack('n', $len) . $plaintext . str_repeat("\0", $padded - $len);
    }

    // --- bech32 (npub) ---------------------------------------------------------------

    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    public static function npubToHex(string $npub): string {
        $npub = strtolower(trim($npub));
        if (preg_match('/^[0-9a-f]{64}$/', $npub)) {
            return $npub;
        }
        if (!preg_match('/^npub1[' . self::CHARSET . ']{6,}$/', $npub)) {
            throw new InvalidArgumentException('This does not look like a Nostr public key (npub1…).');
        }
        $data = [];
        foreach (str_split(substr($npub, 5)) as $c) {
            $data[] = strpos(self::CHARSET, $c);
        }
        if (self::polymod(array_merge(self::hrpExpand('npub'), $data)) !== 1) {
            throw new InvalidArgumentException('This npub has a typo (its checksum does not match).');
        }
        $bytes = self::convertBits(array_slice($data, 0, -6), 5, 8, false);
        if ($bytes === null || count($bytes) !== 32) {
            throw new InvalidArgumentException('This npub is not a valid public key.');
        }
        return bin2hex(implode('', array_map('chr', $bytes)));
    }

    private static function bech32Encode(string $hrp, string $bytes): string {
        $data = self::convertBits(array_values(unpack('C*', $bytes)), 8, 5, true);
        $values = array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0]);
        $mod = self::polymod($values) ^ 1;
        for ($i = 0; $i < 6; $i++) {
            $data[] = ($mod >> (5 * (5 - $i))) & 31;
        }
        return $hrp . '1' . implode('', array_map(fn($d) => self::CHARSET[$d], $data));
    }

    private static function hrpExpand(string $hrp): array {
        $out = [];
        foreach (str_split($hrp) as $c) { $out[] = ord($c) >> 5; }
        $out[] = 0;
        foreach (str_split($hrp) as $c) { $out[] = ord($c) & 31; }
        return $out;
    }

    private static function polymod(array $values): int {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($b >> $i) & 1) { $chk ^= $gen[$i]; }
            }
        }
        return $chk;
    }

    private static function convertBits(array $data, int $from, int $to, bool $pad): ?array {
        $acc = 0; $bits = 0; $out = []; $maxv = (1 << $to) - 1;
        foreach ($data as $v) {
            $acc = ($acc << $from) | $v;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $out[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits > 0) {
            $out[] = ($acc << ($to - $bits)) & $maxv;
        } elseif (!$pad && ($bits >= $from || (($acc << ($to - $bits)) & $maxv))) {
            return null;
        }
        return $out;
    }

    // --- a minimal WebSocket client, enough to publish one event ------------------------

    private static function publish(string $relay, array $event): bool {
        $parts = parse_url($relay);
        $host = (string)($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'wss' || $host === '') {
            throw new RuntimeException('not a wss:// relay');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("cannot connect ({$errstr})");
        }
        stream_set_timeout($sock, 6);
        try {
            $key = base64_encode(random_bytes(16));
            fwrite($sock, "GET " . ($parts['path'] ?? '/') . " HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\n"
                . "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
            // Bounded in size per line and in time overall: stream_set_timeout() applies
            // per read, so a relay trickling bytes could otherwise hold the run forever,
            // and fgets() without a length reads an endless line whole.
            $head = '';
            $handshakeDeadline = microtime(true) + 15;
            while (!str_contains($head, "\r\n\r\n") && !feof($sock) && strlen($head) < 8192) {
                if (microtime(true) > $handshakeDeadline) {
                    throw new RuntimeException('relay did not answer in time');
                }
                $line = fgets($sock, 8193 - strlen($head));
                if ($line === false) { break; }
                $head .= $line;
            }
            if (!preg_match('#^HTTP/1\.1 101#', $head)) {
                throw new RuntimeException('relay refused the connection');
            }
            self::wsSend($sock, json_encode(['EVENT', $event], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $deadline = microtime(true) + 6;
            while (microtime(true) < $deadline) {
                $msg = self::wsReceive($sock);
                if ($msg === null) { break; }
                $data = json_decode($msg, true);
                if (is_array($data) && ($data[0] ?? '') === 'OK' && ($data[1] ?? '') === $event['id']) {
                    return (bool)($data[2] ?? false);
                }
            }
            return false;
        } finally {
            @fclose($sock);
        }
    }

    private static function wsSend($sock, string $payload): void {
        $len = strlen($payload);
        $frame = "\x81";
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($sock, $frame . $mask . $masked);
    }

    private static function wsReceive($sock): ?string {
        $head = fread($sock, 2);
        if ($head === false || strlen($head) < 2) {
            return null;
        }
        $len = ord($head[1]) & 0x7f;
        if ($len === 126) {
            $len = unpack('n', fread($sock, 2))[1];
        } elseif ($len === 127) {
            $len = unpack('J', fread($sock, 8))[1];
        }
        if ($len > 65536) {
            return null;
        }
        $data = '';
        while (strlen($data) < $len && !feof($sock)) {
            $chunk = fread($sock, $len - strlen($data));
            if ($chunk === false || $chunk === '') { break; }
            $data .= $chunk;
        }
        return $data;
    }
}
