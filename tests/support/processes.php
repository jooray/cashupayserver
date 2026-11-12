<?php
/**
 * Helpers for tests that start `php -S` servers (mock mints, the app under test).
 *
 * Two things used to make the suite flaky:
 *  - Ports were picked with random_int() from a fixed range. If anything already listened
 *    there (another app, or a server leaked by an earlier run), the new `php -S` failed to
 *    bind, but the readiness probe (fsockopen) still connected - to the foreign server.
 *    The test then talked to the wrong process ("Invalid JSON response from mint").
 *  - With PHP_CLI_SERVER_WORKERS set, `php -S` forks workers. proc_terminate() only
 *    signals the master, so the workers were orphaned and kept the port forever.
 *
 * testFreePort() asks the kernel for a port nobody holds, and testStopProcess() takes the
 * whole process tree down.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

/** A TCP port on 127.0.0.1 that is free right now (the kernel picks it). */
function testFreePort(): int {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("Could not reserve a local port: $errstr");
    }
    $name = (string)stream_socket_get_name($server, false);
    fclose($server);
    $port = (int)substr($name, strrpos($name, ':') + 1);
    if ($port <= 0) {
        throw new RuntimeException("Could not read the reserved port from '$name'");
    }
    return $port;
}

/** PIDs of every descendant of $pid (children first, then their children). */
function testDescendantPids(int $pid): array {
    $out = [];
    @exec('pgrep -P ' . $pid . ' 2>/dev/null', $children);
    foreach ($children as $child) {
        $child = (int)trim($child);
        if ($child > 0) {
            $out[] = $child;
            array_push($out, ...testDescendantPids($child));
        }
    }
    return $out;
}

/**
 * Wait until a freshly started server accepts connections on $port. Fails fast if the
 * process exited instead (port taken, bad router path), rather than probing whatever
 * else might be listening there.
 *
 * @param resource $proc
 */
function testWaitForServer($proc, int $port, float $timeoutSeconds = 10.0): void {
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            throw new RuntimeException("Test server for port $port exited early (exit code {$status['exitcode']})");
        }
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock) {
            fclose($sock);
            return;
        }
        usleep(50000);
    }
    throw new RuntimeException("Test server on port $port did not start within {$timeoutSeconds}s");
}

/**
 * Stop a process started with proc_open() together with everything it forked
 * (php -S workers, or the php behind a `sh -c` wrapper).
 *
 * @param resource|null|false $proc
 */
function testStopProcess($proc): void {
    if (!is_resource($proc)) {
        return;
    }
    $status = @proc_get_status($proc);
    $pids = [];
    if ($status && $status['running'] && $status['pid'] > 0) {
        $pids = testDescendantPids((int)$status['pid']);
    }
    @proc_terminate($proc);
    foreach ($pids as $pid) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } else {
            @exec('kill -TERM ' . $pid . ' 2>/dev/null');
        }
    }
}
