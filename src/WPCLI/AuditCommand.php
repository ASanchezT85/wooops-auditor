<?php
declare(strict_types=1);

namespace WooOps\Auditor\WPCLI;

use WP_CLI;
use WooOps\Auditor\Audit\AuditResult;
use WooOps\Auditor\Audit\AuditRunner;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Report\HtmlReporter;
use WooOps\Auditor\Report\JsonReporter;
use WooOps\Auditor\Store\WordPressGateway;
use WooOps\Auditor\Support\ReportWriter;

/**
 * Read-only WooCommerce operational audit.
 *
 * Reports are only written when the operator asks for a file. Without
 * --output they go to a private directory under the system temp directory,
 * never into wp-content/uploads: an audit report describes how a live store
 * is failing and has no business sitting in the web root.
 */
final class AuditCommand
{
    /**
     * Runs the seven read-only checks and reports the result.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - html
     *   - both
     * ---
     *
     * [--output=<path>]
     * : Write the report to this path instead of the default private
     * directory. With --format=both, this is treated as a directory. The
     * operator is responsible for choosing a location that is not served by
     * the web server.
     *
     * [--stdout]
     * : Print the report to STDOUT instead of writing a file. Only valid with
     * --format=json or --format=html.
     *
     * ## EXAMPLES
     *
     *     wp wooops audit
     *     wp wooops audit --format=json
     *     wp wooops audit --format=html --output=/tmp/report.html
     *     wp wooops audit --format=both
     *
     * @when after_wp_load
     */
    public function __invoke(array $args, array $assoc): void
    {
        $format = $assoc['format'] ?? 'table';

        $result = (new AuditRunner())->run(WordPressGateway::create());

        if ($format === 'table') {
            $this->printSummary($result);

            return;
        }

        $stamp = gmdate('Y-m-d', $result->timestamp);
        $writer = isset($assoc['output']) && $format !== 'both' && !isset($assoc['stdout'])
            ? new ReportWriter(dirname((string) $assoc['output']))
            : ReportWriter::default();

        if (isset($assoc['output']) && $format === 'both') {
            $writer = new ReportWriter(rtrim((string) $assoc['output'], '/\\'));
        }

        $reporters = match ($format) {
            'json' => ['json' => new JsonReporter()],
            'html' => ['html' => new HtmlReporter()],
            'both' => ['json' => new JsonReporter(), 'html' => new HtmlReporter()],
            default => WP_CLI::error("Unknown format: {$format}"),
        };

        foreach ($reporters as $reporter) {
            $contents = $reporter->render($result);

            if (isset($assoc['stdout'])) {
                WP_CLI::line($contents);
                continue;
            }

            $filename = isset($assoc['output']) && $format !== 'both'
                ? basename((string) $assoc['output'])
                : sprintf('wooops-audit-%s.%s', $stamp, $reporter->extension());

            $path = $writer->write($filename, $contents);
            WP_CLI::success("Report written to {$path}");
            WP_CLI::debug('Reports are never written under wp-content/uploads.', 'wooops');
        }
    }

    private function printSummary(AuditResult $result): void
    {
        WP_CLI::line('');
        WP_CLI::line(sprintf('WooOps Audit — health score %d/100', $result->score()));

        $summary = $result->summary();
        $line = [];
        foreach (Severity::ORDER as $level) {
            $line[] = sprintf('%s: %d', $level, $summary[$level]);
        }
        WP_CLI::line(implode('   ', $line));
        WP_CLI::line('');

        $rows = [];
        foreach ($result->actionable() as $finding) {
            $rows[] = [
                'severity' => $finding->severity,
                'id' => $finding->id,
                'summary' => $finding->summary,
            ];
        }

        if ($rows === []) {
            WP_CLI::success('No actionable findings.');
        } else {
            WP_CLI\Utils\format_items('table', $rows, ['severity', 'id', 'summary']);
        }

        if ($result->errors !== []) {
            WP_CLI::warning(sprintf('%d check(s) failed to run; this audit is incomplete.', count($result->errors)));
        }
    }
}
