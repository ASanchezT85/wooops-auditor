<?php
declare(strict_types=1);

namespace WooOps\Auditor\Report;

use WooOps\Auditor\Audit\AuditResult;

final class JsonReporter implements ReporterInterface
{
    public function render(AuditResult $result): string
    {
        return json_encode(
            $result->jsonSerialize(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function extension(): string
    {
        return 'json';
    }
}
