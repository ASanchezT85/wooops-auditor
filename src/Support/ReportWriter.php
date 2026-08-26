<?php
declare(strict_types=1);

namespace WooOps\Auditor\Support;

use RuntimeException;

/**
 * Writes a rendered report to a file.
 *
 * Used only when someone explicitly asked for a file — that means WP-CLI, and
 * nothing else. The admin screen streams reports from memory and never comes
 * near this class.
 *
 * The default directory is a private one under the system temp directory, not
 * wp-content/uploads. Reports describe the operational weaknesses of a live
 * store; the previous default put them inside the web root and relied on an
 * .htaccess deny rule, which does nothing on nginx. Rendering and persisting
 * are now separate decisions, and persisting is opt-in.
 */
final class ReportWriter
{
    private const DIR_MODE = 0700;
    private const FILE_MODE = 0600;

    public function __construct(private string $directory)
    {
    }

    /**
     * A private directory outside the web root.
     *
     * Deliberately does NOT consult wp_upload_dir(): uploads is web-accessible
     * by design, and an audit report is not something to leave there.
     */
    public static function default(): self
    {
        return new self(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wooops-audit');
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

        @chmod($path, self::FILE_MODE);

        return $path;
    }

    private function prepare(): void
    {
        if (!is_dir($this->directory)
            && !mkdir($this->directory, self::DIR_MODE, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException("Could not create report directory {$this->directory}");
        }
    }
}
