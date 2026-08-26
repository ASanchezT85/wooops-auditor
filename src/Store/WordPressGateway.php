<?php
declare(strict_types=1);

namespace WooOps\Auditor\Store;

use wpdb;

/**
 * The real gateway. Reads WordPress, WooCommerce and the database.
 *
 * Every statement in this file is a SELECT or a WordPress read API. There is
 * no UPDATE, INSERT, DELETE, ALTER or OPTIMIZE anywhere, by design and by
 * review: see docs/SECURITY.md.
 *
 * Aggregation happens in SQL. The auditor never loads a full order set into
 * memory, because it has to be safe to run against a store with a million
 * orders.
 */
final class WordPressGateway implements StoreGateway
{
    /** How many oldest orders to list per status. Bounded on purpose. */
    private const ORDER_SAMPLE = 10;

    private int $now;

    public function __construct(private wpdb $db, ?int $now = null)
    {
        $this->now = $now ?? time();
    }

    public static function create(): self
    {
        global $wpdb;

        return new self($wpdb, time());
    }

    public function now(): int
    {
        return $this->now;
    }

    public function environment(): array
    {
        $wooActive = class_exists('WooCommerce');
        $wooVersion = defined('WC_VERSION') ? WC_VERSION : null;
        $siteUrl = (string) get_option('siteurl');

        $wooPlugins = [];
        $pluginCount = 0;
        if (function_exists('get_plugins')) {
            $active = (array) get_option('active_plugins', []);
            $pluginCount = count($active);
            $all = get_plugins();
            foreach ($active as $file) {
                if (!isset($all[$file])) {
                    continue;
                }
                $name = (string) $all[$file]['Name'];
                if (stripos($name, 'woo') !== false || stripos($file, 'woo') !== false) {
                    $wooPlugins[] = $name . ' ' . $all[$file]['Version'];
                }
            }
        }

        return [
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => $wooVersion,
            'woocommerce_active' => $wooActive,
            'woocommerce_db_version' => get_option('woocommerce_db_version') ?: null,
            'hpos_enabled' => $this->hposEnabled(),
            'php_version' => PHP_VERSION,
            'database_version' => $this->db->db_version(),
            'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
            'php_memory_limit' => (string) ini_get('memory_limit'),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'site_url' => $siteUrl,
            'https' => str_starts_with($siteUrl, 'https://'),
            'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string'),
            'active_theme' => $this->themeName(),
            'active_plugins_count' => $pluginCount,
            'woocommerce_plugins' => $wooPlugins,
            'db_prefix' => $this->db->prefix,
        ];
    }

    public function cron(): array
    {
        $cronArray = function_exists('_get_cron_array') ? (array) _get_cron_array() : [];

        $overdue = [];
        $next = [];
        $total = 0;
        $wooHooks = 0;

        foreach ($cronArray as $timestamp => $hooks) {
            if (!is_numeric($timestamp)) {
                continue;
            }
            $timestamp = (int) $timestamp;
            foreach ((array) $hooks as $hook => $events) {
                $count = count((array) $events);
                $total += $count;
                if (str_starts_with((string) $hook, 'woocommerce') || str_starts_with((string) $hook, 'wc_')) {
                    $wooHooks += $count;
                }
                if ($timestamp < $this->now) {
                    $overdue[] = [
                        'hook' => (string) $hook,
                        'timestamp' => $timestamp,
                        'delay' => $this->now - $timestamp,
                    ];
                } elseif (count($next) < 10) {
                    $next[] = ['hook' => (string) $hook, 'timestamp' => $timestamp];
                }
            }
        }

        usort($overdue, static fn (array $a, array $b) => $b['delay'] <=> $a['delay']);
        $overdue = array_slice($overdue, 0, 50);

        return [
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'total_events' => $total,
            'overdue' => $overdue,
            'next_events' => $next,
            'woocommerce_hooks' => $wooHooks,
            'doing_cron_stale' => $this->staleCronLock(),
        ];
    }

    public function actionSchedulerAvailable(): bool
    {
        return $this->tableExists($this->db->prefix . 'actionscheduler_actions');
    }

    public function failedActions(): array
    {
        $table = $this->db->prefix . 'actionscheduler_actions';
        if (!$this->tableExists($table)) {
            return ['total' => 0, 'oldest' => null, 'newest' => null, 'by_hook' => [], 'by_group' => []];
        }

        $groups = $this->db->prefix . 'actionscheduler_groups';

        $row = $this->db->get_row(
            "SELECT COUNT(*) AS total, MIN(scheduled_date_gmt) AS oldest, MAX(scheduled_date_gmt) AS newest
             FROM {$table} WHERE status = 'failed'",
            ARRAY_A
        );

        $byHook = [];
        $rows = $this->db->get_results(
            "SELECT hook, COUNT(*) AS c FROM {$table} WHERE status = 'failed'
             GROUP BY hook ORDER BY c DESC LIMIT 25",
            ARRAY_A
        );
        foreach ((array) $rows as $r) {
            $byHook[(string) $r['hook']] = (int) $r['c'];
        }

        $byGroup = [];
        if ($this->tableExists($groups)) {
            $rows = $this->db->get_results(
                "SELECT g.slug AS slug, COUNT(*) AS c FROM {$table} a
                 LEFT JOIN {$groups} g ON g.group_id = a.group_id
                 WHERE a.status = 'failed' GROUP BY g.slug ORDER BY c DESC LIMIT 25",
                ARRAY_A
            );
            foreach ((array) $rows as $r) {
                $byGroup[(string) ($r['slug'] ?? 'none')] = (int) $r['c'];
            }
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'oldest' => $this->toTimestamp($row['oldest'] ?? null),
            'newest' => $this->toTimestamp($row['newest'] ?? null),
            'by_hook' => $byHook,
            'by_group' => $byGroup,
        ];
    }

    public function pastDueActions(): array
    {
        $table = $this->db->prefix . 'actionscheduler_actions';
        $empty = ['total' => 0, 'oldest_delay' => 0, 'median_delay' => 0, 'by_hook' => [], 'by_group' => [], 'sample' => []];
        if (!$this->tableExists($table)) {
            return $empty;
        }

        $cutoff = gmdate('Y-m-d H:i:s', $this->now);

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT COUNT(*) AS total, MIN(scheduled_date_gmt) AS oldest
                 FROM {$table} WHERE status = 'pending' AND scheduled_date_gmt < %s",
                $cutoff
            ),
            ARRAY_A
        );

        $total = (int) ($row['total'] ?? 0);
        if ($total === 0) {
            return $empty;
        }

        $oldest = $this->toTimestamp($row['oldest'] ?? null);

        $byHook = [];
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT hook, COUNT(*) AS c FROM {$table}
                 WHERE status = 'pending' AND scheduled_date_gmt < %s
                 GROUP BY hook ORDER BY c DESC LIMIT 25",
                $cutoff
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $r) {
            $byHook[(string) $r['hook']] = (int) $r['c'];
        }

        // Median over a bounded sample rather than the whole backlog: a store
        // with 400k past-due actions must not pull them all into PHP.
        $sampleRows = $this->db->get_results(
            $this->db->prepare(
                "SELECT hook, scheduled_date_gmt FROM {$table}
                 WHERE status = 'pending' AND scheduled_date_gmt < %s
                 ORDER BY scheduled_date_gmt ASC LIMIT 1000",
                $cutoff
            ),
            ARRAY_A
        );

        $delays = [];
        $sample = [];
        foreach ((array) $sampleRows as $r) {
            $ts = $this->toTimestamp($r['scheduled_date_gmt']);
            if ($ts === null) {
                continue;
            }
            $delay = $this->now - $ts;
            $delays[] = $delay;
            if (count($sample) < 10) {
                $sample[] = ['hook' => (string) $r['hook'], 'delay' => $delay];
            }
        }

        sort($delays);
        $median = $delays === [] ? 0 : (int) $delays[intdiv(count($delays), 2)];

        return [
            'total' => $total,
            'oldest_delay' => $oldest === null ? 0 : $this->now - $oldest,
            'median_delay' => $median,
            'by_hook' => $byHook,
            'by_group' => [],
            'sample' => $sample,
        ];
    }

    public function orders(string $status, ?int $sinceDays = null): array
    {
        $empty = ArrayGateway::emptyOrders();
        $empty['currency'] = (string) (get_option('woocommerce_currency') ?: 'USD');

        $hpos = $this->hposEnabled();
        $table = $hpos ? $this->db->prefix . 'wc_orders' : $this->db->posts;
        if (!$this->tableExists($table)) {
            return $empty;
        }

        $since = $sinceDays === null ? null : gmdate('Y-m-d H:i:s', $this->now - ($sinceDays * 86400));

        if ($hpos) {
            $where = $this->db->prepare('o.status = %s', 'wc-' . $status);
            if ($since !== null) {
                $where .= $this->db->prepare(' AND o.date_created_gmt >= %s', $since);
            }

            $summary = $this->db->get_row(
                "SELECT COUNT(*) AS c, COALESCE(SUM(o.total_amount), 0) AS total
                 FROM {$table} o WHERE {$where}",
                ARRAY_A
            );

            $methods = $this->db->get_results(
                "SELECT COALESCE(NULLIF(o.payment_method_title, ''), NULLIF(o.payment_method, ''), 'unknown') AS m,
                        COUNT(*) AS c
                 FROM {$table} o WHERE {$where} GROUP BY m ORDER BY c DESC LIMIT 25",
                ARRAY_A
            );

            $sample = $this->db->get_results(
                "SELECT o.id, o.date_created_gmt, o.total_amount, o.currency,
                        COALESCE(NULLIF(o.payment_method_title, ''), NULLIF(o.payment_method, ''), 'unknown') AS m,
                        o.status
                 FROM {$table} o WHERE {$where}
                 ORDER BY o.date_created_gmt ASC LIMIT " . self::ORDER_SAMPLE,
                ARRAY_A
            );

            $ages = $this->db->get_results(
                "SELECT o.date_created_gmt AS d FROM {$table} o WHERE {$where}
                 ORDER BY o.date_created_gmt ASC LIMIT 5000",
                ARRAY_A
            );
        } else {
            $meta = $this->db->postmeta;
            $where = $this->db->prepare("p.post_type = 'shop_order' AND p.post_status = %s", 'wc-' . $status);
            if ($since !== null) {
                $where .= $this->db->prepare(' AND p.post_date_gmt >= %s', $since);
            }

            $summary = $this->db->get_row(
                "SELECT COUNT(*) AS c, COALESCE(SUM(m.meta_value + 0), 0) AS total
                 FROM {$this->db->posts} p
                 LEFT JOIN {$meta} m ON m.post_id = p.ID AND m.meta_key = '_order_total'
                 WHERE {$where}",
                ARRAY_A
            );

            $methods = $this->db->get_results(
                "SELECT COALESCE(NULLIF(m.meta_value, ''), 'unknown') AS m, COUNT(*) AS c
                 FROM {$this->db->posts} p
                 LEFT JOIN {$meta} m ON m.post_id = p.ID AND m.meta_key = '_payment_method_title'
                 WHERE {$where} GROUP BY m ORDER BY c DESC LIMIT 25",
                ARRAY_A
            );

            $sample = $this->db->get_results(
                "SELECT p.ID AS id, p.post_date_gmt AS date_created_gmt,
                        (SELECT meta_value FROM {$meta} WHERE post_id = p.ID AND meta_key = '_order_total' LIMIT 1) AS total_amount,
                        (SELECT meta_value FROM {$meta} WHERE post_id = p.ID AND meta_key = '_order_currency' LIMIT 1) AS currency,
                        (SELECT meta_value FROM {$meta} WHERE post_id = p.ID AND meta_key = '_payment_method_title' LIMIT 1) AS m,
                        p.post_status AS status
                 FROM {$this->db->posts} p WHERE {$where}
                 ORDER BY p.post_date_gmt ASC LIMIT " . self::ORDER_SAMPLE,
                ARRAY_A
            );

            $ages = $this->db->get_results(
                "SELECT p.post_date_gmt AS d FROM {$this->db->posts} p WHERE {$where}
                 ORDER BY p.post_date_gmt ASC LIMIT 5000",
                ARRAY_A
            );
        }

        $byMethod = [];
        foreach ((array) $methods as $r) {
            $byMethod[(string) $r['m']] = (int) $r['c'];
        }

        $oldest = [];
        foreach ((array) $sample as $r) {
            $ts = $this->toTimestamp($r['date_created_gmt']);
            $oldest[] = [
                'id' => (int) $r['id'],
                'date' => (string) $r['date_created_gmt'],
                'age' => $ts === null ? 0 : $this->now - $ts,
                'amount' => (float) $r['total_amount'],
                'currency' => (string) ($r['currency'] ?: $empty['currency']),
                'payment_method' => (string) ($r['m'] ?? 'unknown'),
                'status' => (string) $r['status'],
            ];
        }

        $buckets = array_fill_keys(['<1h', '1-6h', '6-24h', '1-7d', '>7d'], 0);
        foreach ((array) $ages as $r) {
            $ts = $this->toTimestamp($r['d']);
            if ($ts === null) {
                continue;
            }
            $buckets[$this->bucket($this->now - $ts)]++;
        }

        return [
            'count' => (int) ($summary['c'] ?? 0),
            'total_value' => (float) ($summary['total'] ?? 0),
            'currency' => $empty['currency'],
            'by_payment_method' => $byMethod,
            'by_age_bucket' => $buckets,
            'oldest' => $oldest,
        ];
    }

    public function tables(): array
    {
        $names = [
            $this->db->prefix . 'actionscheduler_actions',
            $this->db->prefix . 'actionscheduler_logs',
            $this->db->prefix . 'actionscheduler_claims',
            $this->db->prefix . 'actionscheduler_groups',
            $this->db->prefix . 'wc_orders',
            $this->db->prefix . 'wc_order_addresses',
            $this->db->prefix . 'wc_orders_meta',
            $this->db->posts,
            $this->db->postmeta,
            $this->db->options,
        ];

        $out = [];
        foreach (array_unique($names) as $name) {
            $out[$name] = ['exists' => false, 'rows' => 0, 'data_size' => 0, 'index_size' => 0, 'total_size' => 0];
        }

        $placeholders = implode(', ', array_fill(0, count($out), '%s'));
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT TABLE_NAME AS n, TABLE_ROWS AS r, DATA_LENGTH AS d, INDEX_LENGTH AS i
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                ...array_keys($out)
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $r) {
            $name = (string) $r['n'];
            $out[$name] = [
                'exists' => true,
                'rows' => (int) $r['r'],
                'data_size' => (int) $r['d'],
                'index_size' => (int) $r['i'],
                'total_size' => (int) $r['d'] + (int) $r['i'],
            ];
        }

        return $out;
    }

    private function hposEnabled(): bool
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
        ) {
            return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }

        return get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    }

    private function themeName(): string
    {
        if (!function_exists('wp_get_theme')) {
            return '';
        }
        $theme = wp_get_theme();

        return trim($theme->get('Name') . ' ' . $theme->get('Version'));
    }

    /** Age of the DOING_CRON lock, if it is older than the WP timeout. */
    private function staleCronLock(): ?int
    {
        if (!function_exists('get_transient')) {
            return null;
        }
        $lock = get_transient('doing_cron');
        if (!$lock) {
            return null;
        }
        $age = $this->now - (int) $lock;
        $timeout = defined('WP_CRON_LOCK_TIMEOUT') ? (int) WP_CRON_LOCK_TIMEOUT : 60;

        return $age > max($timeout, 600) ? $age : null;
    }

    private function tableExists(string $table): bool
    {
        $found = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $table));

        return $found === $table;
    }

    private function toTimestamp(?string $gmt): ?int
    {
        if ($gmt === null || $gmt === '' || str_starts_with($gmt, '0000')) {
            return null;
        }
        $ts = strtotime($gmt . ' UTC');

        return $ts === false ? null : $ts;
    }

    private function bucket(int $age): string
    {
        return match (true) {
            $age < 3600 => '<1h',
            $age < 21600 => '1-6h',
            $age < 86400 => '6-24h',
            $age < 604800 => '1-7d',
            default => '>7d',
        };
    }
}
