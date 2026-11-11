<?php
/**
 * Helpers for PHP tests that need a REAL HTTP round trip against the server
 * (setup.php / admin.php gates emit redirects and sessions that the CLI SAPI
 * cannot observe: headers_list() is empty there and sessions cannot start
 * after output). Spawns `php -S` with router.php on a free loopback port,
 * pointed at the test's CASHUPAY_DATA_DIR via the same wrapper trick as
 * tests/fixtures/payserver.py, and talks to it with curl + a cookie jar.
 *
 * Not a test itself (run.php only globs test_*.php).
 */
declare(strict_types=1);

final class HttpDriver {
    public int $port;
    public string $base;
    /** @var resource|null */
    private $proc;
    private string $cookieJar;
    private string $wrapper;
    /** @var array<string,string> extra request headers (e.g. Host override) */
    public array $headers = [];

    public static function start(string $dataDir, array $env = []): self {
        $d = new self();
        $repo = dirname(__DIR__, 2) . '/app';
        $d->port = self::freePort();
        $d->base = 'http://127.0.0.1:' . $d->port;
        $d->cookieJar = $dataDir . '/driver-cookies-' . bin2hex(random_bytes(4)) . '.txt';
        $d->wrapper = $dataDir . '/driver-router-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($d->wrapper, '<?php'
            . "\n\$dd = getenv('CASHUPAY_DATA_DIR');"
            . "\nif (\$dd !== false && \$dd !== '' && !defined('CASHUPAY_DATA_DIR')) { define('CASHUPAY_DATA_DIR', \$dd); }"
            . "\nreturn require " . var_export($repo . '/router.php', true) . ";\n");
        $envFull = array_merge([
            'CASHUPAY_DATA_DIR' => $dataDir,
            'CASHUPAY_UPDATER_DISABLED' => '1',
            'CASHUPAY_DEV_FEE_BRANCH_ENDPOINT' => 'disabled',
            'CASHUPAY_USAGE_STATS_ENDPOINT' => 'disabled',
            'CASHUPAY_ALLOW_PRIVATE_ENDPOINTS' => '1',
            'PATH' => (string)getenv('PATH'),
        ], $env);
        // `exec` so the php -S process replaces the sh -c wrapper: proc_terminate()
        // signals the pid proc_open returns, and without exec that is the shell,
        // leaving the server orphaned when a test fails.
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $d->port . ' -t ' . escapeshellarg($repo)
            . ' ' . escapeshellarg($d->wrapper);
        $d->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $dataDir . '/driver-server.log', 'a'], 2 => ['file', $dataDir . '/driver-server.log', 'a']], $pipes, $repo, $envFull);
        if (!is_resource($d->proc)) {
            throw new \RuntimeException('could not start php -S');
        }
        // Wait until it answers.
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $s = @fsockopen('127.0.0.1', $d->port, $errno, $errstr, 0.2);
            if ($s) {
                fclose($s);
                return $d;
            }
            usleep(100000);
        }
        $d->stop();
        throw new \RuntimeException('php -S did not come up on port ' . $d->port);
    }

    public function stop(): void {
        if (is_resource($this->proc)) {
            $st = proc_get_status($this->proc);
            if (!empty($st['running'])) {
                proc_terminate($this->proc, 15);
                $deadline = microtime(true) + 3;
                while (microtime(true) < $deadline) {
                    $st = proc_get_status($this->proc);
                    if (empty($st['running'])) break;
                    usleep(50000);
                }
                if (!empty($st['running'])) proc_terminate($this->proc, 9);
            }
            proc_close($this->proc);
            $this->proc = null;
        }
        @unlink($this->wrapper);
    }

    public function __destruct() {
        $this->stop();
    }

    /** Forget the session (new cookie jar). Returns the previous jar so a
     *  test can switch back with switchSession(). */
    public function newSession(): string {
        $old = $this->cookieJar;
        $this->cookieJar = dirname($this->cookieJar) . '/driver-cookies-' . bin2hex(random_bytes(4)) . '.txt';
        return $old;
    }

    /** Continue with a cookie jar returned by newSession(). */
    public function switchSession(string $jar): string {
        $old = $this->cookieJar;
        $this->cookieJar = $jar;
        return $old;
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function request(string $method, string $path, array $post = [], array $headers = []): array {
        $ch = curl_init($this->base . $path);
        $hdrs = [];
        foreach (array_merge($this->headers, $headers) as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_HEADERFUNCTION => function ($_c, string $h) use (&$respHeaders) {
                $pos = strpos($h, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($h, 0, $pos)))] = trim(substr($h, $pos + 1));
                }
                return strlen($h);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            throw new \RuntimeException('request failed: ' . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string)$body];
    }

    public function get(string $path, array $headers = []): array {
        return $this->request('GET', $path, [], $headers);
    }

    public function post(string $path, array $post, array $headers = []): array {
        return $this->request('POST', $path, $post, $headers);
    }

    /** The wizard's csrf_token hidden field from a rendered screen. */
    public static function wizardCsrf(string $body): ?string {
        return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m) ? html_entity_decode($m[1]) : null;
    }

    /** The slug of the screen a wizard body rendered (its step hidden field). */
    public static function wizardStep(string $body): ?string {
        return preg_match('/name="step" value="([a-z_]+)"/', $body, $m) ? $m[1] : null;
    }

    public static function freePort(): int {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("cannot allocate port: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int)substr($name, strrpos($name, ':') + 1);
    }
}

/**
 * A tiny request sink: `php -S` serving one script that appends every
 * request's method, path and headers (JSON, one per line) to a log file.
 * Lets a test assert that a request did — or did NOT — reach a listener.
 */
final class RequestSink {
    public int $port;
    public string $base;
    public string $log;
    /** @var resource|null */
    private $proc;
    private string $dir;

    public static function start(string $dataDir, int $status = 200, string $body = 'sink', ?string $location = null): self {
        $s = new self();
        $s->port = HttpDriver::freePort();
        $s->base = 'http://127.0.0.1:' . $s->port;
        $s->dir = $dataDir . '/sink-' . bin2hex(random_bytes(4));
        mkdir($s->dir, 0750, true);
        $s->log = $s->dir . '/requests.log';
        $locationLine = $location === null ? '' : "\nheader('Location: ' . " . var_export($location, true) . ");";
        file_put_contents($s->dir . '/index.php', '<?php'
            . "\n\$h = []; foreach (\$_SERVER as \$k => \$v) { if (strpos(\$k, 'HTTP_') === 0) \$h[\$k] = \$v; }"
            . "\nfile_put_contents(" . var_export($s->log, true) . ", json_encode(['method' => \$_SERVER['REQUEST_METHOD'], 'uri' => \$_SERVER['REQUEST_URI'], 'headers' => \$h]) . \"\\n\", FILE_APPEND | LOCK_EX);"
            . $locationLine
            . "\nhttp_response_code(" . (int)$status . "); echo " . var_export($body, true) . ";\n");
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $s->port . ' -t ' . escapeshellarg($s->dir) . ' ' . escapeshellarg($s->dir . '/index.php');
        $s->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $s->dir . '/server.log', 'a'], 2 => ['file', $s->dir . '/server.log', 'a']], $pipes, $s->dir);
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $s->port, $errno, $errstr, 0.2);
            if ($c) { fclose($c); return $s; }
            usleep(100000);
        }
        throw new \RuntimeException('sink did not come up');
    }

    /** @return array<int,array> */
    public function requests(): array {
        if (!is_file($this->log)) return [];
        $out = [];
        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $out[] = json_decode($line, true);
        }
        return $out;
    }

    public function stop(): void {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc, 15);
            usleep(100000);
            proc_terminate($this->proc, 9);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    public function __destruct() {
        $this->stop();
    }
}
