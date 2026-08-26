<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 03 — Action Scheduler failed actions.
 *
 * Reports volume and concentration. Never retries, cancels or deletes anything.
 */
final class ActionSchedulerFailedCheck implements CheckInterface
{
    /** Heuristic volume thresholds — see docs/CHECKS.md. */
    private const VOLUME_MEDIUM = 10;
    private const VOLUME_HIGH = 50;
    private const VOLUME_CRITICAL = 500;

    /** A single hook owning this share of all failures is worth naming. */
    private const CONCENTRATION_RATIO = 0.5;

    public function key(): string
    {
        return 'action_scheduler_failed';
    }

    public function title(): string
    {
        return 'Action Scheduler — Failed Actions';
    }

    public function run(StoreGateway $store): array
    {
        if (!$store->actionSchedulerAvailable()) {
            return [
                'findings' => [$this->unavailable()],
                'data' => ['available' => false],
            ];
        }

        $data = $store->failedActions();
        $data['available'] = true;
        $total = $data['total'];

        if ($total === 0) {
            return [
                'findings' => [new Finding(
                    'action_scheduler.failed.none',
                    'action_scheduler',
                    Severity::PASS,
                    'No failed scheduled actions',
                    'Action Scheduler reports zero actions in the failed state.',
                    '',
                    '',
                    ['failed_count' => 0]
                )],
                'data' => $data,
            ];
        }

        $severity = match (true) {
            $total >= self::VOLUME_CRITICAL => Severity::CRITICAL,
            $total >= self::VOLUME_HIGH => Severity::HIGH,
            $total >= self::VOLUME_MEDIUM => Severity::MEDIUM,
            default => Severity::LOW,
        };

        arsort($data['by_hook']);

        $age = '';
        if ($data['oldest'] !== null) {
            $age = sprintf(' The oldest failure is %s old.', Format::duration($store->now() - $data['oldest']));
        }

        $findings = [new Finding(
            'action_scheduler.failed.volume',
            'action_scheduler',
            $severity,
            $total === 1 ? 'A scheduled action has failed' : 'Failed scheduled actions present',
            sprintf('%d scheduled action(s) are currently marked as failed.%s', $total, $age),
            'WooCommerce and its extensions push operational work onto Action Scheduler: order emails, stock and lookup-table updates, feed syncs, subscription renewals. A failed action is work that silently did not happen.',
            'Start with the most frequently failing hook below and identify the plugin that registers it. Failures usually share one root cause.',
            [
                'failed_count' => $total,
                'oldest' => $data['oldest'],
                'newest' => $data['newest'],
                'top_hooks' => array_slice($data['by_hook'], 0, 10, true),
            ]
        )];

        $topHook = array_key_first($data['by_hook']);
        if ($topHook !== null && $total >= self::VOLUME_MEDIUM) {
            $topCount = $data['by_hook'][$topHook];
            if ($topCount / $total >= self::CONCENTRATION_RATIO) {
                $findings[] = new Finding(
                    'action_scheduler.failed.concentration',
                    'action_scheduler',
                    Severity::MEDIUM,
                    'Failures are concentrated in one hook',
                    sprintf(
                        '%d of %d failures (%d%%) come from the hook "%s".',
                        $topCount,
                        $total,
                        (int) round($topCount / $total * 100),
                        $topHook
                    ),
                    'A single dominant hook usually means one broken extension rather than a general scheduler problem, which makes it much cheaper to fix.',
                    'Identify the plugin registering this hook and inspect its most recent failure log entry.',
                    ['hook' => $topHook, 'hook_failures' => $topCount, 'failed_count' => $total]
                );
            }
        }

        return ['findings' => $findings, 'data' => $data];
    }

    private function unavailable(): Finding
    {
        return new Finding(
            'action_scheduler.unavailable',
            'action_scheduler',
            Severity::INFO,
            'Action Scheduler could not be inspected',
            'The Action Scheduler tables or API were not found on this installation.',
            'Action Scheduler ships with WooCommerce. Its absence means either WooCommerce is not installed, or it stores its queue somewhere this auditor cannot read.',
            'Confirm WooCommerce is active and that the actionscheduler_* tables exist.',
            ['available' => false]
        );
    }
}
