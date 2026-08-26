<?php
declare(strict_types=1);

/**
 * Regenerates examples/sample-report.{html,json} from the demo fixture.
 * Usage: php bin/generate-sample.php
 */

require __DIR__ . '/../tests/bootstrap.php';

use WooOps\Auditor\Audit\AuditRunner;
use WooOps\Auditor\Report\HtmlReporter;
use WooOps\Auditor\Report\JsonReporter;
use WooOps\Auditor\Tests\Fixtures;

$result = (new AuditRunner())->run(Fixtures::troubledStore());
$dir = __DIR__ . '/../examples';

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($dir . '/sample-report.json', (new JsonReporter())->render($result));
file_put_contents($dir . '/sample-report.html', (new HtmlReporter())->render($result));

printf("Sample report written. Score: %d/100, %d findings (%d actionable).\n",
    $result->score(),
    count($result->findings),
    count($result->actionable())
);
