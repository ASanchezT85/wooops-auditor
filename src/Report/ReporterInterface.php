<?php
declare(strict_types=1);

namespace WooOps\Auditor\Report;

use WooOps\Auditor\Audit\AuditResult;

interface ReporterInterface
{
    public function render(AuditResult $result): string;

    /** File extension without the dot. */
    public function extension(): string;
}
