<?php
/**
 * Detection for the Windows desktop package.
 *
 * The desktop distribution (built by scripts/build-windows-desktop.sh) wraps
 * the standalone app in a launcher that runs PHP's built-in web server on
 * 127.0.0.1 and ticks cron-runner.php on a timer, so two onboarding screens
 * stop making sense there:
 *
 *   - cron: background jobs already run automatically; a crontab line is
 *     noise on a system that has no cron.
 *   - security: the server only listens on loopback and router.php already
 *     403s the data directory; walking a desktop user through Apache/nginx
 *     hardening only confuses them.
 *
 * Detection is two-layered. The launcher (BareBits.bat) sets
 * CASHUPAY_DESKTOP=1, which is explicit and works on any OS — that also makes
 * it the test hook. As a fallback, the package's on-disk shape is recognised
 * directly, so a merchant who starts php -S by hand from inside the package
 * (skipping the .bat) gets the same wizard.
 */

final class Desktop {

    /** Is this request served by the Windows desktop package? */
    public static function isWindowsDesktop(): bool {
        // Same env conventions as the updater's kill switches: non-empty and
        // not "0" counts as set.
        $env = getenv('CASHUPAY_DESKTOP');
        if ($env !== false && $env !== '' && $env !== '0') {
            return true;
        }
        return self::looksLikeDesktopLayout(PHP_OS_FAMILY, PHP_SAPI, dirname(__DIR__));
    }

    /**
     * Fallback: recognise the desktop package by its layout — the app lives
     * in <package>/app with the launcher's helper scripts one level up (see
     * the staging tree in scripts/build-windows-desktop.sh). Only trusted on
     * Windows under the built-in web server: that combination plus the helper
     * files can only be the desktop package, whereas e.g. a Linux checkout
     * with a stray sibling file must never flip the wizard's shape.
     *
     * Parameterised on what is environmental (OS, SAPI, app root) so the
     * decision itself is testable from any host.
     */
    public static function looksLikeDesktopLayout(
        string $osFamily, string $sapi, string $appRoot
    ): bool {
        if ($osFamily !== 'Windows' || $sapi !== 'cli-server') {
            return false;
        }
        $packageRoot = dirname($appRoot);
        return is_file($packageRoot . '/cron-runner.php')
            && is_file($packageRoot . '/desktop-helper.php');
    }
}
