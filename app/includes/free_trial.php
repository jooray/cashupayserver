<?php
/**
 * CashuPayServer — Free trial gate.
 *
 * Suppresses the dev fee and per-store hosting fee while
 * an operator-configured free-trial window is open. Network fees (Lightning
 * routing / mint fees) are never suppressed — they leave the deployment as
 * actual sats spent on melts.
 *
 * Settings are seeded once from env at first migration (see
 * includes/database.php) and are NOT editable from the admin UI. Two
 * thresholds may be set; the trial expires when whichever fires first (OR):
 *   - free_trial_until_ts (int unix seconds)
 *   - free_trial_revenue_cap_sats (int sats, evaluated deployment-wide)
 *
 * On expiry, expireIfNeeded() advances fee_tracking_start_at to the moment
 * the trial ended so future fee math only sees revenue accrued post-trial.
 * Revenue earned during the trial is permanently excluded from fee bases —
 * matching the spirit of "free trial" (no retroactive billing).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class FreeTrial {
    /**
     * True iff a trial was configured and at least one threshold is still
     * unmet. False once expireIfNeeded() has flipped free_trial_expired_at,
     * or when no trial was ever seeded.
     */
    public static function isActive(): bool {
        if ((int) Config::get('free_trial_expired_at', 0) > 0) {
            return false;
        }
        $until = Config::get('free_trial_until_ts');
        $cap   = Config::get('free_trial_revenue_cap_sats');
        if ($until === null && $cap === null) {
            return false;
        }
        $reason = self::expiryReason();
        return $reason === null;
    }

    /**
     * Status block surfaced on the stats dashboard. `active` reflects the
     * live check; `revenue_during_trial_sats` is deployment-wide regardless
     * of which store the dashboard is filtered to (the trial is global).
     */
    public static function status(): array {
        $until = Config::get('free_trial_until_ts');
        $cap   = Config::get('free_trial_revenue_cap_sats');
        $expiredAt = (int) Config::get('free_trial_expired_at', 0);
        $startedAt = (int) Config::get('free_trial_started_at', 0);

        $configured = ($until !== null || $cap !== null);
        $revenue = $configured ? self::revenueDuringTrial() : 0;

        return [
            'configured' => $configured,
            'active' => self::isActive(),
            'started_at' => $startedAt > 0 ? $startedAt : null,
            'until_ts' => $until !== null ? (int)$until : null,
            'revenue_cap_sats' => $cap !== null ? (int)$cap : null,
            'revenue_during_trial_sats' => $revenue,
            'expired_at' => $expiredAt > 0 ? $expiredAt : null,
            'expired_reason' => $expiredAt > 0
                ? (string) Config::get('free_trial_expired_reason', 'unknown')
                : self::expiryReason(),
        ];
    }

    /**
     * Returns the name of the threshold that has fired ('date' | 'revenue'),
     * or null if the trial is still active. Only meaningful when a trial is
     * configured — callers should check that first.
     */
    private static function expiryReason(): ?string {
        $until = Config::get('free_trial_until_ts');
        if ($until !== null && time() >= (int)$until) {
            return 'date';
        }
        $cap = Config::get('free_trial_revenue_cap_sats');
        if ($cap !== null && self::revenueDuringTrial() >= (int)$cap) {
            return 'revenue';
        }
        return null;
    }

    /**
     * Sum of paid-invoice sats across every store since the trial started.
     * Mirrors the DevFee::computeOwed() filter (Settled, amount_sats NOT
     * NULL, created_at >= start) but is deployment-wide, not per-store.
     */
    public static function revenueDuringTrial(): int {
        $start = (int) Config::get('free_trial_started_at', 0);
        $row = Database::fetchOne(
            "SELECT COALESCE(SUM(amount_sats), 0) AS s
             FROM invoices
             WHERE status = 'Settled'
               AND amount_sats IS NOT NULL
               AND created_at >= ?",
            [$start]
        );
        return (int)($row['s'] ?? 0);
    }

    /**
     * Idempotent: if a trial is configured and a threshold has fired, mark
     * expired and advance fee_tracking_start_at so post-trial revenue
     * accrues fees but trial-window revenue does not. Safe to call from
     * cron and from the stats summary path.
     */
    public static function expireIfNeeded(): void {
        if ((int) Config::get('free_trial_expired_at', 0) > 0) {
            return;
        }
        $until = Config::get('free_trial_until_ts');
        $cap   = Config::get('free_trial_revenue_cap_sats');
        if ($until === null && $cap === null) {
            return;
        }
        $reason = self::expiryReason();
        if ($reason === null) {
            return;
        }
        $now = time();
        Config::set('free_trial_expired_at', $now);
        Config::set('free_trial_expired_reason', $reason);
        // Going-forward only: fees now apply only to revenue from this
        // moment on. Pre-trial fee_tracking_start_at is overwritten because
        // any revenue that landed between it and now happened during the
        // trial window and was promised fee-free.
        Config::set('fee_tracking_start_at', $now);
    }
}
