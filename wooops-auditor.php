<?php
/**
 * Plugin Name: WooOps Auditor
 * Description: Read-only operational diagnostics for WooCommerce stores. Inspects and reports; never modifies the store.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: WooOps
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wooops-auditor
 *
 * @package WooOps\Auditor
 *
 * Copyright (C) 2026 Alexander J. Sanchez T.
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WOOOPS_AUDITOR_VERSION', '0.1.0');
define('WOOOPS_AUDITOR_FILE', __FILE__);

/**
 * Composer autoload when the plugin was installed with dependencies, otherwise
 * a plain PSR-4 loader so the plugin works from a bare checkout.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WooOps\\Auditor\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) {
            require $path;
        }
    });
}

// Declare HPOS compatibility: the auditor reads both storage layouts.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            WOOOPS_AUDITOR_FILE,
            true
        );
    }
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wooops audit', \WooOps\Auditor\WPCLI\AuditCommand::class);
}

if (is_admin()) {
    \WooOps\Auditor\Admin\Page::register();
}
