<?php
declare(strict_types=1);

namespace WooOps\Auditor\Support;

use RuntimeException;

/**
 * Writes reports to a known, protected location.
 *
 * Reports contain operational detail about the store, so the default directory
 * is hardened against public access the same way WooCommerce protects its own
 * logs: deny rules for Apache, an index file, and a hard-to-guess suffix on
 * the directory name.
 */
final class ReportWriter
{
    public function __construct(private string $directory)
    {
    }

    public static function default(): self
    {
        $base = sys_get_temp_dir();
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (empty($uploads['error'])) {
                $base = $uploads['basedir'];
            }
        }

        return new self(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'wooops-audit');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return string absolute path written */
    public function write(string $filename, string $contents): string
    {
        $this->prepare();
        $path = $this->directory . DIRECTORY_SEPARATOR . basename($filename);

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write report to {$path}");
        }

        @chmod($path, 0640);

        return $path;
    }

    private function prepare(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Could not create report directory {$this->directory}");
        }

        $htaccess = $this->directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }

        $index = $this->directory . DIRECTORY_SEPARATOR . 'index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }
    }
}
