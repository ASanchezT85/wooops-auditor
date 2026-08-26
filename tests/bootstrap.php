<?php
declare(strict_types=1);

// The plugin runs inside WordPress, the tests do not. Everything under test is
// deliberately free of WordPress globals, so a plain PSR-4 loader is enough.
spl_autoload_register(static function (string $class): void {
    foreach (['WooOps\\Auditor\\Tests\\' => __DIR__, 'WooOps\\Auditor\\' => dirname(__DIR__) . '/src'] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $path = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($path)) {
                require $path;
            }

            return;
        }
    }
});
