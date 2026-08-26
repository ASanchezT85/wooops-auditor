<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 05 — orders stuck in the "pending payment" status.
 *
 * A pending order does NOT prove a lost payment. Customers abandon checkout,
 * bank transfers are legitimately slow, and some gateways leave the order
 * pending until an asynchronous callback arrives. What this check reports is
 * *age*: a pending order that is days old is an order nobody is going to
 * collect, and a sudden pile of them is worth a look.
 */
final class PendingOrdersCheck implements CheckInterface
{
    /** Buckets considered "stale" — older than 24 hours. */
    private const STALE_BUCKETS = ['1-7d', '>7d'];

    private const STALE_HIGH = 20;
    private const STALE_MEDIUM = 5;

    public function key(): string
    {
        return 'pending_orders';
    }

    public function title(): string
    {
        return 'Pending Orders';
    }

    public function run(StoreGateway $store): array
    {
        $data = $store->orders('pending');

        if ($data['count'] === 0) {
            return [
                'findings' => [new Finding(
                    'orders.pending.none',
                    'orders',
                    Severity::PASS,
                    'No pending orders',
                    'The store has no orders sitting in the pending payment status.',
                    '',
                    '',
                    ['pending_count' => 0]
                )],
                'data' => $data,
            ];
        }

        $stale = 0;
        foreach (self::STALE_BUCKETS as $bucket) {
            $stale += $data['by_age_bucket'][$bucket] ?? 0;
        }

        $severity = match (true) {
            $stale >= self::STALE_HIGH => Severity::HIGH,
            $stale >= self::STALE_MEDIUM => Severity::MEDIUM,
            $stale >= 1 => Severity::LOW,
            default => Severity::INFO,
        };

        $findings = [new Finding(
            'orders.pending.volume',
            'orders',
            $severity,
            $stale > 0 ? 'Pending orders are ageing' : 'Pending orders present',
            sprintf(
                '%d order(s) are in the pending payment status, totalling %s. %d of them are more than 24 hours old.',
                $data['count'],
                Format::money($data['total_value'], $data['currency']),
                $stale
            ),
            'Pending means the store is still waiting for payment confirmation. Some of these are ordinary abandoned checkouts, but the ones that never resolve can also indicate a gateway callback that is not arriving, or a payment that was taken and never recorded against the order.',
            $stale > 0
                ? 'Open the oldest pending orders listed below and reconcile them against the payment gateway dashboard. If the gateway shows a captured payment, the callback path is broken.'
                : 'No action needed yet. Recent pending orders are normal checkout traffic.',
            [
                'pending_count' => $data['count'],
                'total_value' => round($data['total_value'], 2),
                'currency' => $data['currency'],
                'stale_over_24h' => $stale,
                'by_age_bucket' => $data['by_age_bucket'],
                'by_payment_method' => $data['by_payment_method'],
                'oldest_orders' => $data['oldest'],
            ],
            'Value shown is the order total as recorded by WooCommerce. It is not revenue, and it is not money that was lost.'
        )];

        return ['findings' => $findings, 'data' => $data];
    }
}
