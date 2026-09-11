<?php
/**
 * BareBits plugin — WP-cron pinger for an alongside install.
 *
 * A BareBits server installed by this plugin declared BAREBITS_EXTERNAL_CRON
 * at install time: its setup wizard skipped the crontab screen because WE
 * promised to tick its cron endpoint. This file keeps that promise — an
 * every-minute WP-cron event fires an authenticated HTTP request to the
 * install's cron.php, which drives the server's full background task set
 * (webhook drain, on-chain polling, sweeps, its own auto-updater, …).
 *
 * Pure HTTP: the cron key travels in the X-CRON-KEY header so it stays out
 * of access logs, and no BareBits code runs inside WordPress. Servers this
 * plugin did not install run their own cron and get no pinger.
 *
 * The heartbeat is owed to the INSTALL, not to whatever server happens to be
 * connected: it keeps ticking the install's own URL through "Start over" and
 * after the install is reconnected in URL mode — the install has no crontab
 * of its own (the plugin promised it one at provision time), and it keeps
 * running with real money either way. License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('barebits_cron_tick', 'barebits_cron_tick');
add_action('init', 'barebits_cron_reschedule');
add_filter('cron_schedules', 'barebits_register_cron_interval');

// When a ping fails (host blocks self-requests, install broken), don't burn
// ~15s of every minute's wp-cron request timing out — back off to this
// cadence until a ping succeeds again.
const BAREBITS_CRON_BACKOFF_SECONDS = 600;

/**
 * Whether this site owes the alongside install a cron heartbeat: an install
 * record exists and the provisioning handshake handed us its cron key. NOT
 * gated on the current mode — the key is unrecoverable (the handshake is
 * one-time), and the install needs its heartbeat even mid-"Start over" or
 * after being reconnected by URL.
 */
function barebits_cron_needed(): bool {
    return barebits_install_url() !== ''
        && (string) get_option('barebits_cron_key', '') !== '';
}

function barebits_register_cron_interval(array $schedules): array {
    if (!isset($schedules['barebits_every_minute'])) {
        $schedules['barebits_every_minute'] = [
            'interval' => 60,
            'display' => 'Every minute',
        ];
    }
    return $schedules;
}

/**
 * Self-heal the schedule (WordPress's cron-array write race and cron-cleaner
 * plugins both lose events). wp_next_scheduled reads the already-loaded cron
 * option, so this is cheap enough for init. Also the activation hook's body.
 */
function barebits_cron_reschedule(): void {
    if (!barebits_cron_needed()) {
        return;
    }
    if (!wp_next_scheduled('barebits_cron_tick')) {
        // First run a minute out, not now: this is (re)scheduled in the same
        // request that just proved the endpoint synchronously (credential
        // collection), and an immediately-due event would pile a full cron
        // chain onto the merchant's very next page load — the onboarding
        // page itself — which on tight per-site worker pools (Local WP) is
        // the starvation pattern. Cron work has waited this long; one more
        // minute costs nothing.
        wp_schedule_event(time() + 60, 'barebits_every_minute', 'barebits_cron_tick');
    }
}

function barebits_cron_unschedule(): void {
    $timestamp = wp_next_scheduled('barebits_cron_tick');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'barebits_cron_tick');
    }
    delete_option('barebits_cron_backoff_until');
    delete_option('barebits_cron_last_ok');
}

/**
 * Fire one authenticated request at the install's cron endpoint. Returns
 * true when cron.php itself answered — it always returns JSON with a 'mode'
 * field (full / essentials / lock-bounce / ping alike); a 200 carrying
 * anything else means some other page answered and must read as failure.
 * Every success stamps barebits_cron_last_ok, which the wp-admin
 * stale-heartbeat warning (admin-menu.php) reads.
 *
 * $ping asks for cron.php's reachability-ping mode (?ping=1): routing and
 * key are proven but no tasks run. Interactive callers (onboarding's
 * synchronous heartbeat proof, with the merchant's page blocked on the
 * answer) use it so the install's first-ever full pass — updater check,
 * IP-geo download, mint syncs, minutes of worker time on a tight pool —
 * never runs inside their request. A server that predates the flag ignores
 * it and does a full run: same JSON shape, same success signal. The
 * scheduled tick keeps full runs — that IS the heartbeat.
 */
function barebits_fire_cron_endpoint(int $timeoutSeconds, bool $ping = false): bool {
    // Always the install's own URL — never the connected server, which may
    // be a different host entirely after a reconnect.
    $server = barebits_install_url();
    $cronKey = (string) get_option('barebits_cron_key', '');
    if ($server === '' || $cronKey === '') {
        return false;
    }
    $response = wp_remote_get($server . '/cron.php' . ($ping ? '?ping=1' : ''), [
        'timeout' => $timeoutSeconds,
        'redirection' => 2,
        // Same-origin self-request; mirrors WordPress core's own loopbacks,
        // which skip peer verification for local/self-signed HTTPS.
        'sslverify' => !barebits_is_same_host_url($server),
        'headers' => ['X-CRON-KEY' => $cronKey],
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !array_key_exists('mode', $body)) {
        return false;
    }
    update_option('barebits_cron_last_ok', time(), false);
    return true;
}

/** The every-minute WP-cron callback. */
function barebits_cron_tick(): void {
    if (!barebits_cron_needed()) {
        return;
    }
    $now = time();
    if ($now < (int) get_option('barebits_cron_backoff_until', 0)) {
        return;
    }
    if (barebits_fire_cron_endpoint(15)) {
        delete_option('barebits_cron_backoff_until');
        return;
    }
    update_option('barebits_cron_backoff_until', $now + BAREBITS_CRON_BACKOFF_SECONDS, false);
}
