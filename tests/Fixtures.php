<?php
declare(strict_types=1);

namespace WooOps\Auditor\Tests;

use WooOps\Auditor\Store\ArrayGateway;

/**
 * Scenario builders shared by the tests and by the sample report.
 * No real store data, no real people.
 */
final class Fixtures
{
    public const NOW = 1787745600; // 2026-08-26 12:00:00 UTC, fixed for reproducibility

    public static function healthy(): ArrayGateway
    {
        return new ArrayGateway([], self::NOW);
    }

    /** The store used for examples/sample-report.*: visibly unwell, plausibly real. */
    public static function troubledStore(): ArrayGateway
    {
        return new ArrayGateway([
            'environment' => [
                'woocommerce_version' => '8.9.1',
                'wp_memory_limit' => '192M',
                'wp_debug' => true,
                'site_url' => 'https://demo-store.example',
                'active_plugins_count' => 34,
                'woocommerce_plugins' => [
                    'WooCommerce 8.9.1',
                    'WooCommerce Subscriptions 5.9.0',
                    'Google Listings and Ads 2.7.2',
                    'WooCommerce Stripe Gateway 8.1.0',
                ],
            ],
            'cron' => [
                'disabled' => true,
                'total_events' => 41,
                'overdue' => [
                    ['hook' => 'woocommerce_scheduled_sales', 'timestamp' => self::NOW - 2400, 'delay' => 2400],
                    ['hook' => 'wc_admin_daily', 'timestamp' => self::NOW - 1800, 'delay' => 1800],
                ],
                'woocommerce_hooks' => 11,
            ],
            'failed_actions' => [
                'total' => 43,
                'oldest' => self::NOW - 1209600,
                'newest' => self::NOW - 5400,
                'by_hook' => [
                    'wc_update_product_lookup_tables' => 24,
                    'gla/jobs/update_products' => 15,
                    'some_plugin_background_sync' => 4,
                ],
                'by_group' => ['woocommerce-db-updates' => 24, 'gla' => 15, 'none' => 4],
            ],
            'past_due_actions' => [
                'total' => 12,
                'oldest_delay' => 4200,
                'median_delay' => 1900,
                'by_hook' => [
                    'action_scheduler_run_queue' => 6,
                    'woocommerce_run_product_attribute_lookup_regeneration_callback' => 4,
                    'wcs_scheduled_subscription_payment' => 2,
                ],
                'sample' => [
                    ['hook' => 'action_scheduler_run_queue', 'delay' => 4200],
                    ['hook' => 'wcs_scheduled_subscription_payment', 'delay' => 1600],
                ],
            ],
            'orders' => [
                'pending' => [
                    'count' => 7,
                    'total_value' => 742.81,
                    'currency' => 'USD',
                    'by_payment_method' => ['Credit Card (Stripe)' => 5, 'Direct bank transfer' => 2],
                    'by_age_bucket' => ['<1h' => 1, '1-6h' => 2, '6-24h' => 1, '1-7d' => 2, '>7d' => 1],
                    'oldest' => [
                        ['id' => 18041, 'date' => '2026-08-12 09:14:00', 'age' => 1220760, 'amount' => 189.00, 'currency' => 'USD', 'payment_method' => 'Direct bank transfer', 'status' => 'pending'],
                        ['id' => 18122, 'date' => '2026-08-22 17:41:00', 'age' => 325140, 'amount' => 74.50, 'currency' => 'USD', 'payment_method' => 'Credit Card (Stripe)', 'status' => 'pending'],
                        ['id' => 18190, 'date' => '2026-08-25 08:02:00', 'age' => 100680, 'amount' => 132.40, 'currency' => 'USD', 'payment_method' => 'Credit Card (Stripe)', 'status' => 'pending'],
                    ],
                ],
                'failed' => [
                    'count' => 3,
                    'total_value' => 438.98,
                    'currency' => 'USD',
                    'by_payment_method' => ['Credit Card (Stripe)' => 2, 'PayPal' => 1],
                    'by_age_bucket' => ['<1h' => 0, '1-6h' => 1, '6-24h' => 1, '1-7d' => 1, '>7d' => 0],
                    'oldest' => [
                        ['id' => 18163, 'date' => '2026-08-21 11:20:00', 'age' => 434280, 'amount' => 249.99, 'currency' => 'USD', 'payment_method' => 'Credit Card (Stripe)', 'status' => 'failed'],
                        ['id' => 18156, 'date' => '2026-08-24 19:05:00', 'age' => 147300, 'amount' => 89.00, 'currency' => 'USD', 'payment_method' => 'PayPal', 'status' => 'failed'],
                    ],
                ],
            ],
            'tables' => [
                'wp_actionscheduler_actions' => ArrayGateway::table(312044, 190 * 1024 * 1024, 96 * 1024 * 1024),
                'wp_actionscheduler_logs' => ArrayGateway::table(1072113, 1010 * 1024 * 1024, 331 * 1024 * 1024),
                'wp_actionscheduler_claims' => ArrayGateway::table(3, 16384, 16384),
                'wp_actionscheduler_groups' => ArrayGateway::table(9, 16384, 16384),
                'wp_wc_orders' => ArrayGateway::table(18240, 22 * 1024 * 1024, 7 * 1024 * 1024),
                'wp_options' => ArrayGateway::table(4120, 9 * 1024 * 1024, 2 * 1024 * 1024),
            ],
        ], self::NOW);
    }
}
