<?php
declare(strict_types=1);

namespace WooOps\Auditor\Report;

use WooOps\Auditor\Audit\AuditResult;

/**
 * Standalone HTML. No external CSS, JS, fonts, CDNs or tracking: the file must
 * open from a laptop with no network and print cleanly.
 */
final class HtmlReporter implements ReporterInterface
{
    public function __construct(private ?string $template = null)
    {
        $this->template ??= dirname(__DIR__, 2) . '/templates/report.php';
    }

    public function render(AuditResult $result): string
    {
        ob_start();
        (static function (string $template, AuditResult $result): void {
            require $template;
        })($this->template, $result);

        return (string) ob_get_clean();
    }

    public function extension(): string
    {
        return 'html';
    }
}
