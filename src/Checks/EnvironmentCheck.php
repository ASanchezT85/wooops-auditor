<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;

/**
 * Check 01 — WooCommerce environment facts and obvious environment problems.
 * Not a security scanner. Never reports secrets.
 */
final class EnvironmentCheck implements CheckInterface
{
    /** Bytes. Below this WooCommerce admin screens routinely fatal. */
    private const MEMORY_LOW = 268435456;   // 256M
    private const MEMORY_CRITICAL = 134217728; // 128M

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

        $memory = $this->toBytes($env['wp_memory_limit']);
        if ($memory > 0 && $memory < self::MEMORY_CRITICAL) {
            $findings[] = new Finding(
                'environment.memory.low',
                'environment',
                Severity::HIGH,
                'WordPress memory limit is very low',
                sprintf('WP_MEMORY_LIMIT is %s.', $env['wp_memory_limit']),
                'WooCommerce background jobs and admin reports are memory hungry. Low limits show up as blank screens and silently killed scheduled actions.',
                'Raise WP_MEMORY_LIMIT to at least 256M in wp-config.php, and confirm the PHP limit allows it.',
                ['wp_memory_limit' => $env['wp_memory_limit'], 'php_memory_limit' => $env['php_memory_limit']]
            );
        } elseif ($memory > 0 && $memory < self::MEMORY_LOW) {
            $findings[] = new Finding(
                'environment.memory.below_recommended',
                'environment',
                Severity::LOW,
                'WordPress memory limit below the recommended 256M',
                sprintf('WP_MEMORY_LIMIT is %s.', $env['wp_memory_limit']),
                'WooCommerce recommends 256M for stores of any real size.',
                'Consider raising WP_MEMORY_LIMIT to 256M.',
                ['wp_memory_limit' => $env['wp_memory_limit']]
            );
        }

        if (!$env['https']) {
            $findings[] = new Finding(
                'environment.https.missing',
                'environment',
                Severity::HIGH,
                'Site URL is not HTTPS',
                sprintf('The configured site URL is %s.', $env['site_url']),
                'Checkout, payment gateway callbacks and most modern payment methods require HTTPS.',
                'Install a certificate and move the site URL to https://.',
                ['site_url' => $env['site_url']]
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

    /** "256M" -> bytes. Returns 0 for -1/unknown so callers can skip the check. */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
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
