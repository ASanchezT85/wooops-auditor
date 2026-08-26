<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 04 — Action Scheduler actions that are pending but already past due.
 *
 * A few seconds of lag is normal and is NOT reported as a problem. The
 * thresholds below are heuristics; see docs/CHECKS.md.
 */
final class ActionSchedulerPastDueCheck implements CheckInterface
{
    /** Delay floor (seconds) => severity. Highest floor first. */
    public const THRESHOLDS = [
        21600 => Severity::CRITICAL, // more than 6 h
        3600 => Severity::HIGH,      // 1-6 h
        900 => Severity::MEDIUM,     // 15-60 min
        300 => Severity::LOW,        // 5-15 min
        0 => Severity::INFO,         // under 5 min
    ];

    public function key(): string
    {
        return 'action_scheduler_past_due';
    }

    public function title(): string
    {
        return 'Action Scheduler — Past-Due Actions';
    }

    public function run(StoreGateway $store): array
    {
        if (!$store->actionSchedulerAvailable()) {
            return [
                'findings' => [new Finding(
                    'action_scheduler.past_due.unavailable',
                    'action_scheduler',
                    Severity::INFO,
                    'Past-due actions could not be inspected',
                    'Action Scheduler was not found on this installation.',
                    '',
                    'Confirm WooCommerce is active.',
                    ['available' => false]
                )],
                'data' => ['available' => false],
            ];
        }

        $data = $store->pastDueActions();
        $data['available'] = true;
        $data['thresholds'] = self::THRESHOLDS;
        $total = $data['total'];

        if ($total === 0) {
            return [
                'findings' => [new Finding(
                    'action_scheduler.past_due.none',
                    'action_scheduler',
                    Severity::PASS,
                    'No past-due scheduled actions',
                    'The Action Scheduler queue is keeping up.',
                    '',
                    '',
                    ['past_due_count' => 0]
                )],
                'data' => $data,
            ];
        }

        $severity = self::severityForDelay($data['oldest_delay']);
        arsort($data['by_hook']);

        $findings = [new Finding(
            'action_scheduler.past_due.backlog',
            'action_scheduler',
            $severity,
            $severity === Severity::INFO ? 'Minor Action Scheduler lag' : 'Action Scheduler queue is behind',
            sprintf(
                '%d pending action(s) are past their scheduled time. The oldest is %s late (median lag %s).',
                $total,
                Format::duration($data['oldest_delay']),
                Format::duration($data['median_delay'])
            ),
            'Past-due actions are queued work that should already have run. A sustained backlog means background processing is slower than the rate at which the store creates work, or has stopped entirely.',
            $severity === Severity::INFO
                ? 'No action needed. A lag of a few minutes is normal on the default WordPress queue runner.'
                : 'Confirm the queue runner is executing (WP-Cron or WP-CLI), then look at whether one hook is generating more work than the queue can drain.',
            [
                'past_due_count' => $total,
                'oldest_delay_seconds' => $data['oldest_delay'],
                'median_delay_seconds' => $data['median_delay'],
                'top_hooks' => array_slice($data['by_hook'], 0, 10, true),
            ]
        )];

        return ['findings' => $findings, 'data' => $data];
    }

    public static function severityForDelay(int $delaySeconds): string
    {
        foreach (self::THRESHOLDS as $floor => $severity) {
            if ($delaySeconds >= $floor) {
                return $severity;
            }
        }

        return Severity::INFO;
    }
}
