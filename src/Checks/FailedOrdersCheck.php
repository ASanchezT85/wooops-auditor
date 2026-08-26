<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 06 — orders in the "failed" status over a recent window.
 *
 * Semantics matter here. The monetary figure is *attempted* value: what the
 * customer tried to spend. It is not revenue lost — most failed orders are
 * declined cards that were never going to become revenue. What is worth
 * reporting is volume and, above all, concentration in a single gateway.
 */
final class FailedOrdersCheck implements CheckInterface
{
    private const WINDOW_DAYS = 30;

    private const VOLUME_HIGH = 50;
    private const VOLUME_MEDIUM = 10;

    /** One gateway owning this share of failures, with enough volume to mean it. */
    private const CONCENTRATION_RATIO = 0.7;
    private const CONCENTRATION_MIN = 5;

    public function key(): string
    {
        return 'failed_orders';
    }

    public function title(): string
    {
        return 'Failed Orders';
    }

    public function run(StoreGateway $store): array
    {
        $data = $store->orders('failed', self::WINDOW_DAYS);
        $data['window_days'] = self::WINDOW_DAYS;
        $total = $data['count'];

        if ($total === 0) {
            return [
                'findings' => [new Finding(
                    'orders.failed.none',
                    'orders',
                    Severity::PASS,
                    'No failed orders in the last 30 days',
                    'No orders reached the failed status in the audited window.',
                    '',
                    '',
                    ['failed_count' => 0, 'window_days' => self::WINDOW_DAYS]
                )],
                'data' => $data,
            ];
        }

        $severity = match (true) {
            $total >= self::VOLUME_HIGH => Severity::HIGH,
            $total >= self::VOLUME_MEDIUM => Severity::MEDIUM,
            default => Severity::LOW,
        };

        arsort($data['by_payment_method']);

        $findings = [new Finding(
            'orders.failed.volume',
            'orders',
            $severity,
            'Failed orders in the last 30 days',
            sprintf(
                '%d order(s) failed in the last %d days, for a total attempted value of %s.',
                $total,
                self::WINDOW_DAYS,
                Format::money($data['total_value'], $data['currency'])
            ),
            'A failed order is a checkout that did not complete at the payment step. A background level of failures is normal (declined cards, 3-D Secure abandonment). A spike, or a concentration in one gateway, usually means a misconfiguration rather than customer behaviour.',
            'Compare the failure counts per gateway below against the gateway dashboard. If one gateway dominates, check its API credentials, webhook endpoint and currency configuration.',
            [
                'failed_count' => $total,
                'window_days' => self::WINDOW_DAYS,
                'attempted_value' => round($data['total_value'], 2),
                'currency' => $data['currency'],
                'by_payment_method' => $data['by_payment_method'],
                'by_age_bucket' => $data['by_age_bucket'],
                'recent_orders' => $data['oldest'],
            ],
            'Attempted value is the sum of the order totals. It is NOT revenue lost: most failed orders would not have converted.'
        )];

        $topMethod = array_key_first($data['by_payment_method']);
        if ($topMethod !== null && $total >= self::CONCENTRATION_MIN) {
            $topCount = $data['by_payment_method'][$topMethod];
            if ($topCount / $total >= self::CONCENTRATION_RATIO) {
                $findings[] = new Finding(
                    'orders.failed.gateway_concentration',
                    'orders',
                    Severity::MEDIUM,
                    'Failures are concentrated in one payment method',
                    sprintf(
                        '%d of %d failed orders (%d%%) used "%s".',
                        $topCount,
                        $total,
                        (int) round($topCount / $total * 100),
                        $topMethod
                    ),
                    'When one gateway accounts for nearly all failures, the cause is usually on the store side: wrong keys, an expired certificate, a blocked webhook or an unsupported currency.',
                    'Run a test transaction through this gateway and review its error log for the most recent failures.',
                    ['payment_method' => $topMethod, 'method_failures' => $topCount, 'failed_count' => $total]
                );
            }
        }

        return ['findings' => $findings, 'data' => $data];
    }
}
