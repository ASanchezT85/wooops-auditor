<?php
declare(strict_types=1);

namespace WooOps\Auditor\Store;

/**
 * In-memory gateway. Backs the unit tests and the sample report.
 *
 * Defaults describe a healthy small store; pass overrides for the scenario you
 * want. No real customer data ever goes in here.
 */
final class ArrayGateway implements StoreGateway
{
    private array $data;

    public function __construct(array $overrides = [], private ?int $now = null)
    {
        $this->now ??= time();
        $this->data = array_replace_recursive($this->defaults(), $overrides);

        // array_replace_recursive merges maps; for the list-shaped keys we want
        // an override to win outright.
        foreach (['cron.overdue', 'cron.next_events', 'tables'] as $path) {
            [$a, $b] = array_pad(explode('.', $path), 2, null);
            if ($b === null) {
                if (array_key_exists($a, $overrides)) {
                    $this->data[$a] = $overrides[$a];
                }
            } elseif (isset($overrides[$a]) && array_key_exists($b, $overrides[$a])) {
                $this->data[$a][$b] = $overrides[$a][$b];
            }
        }
    }

    public function now(): int
    {
        return $this->now;
    }

    public function environment(): array
    {
        return $this->data['environment'];
    }

    public function cron(): array
    {
        return $this->data['cron'];
    }

    public function actionSchedulerAvailable(): bool
    {
        return $this->data['action_scheduler_available'];
    }

    public function failedActions(): array
    {
        return $this->data['failed_actions'];
    }

    public function pastDueActions(): array
    {
        return $this->data['past_due_actions'];
    }

    public function orders(string $status, ?int $sinceDays = null): array
    {
        return $this->data['orders'][$status] ?? self::emptyOrders();
    }

    public function tables(): array
    {
        return $this->data['tables'];
    }

    public static function emptyOrders(): array
    {
        return [
            'count' => 0,
            'total_value' => 0.0,
            'currency' => 'USD',
            'by_payment_method' => [],
            'by_age_bucket' => [],
            'oldest' => [],
        ];
    }

    private function defaults(): array
    {
        return [
            'environment' => [
                'wordpress_version' => '6.5.2',
                'woocommerce_version' => '8.9.1',
                'woocommerce_active' => true,
                'woocommerce_db_version' => '8.9.1',
                'hpos_enabled' => true,
                'php_version' => '8.2.18',
                'database_version' => '10.6.17-MariaDB',
                'wp_memory_limit' => '256M',
                'php_memory_limit' => '512M',
                'wp_debug' => false,
                'wp_cron_disabled' => false,
                'site_url' => 'https://example-store.test',
                'https' => true,
                'timezone' => 'UTC',
                'active_theme' => 'Storefront 4.6.0',
                'active_plugins_count' => 18,
                'woocommerce_plugins' => ['WooCommerce 8.9.1'],
                'db_prefix' => 'wp_',
            ],
            'cron' => [
                'disabled' => false,
                'alternate' => false,
                'total_events' => 34,
                'overdue' => [],
                'next_events' => [],
                'woocommerce_hooks' => 9,
                'doing_cron_stale' => null,
            ],
            'action_scheduler_available' => true,
            'failed_actions' => [
                'total' => 0,
                'oldest' => null,
                'newest' => null,
                'by_hook' => [],
                'by_group' => [],
            ],
            'past_due_actions' => [
                'total' => 0,
                'oldest_delay' => 0,
                'median_delay' => 0,
                'by_hook' => [],
                'by_group' => [],
                'sample' => [],
            ],
            'orders' => [
                'pending' => self::emptyOrders(),
                'failed' => self::emptyOrders(),
            ],
            'tables' => [
                'wp_actionscheduler_actions' => self::table(1200, 2 * 1024 * 1024),
                'wp_actionscheduler_logs' => self::table(4800, 3 * 1024 * 1024),
                'wp_actionscheduler_claims' => self::table(2, 16384),
                'wp_actionscheduler_groups' => self::table(6, 16384),
                'wp_wc_orders' => self::table(4300, 6 * 1024 * 1024),
            ],
        ];
    }

    public static function table(int $rows, int $dataSize, ?int $indexSize = null): array
    {
        $indexSize ??= (int) ($dataSize * 0.3);

        return [
            'exists' => true,
            'rows' => $rows,
            'data_size' => $dataSize,
            'index_size' => $indexSize,
            'total_size' => $dataSize + $indexSize,
        ];
    }
}
