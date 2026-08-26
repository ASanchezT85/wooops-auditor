<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 02 — WP-Cron health.
 *
 * Deliberately conservative: WordPress cannot see an external system cron, so
 * DISABLE_WP_CRON on its own is reported as "unverifiable", never as "broken".
 * The evidence that actually matters is whether events are piling up overdue.
 */
final class CronCheck implements CheckInterface
{
    private const OVERDUE_MEDIUM = 900;    // 15 min
    private const OVERDUE_HIGH = 3600;     // 1 h
    private const OVERDUE_CRITICAL = 21600; // 6 h

    public function key(): string { return 'cron'; }
    public function title(): string { return 'WP-Cron Health'; }

    public function run(StoreGateway $store): array
    {
        $cron = $store->cron();
        $findings = [];

        $overdue = $cron['overdue'];
        $worst = 0;
        foreach ($overdue as $event) {
            $worst = max($worst, $event['delay']);
        }

        if ($worst >= self::OVERDUE_CRITICAL) {
            $findings[] = new Finding(
                'cron.overdue.critical',
                'cron',
                Severity::CRITICAL,
                'WP-Cron events are hours overdue',
                sprintf(
                    '%d scheduled event(s) are overdue; the worst is %s late.',
                    count($overdue),
                    Format::duration($worst)
                ),
                'A delay of this size is the strongest evidence available from inside WordPress that cron is not running at all. Order emails, subscription renewals and stock syncs all ride on it.',
                'Check whether anything is triggering wp-cron.php (a real system cron, a host-level cron, or normal site traffic). Look for a stuck DOING_CRON lock.',
                ['overdue_count' => count($overdue), 'worst_delay_seconds' => $worst, 'events' => array_slice($overdue, 0, 10)]
            );
        } elseif ($worst >= self::OVERDUE_HIGH) {
            $findings[] = new Finding(
                'cron.overdue.high',
                'cron',
                Severity::HIGH,
                'WP-Cron events are significantly overdue',
                sprintf('%d scheduled event(s) are overdue; the worst is %s late.', count($overdue), Format::duration($worst)),
                'Overdue events mean background work is not happening on time, which typically shows up first as delayed emails and stale reports.',
                'Confirm cron is being triggered and that no single long-running job is blocking the queue.',
                ['overdue_count' => count($overdue), 'worst_delay_seconds' => $worst, 'events' => array_slice($overdue, 0, 10)]
            );
        } elseif ($worst >= self::OVERDUE_MEDIUM) {
            $findings[] = new Finding(
                'cron.overdue.medium',
                'cron',
                Severity::MEDIUM,
                'Some WP-Cron events are running late',
                sprintf('%d scheduled event(s) are overdue; the worst is %s late.', count($overdue), Format::duration($worst)),
                'Moderate lateness is normal on low-traffic sites using the default WordPress cron, but it is worth watching.',
                'If this store depends on timely background work, move to a real system cron.',
                ['overdue_count' => count($overdue), 'worst_delay_seconds' => $worst]
            );
        }

        if ($cron['disabled']) {
            $findings[] = new Finding(
                'cron.disabled.unverifiable',
                'cron',
                $worst >= self::OVERDUE_HIGH ? Severity::MEDIUM : Severity::INFO,
                'DISABLE_WP_CRON is true',
                'WordPress will not trigger cron on page loads. An external system cron may be configured; that cannot be verified from inside WordPress.',
                'This is a normal, recommended production setup — but only if something outside WordPress is actually calling wp-cron.php.',
                'Confirm with the host or server admin that a system cron calls wp-cron.php on a schedule. Use the overdue-event evidence in this report as the real signal.',
                ['disable_wp_cron' => true, 'overdue_count' => count($overdue)]
            );
        }

        if ($cron['doing_cron_stale'] !== null) {
            $findings[] = new Finding(
                'cron.lock.stale',
                'cron',
                Severity::HIGH,
                'Stale cron lock detected',
                sprintf('The DOING_CRON lock has been held for %s.', Format::duration($cron['doing_cron_stale'])),
                'A stuck lock stops WordPress from starting new cron runs, so the whole queue freezes even though cron is technically configured.',
                'Investigate the job that was running when the lock was taken. WordPress clears the lock itself after 10 minutes; a persistently stale lock means a job is fataling or timing out.',
                ['lock_age_seconds' => $cron['doing_cron_stale']]
            );
        }

        if ($findings === []) {
            $findings[] = new Finding(
                'cron.ok',
                'cron',
                Severity::PASS,
                'WP-Cron looks healthy',
                sprintf('%d scheduled event(s) registered, none significantly overdue.', $cron['total_events']),
                '',
                '',
                ['total_events' => $cron['total_events']]
            );
        }

        return ['findings' => $findings, 'data' => $cron];
    }

}
