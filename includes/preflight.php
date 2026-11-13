<?php
/**
 * CashuPayServer - Startup checks for the pages an operator opens (setup, admin, index).
 *
 * Common hosting problems used to stop PHP before any page was drawn, so the operator saw
 * a blank white page and had nothing to tell their host: PHP too old (the wallet library
 * needs 8.1 and fails to even parse on 7.x), a missing extension, a typo in
 * includes/config.local.php, or a data folder PHP cannot write to (A1, 2026-10-28).
 *
 * This file is deliberately written in old, plain PHP so it still runs where the rest of
 * the code cannot. It is included before anything else and either returns quietly or
 * draws one plain page that says what is wrong and gives a message to send to the host.
 */

require_once __DIR__ . '/preflight_page.php';

if (!function_exists('cashupay_preflight')) {
    function cashupay_preflight() {
        // WordPress shows its own startup notice and keeps the shop running.
        if (defined('ABSPATH') || defined('CASHUPAY_WORDPRESS') || PHP_SAPI === 'cli') {
            return;
        }
        // The WordPress plugin build reached directly, not through WordPress: refuse it
        // here, before any check below can name a server path to a stranger. The same
        // answer as entrypoint_guard.php gives.
        if (is_file(dirname(__DIR__) . '/cashupay.php') && is_file(dirname(__DIR__) . '/bootstrap.php')) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
            }
            exit("This file must be reached through WordPress.\n"
                . "Use the CashuPay menu in wp-admin, or the /cashupay/... routes.\n");
        }
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'my website';
        $root = dirname(__DIR__);

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            cashupay_preflight_page(
                'This website runs an older PHP version',
                'CashuPayServer needs PHP 8.1 or newer. This website runs PHP ' . htmlspecialchars(PHP_VERSION)
                . '. Many hosts let you choose the PHP version yourself in the control panel '
                . '(look for "Select PHP Version" or "PHP Settings"); if not, your host can switch it.',
                "Hello,\n\nplease switch my website " . $host . " to PHP 8.2 or newer (it currently runs PHP "
                . PHP_VERSION . "), with the extensions curl, pdo_sqlite, bcmath, mbstring and gmp enabled.\n\nThank you.",
                'PHP_VERSION=' . PHP_VERSION
            );
        }

        $needed = array('curl' => 'curl', 'json' => 'json', 'pdo_sqlite' => 'pdo_sqlite',
            'bcmath' => 'bcmath', 'mbstring' => 'mbstring');
        $missing = array();
        foreach ($needed as $ext => $name) {
            if (!extension_loaded($ext)) {
                $missing[] = $name;
            }
        }
        if ($missing) {
            $list = implode(', ', $missing);
            $alsoGmp = extension_loaded('gmp') ? '' : ' and gmp (recommended, it makes payments much faster)';
            cashupay_preflight_page(
                'A PHP extension is missing',
                'CashuPayServer needs these PHP extensions, which this website does not have yet: <strong>'
                . htmlspecialchars($list) . '</strong>. They are standard parts of PHP that your host can turn on.',
                "Hello,\n\nplease enable these PHP extensions for my website " . $host . " (PHP " . PHP_VERSION . "): "
                . $list . $alsoGmp . ".\n\nThank you.",
                'missing=' . $list
            );
        }

        // A typo in config.local.php (smart quotes, a missing semicolon) used to stop PHP
        // with nothing on the page. Load it here, where the mistake can be explained.
        // Fail closed: ignoring the file could silently open an empty wallet elsewhere.
        $configFile = __DIR__ . '/config.local.php';
        if (is_file($configFile)) {
            try {
                require_once $configFile;
            } catch (\Throwable $e) {
                cashupay_preflight_page(
                    'There is a mistake in includes/config.local.php',
                    'The settings file <code>includes/config.local.php</code> has a mistake on line '
                    . (int)$e->getLine() . ', so CashuPayServer cannot read it. This often happens when the '
                    . 'text was copied through a word processor, which turns straight quotes (\' and ") into '
                    . 'curly ones. Open the file in your hosting file manager\'s editor and retype the quotes '
                    . 'on that line, or delete the file if you do not need it.',
                    "Hello,\n\nthe file includes/config.local.php in my website " . $host . " has a PHP error on line "
                    . (int)$e->getLine() . ". Could you help me correct it?\n\nThank you.",
                    get_class($e) . ': ' . $e->getMessage()
                );
            }
        }

        // The data folder must be writable, or the first database write fails.
        if (defined('CASHUPAY_DATA_DIR')) {
            $dataDir = rtrim((string)CASHUPAY_DATA_DIR, '/');
        } else {
            $location = __DIR__ . '/data-location.txt';
            $moved = is_file($location) ? trim((string)@file_get_contents($location)) : '';
            $dataDir = ($moved !== '' && is_dir($moved)) ? $moved : $root . '/data';
        }
        $probe = is_dir($dataDir) ? $dataDir : dirname($dataDir);
        if (!is_dir($probe) || !is_writable($probe)) {
            $shown = strpos($dataDir, $root) === 0 ? ltrim(substr($dataDir, strlen($root)), '/') : $dataDir;
            cashupay_preflight_page(
                'CashuPayServer cannot save anything',
                'The folder <code>' . htmlspecialchars($shown) . '</code> cannot be written to by the website, so '
                . 'CashuPayServer has nowhere to keep its wallet. This usually means the folder\'s permissions '
                . 'were changed when the files were uploaded.',
                "Hello,\n\nplease make the folder " . $shown . " in my website " . $host . " writable by PHP "
                . "(the web server user), so the application installed there can store its data.\n\nThank you.",
                'not writable: ' . $dataDir
            );
        }
    }
}

cashupay_preflight();
