<?php
declare(strict_types=1);

namespace WooOps\Auditor\Store;

/**
 * Read-only data source for the audit.
 *
 * Everything that touches WordPress, WooCommerce or the database lives behind
 * this interface. Checks contain the *judgement* (thresholds, severity); the
 * gateway contains the *facts*. That split is what makes the checks testable
 * without a WordPress installation, and it is why fixtures are just another
 * implementation (ArrayGateway).
 *
 * Implementations MUST NOT write anything. Ever.
 */
interface StoreGateway
{
    /** Unix timestamp used as "now" for every age calculation in the audit. */
    public function now(): int;

    /**
     * @return array{
     *   wordpress_version:string, woocommerce_version:?string, woocommerce_active:bool,
     *   woocommerce_db_version:?string, hpos_enabled:bool, php_version:string,
     *   database_version:string, wp_memory_limit:string, php_memory_limit:string,
     *   wp_debug:bool, wp_cron_disabled:bool, site_url:string, https:bool,
     *   timezone:string, active_theme:string, active_plugins_count:int,
     *   woocommerce_plugins:list<string>, db_prefix:string
     * }
     */
    public function environment(): array;

    /**
     * @return array{
     *   disabled:bool, alternate:bool, total_events:int, overdue:list<array{hook:string,timestamp:int,delay:int}>,
     *   next_events:list<array{hook:string,timestamp:int}>, woocommerce_hooks:int,
     *   doing_cron_stale:?int
     * }
     */
    public function cron(): array;

    /** True when Action Scheduler is present and queryable. */
    public function actionSchedulerAvailable(): bool;

    /**
     * @return array{total:int, oldest:?int, newest:?int, by_hook:array<string,int>, by_group:array<string,int>}
     */
    public function failedActions(): array;

    /**
     * Pending actions whose scheduled date is already in the past.
     *
     * @return array{total:int, oldest_delay:int, median_delay:int, by_hook:array<string,int>, by_group:array<string,int>, sample:list<array{hook:string,delay:int}>}
     */
    public function pastDueActions(): array;

    /**
     * Aggregated order data for one status. No PII is returned by design.
     *
     * @param string   $status      WooCommerce status slug without the wc- prefix.
     * @param int|null $sinceDays   Restrict to the last N days, or null for all time.
     * @return array{
     *   count:int, total_value:float, currency:string,
     *   by_payment_method:array<string,int>, by_age_bucket:array<string,int>,
     *   oldest:list<array{id:int|string,date:string,age:int,amount:float,currency:string,payment_method:string,status:string}>
     * }
     */
    public function orders(string $status, ?int $sinceDays = null): array;

    /**
     * @return array<string, array{exists:bool, rows:int, data_size:int, index_size:int, total_size:int}>
     */
    public function tables(): array;
}
