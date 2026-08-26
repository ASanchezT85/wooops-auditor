<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 01 — WooCommerce environment facts and obvious environment problems.
 * Not a security scanner. Never reports secrets.
 */
final class EnvironmentCheck implements CheckInterface
{
    /** Bytes. Below this WooCommerce admin screens routinely fatal. */
    private const MEMORY_LOW = 268435456;      // 256M
    private const MEMORY_CRITICAL = 134217728; // 128M
    private const MEMORY_UNLIMITED = -1;

    public function key(): string { return 'environment'; }
    public function title(): string { return 'WooCommerce Environment'; }

    public function run(StoreGateway $store): array
    {
        $env = $store->environment();
        $findings = [];

        if (!$env['woocommerce_active']) {
            $findings[] = new Finding(
                'environment.woocommerce.inactive',
                'environment',
                Severity::CRITICAL,
                'WooCommerce is not active',
                'WooCommerce could not be detected as an active plugin on this site.',
                'Every other operational check in this audit assumes WooCommerce is running. Without it the results below are meaningless.',
                'Confirm the plugin is installed and activated, and that no fatal error is preventing it from loading.',
                ['woocommerce_version' => $env['woocommerce_version']]
            );

            // Nothing else in this check is meaningful without Woo.
            return ['findings' => $findings, 'data' => $env];
        }

        if ($env['woocommerce_db_version'] !== null
            && $env['woocommerce_version'] !== null
            && version_compare($env['woocommerce_db_version'], $env['woocommerce_version'], '<')
        ) {
            $findings[] = new Finding(
                'environment.woocommerce.db_outdated',
                'environment',
                Severity::HIGH,
                'WooCommerce database update pending',
                sprintf(
                    'The WooCommerce database schema (%s) is older than the installed plugin (%s).',
                    $env['woocommerce_db_version'],
                    $env['woocommerce_version']
                ),
                'WooCommerce runs data migrations after an update. Until they finish, lookup tables, order data and reports can be inconsistent.',
                'Open WooCommerce > Status > Tools, or run the scheduled database update, during a maintenance window.',
                [
                    'db_version'     => $env['woocommerce_db_version'],
                    'plugin_version' => $env['woocommerce_version'],
                ]
            );
        }

        // What matters is the limit PHP will actually enforce. WordPress only
        // ever raises the limit, never lowers it, so a default WP_MEMORY_LIMIT
        // of 40M against an unlimited PHP limit is not a problem — and flagging
        // it would fire on a large share of perfectly healthy stores.
        $memory = $this->effectiveMemory($env['php_memory_limit'], $env['wp_memory_limit']);
        $evidence = [
            'wp_memory_limit' => $env['wp_memory_limit'],
            'php_memory_limit' => $env['php_memory_limit'],
            'effective_limit' => $memory === self::MEMORY_UNLIMITED ? 'unlimited' : $memory,
        ];

        if ($memory !== self::MEMORY_UNLIMITED && $memory > 0 && $memory < self::MEMORY_CRITICAL) {
            $findings[] = new Finding(
                'environment.memory.low',
                'environment',
                Severity::HIGH,
                'Effective memory limit is very low',
                sprintf(
                    'The effective PHP memory limit is %s (WP_MEMORY_LIMIT %s, php memory_limit %s).',
                    Format::bytes($memory),
                    $env['wp_memory_limit'],
                    $env['php_memory_limit']
                ),
                'WooCommerce background jobs and admin reports are memory hungry. Low limits show up as blank screens and silently killed scheduled actions.',
                'Raise the PHP memory_limit to at least 256M, and WP_MEMORY_LIMIT with it.',
                $evidence
            );
        } elseif ($memory !== self::MEMORY_UNLIMITED && $memory > 0 && $memory < self::MEMORY_LOW) {
            $findings[] = new Finding(
                'environment.memory.below_recommended',
                'environment',
                Severity::LOW,
                'Effective memory limit below the recommended 256M',
                sprintf('The effective PHP memory limit is %s.', Format::bytes($memory)),
                'WooCommerce recommends 256M for stores of any real size.',
                'Consider raising the PHP memory_limit to 256M.',
                $evidence
            );
        }

        if (!$env['https']) {
            $local = $this->isLocalHost($env['site_url']);
            $findings[] = new Finding(
                'environment.https.missing',
                'environment',
                $local ? Severity::INFO : Severity::HIGH,
                $local ? 'Site URL is not HTTPS (local or staging host)' : 'Site URL is not HTTPS',
                sprintf('The configured site URL is %s.', $env['site_url']),
                'Checkout, payment gateway callbacks and most modern payment methods require HTTPS.',
                $local
                    ? 'Expected on a local or staging hostname. Confirm the production site is served over HTTPS.'
                    : 'Install a certificate and move the site URL to https://.',
                ['site_url' => $env['site_url'], 'local_host' => $local]
            );
        }

        if ($env['wp_debug']) {
            $findings[] = new Finding(
                'environment.wp_debug.enabled',
                'environment',
                Severity::LOW,
                'WP_DEBUG is enabled',
                'WP_DEBUG is on. On a production store this can leak notices into responses and grow debug.log without bound.',
                'Debug output on a live storefront is both a disclosure risk and a disk-space risk.',
                'Disable WP_DEBUG on production, or at least set WP_DEBUG_DISPLAY to false.',
                ['wp_debug' => true]
            );
        }

        if ($findings === []) {
            $findings[] = new Finding(
                'environment.ok',
                'environment',
                Severity::PASS,
                'No obvious environment problems',
                'WooCommerce is active and no environment issue was detected by this check.',
                '',
                '',
                ['woocommerce_version' => $env['woocommerce_version'], 'hpos_enabled' => $env['hpos_enabled']]
            );
        }

        return ['findings' => $findings, 'data' => $env];
    }

    /**
     * The limit PHP will really enforce: WordPress raises the limit when
     * WP_MEMORY_LIMIT is higher than the ini value, and never lowers it.
     */
    private function effectiveMemory(string $php, string $wp): int
    {
        $phpBytes = $this->toBytes($php);
        if ($phpBytes === self::MEMORY_UNLIMITED) {
            return self::MEMORY_UNLIMITED;
        }

        return max($phpBytes, $this->toBytes($wp));
    }

    /** Local and staging hostnames, where plain HTTP is expected. */
    private function isLocalHost(string $siteUrl): bool
    {
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach (['.test', '.local', '.localhost', '.example', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** "256M" -> bytes. -1 means unlimited; 0 means unknown. */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return self::MEMORY_UNLIMITED;
        }
        if ($value === '') {
            return 0;
        }
        $unit = strtoupper(substr($value, -1));
        $number = (float) $value;
        return (int) match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
