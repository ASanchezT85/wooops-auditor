<?php
declare(strict_types=1);

namespace WooOps\Auditor\Report;

use InvalidArgumentException;
use WooOps\Auditor\Audit\AuditResult;

/**
 * A rendered report ready to be streamed to an authenticated browser.
 *
 * Pure value object: it holds the bytes and the headers, and knows nothing
 * about WordPress or the filesystem. That is the point — the admin screen
 * delivers reports from memory, so a report generated for download never
 * becomes a file sitting under a web-accessible directory.
 */
final class ReportResponse
{
    public const FORMATS = ['html', 'json'];

    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $body,
    ) {
    }

    public static function create(string $format, AuditResult $result): self
    {
        $reporter = match ($format) {
            'html' => new HtmlReporter(),
            'json' => new JsonReporter(),
            default => throw new InvalidArgumentException("Unsupported report format: {$format}"),
        };

        $contentType = $format === 'json'
            ? 'application/json; charset=utf-8'
            : 'text/html; charset=utf-8';

        return new self(
            self::filename($format, $result->timestamp),
            $contentType,
            $reporter->render($result)
        );
    }

    public static function filename(string $format, int $timestamp): string
    {
        return sprintf('wooops-audit-%s.%s', gmdate('Y-m-d-His', $timestamp), $format);
    }

    /**
     * Headers for an attachment download.
     *
     * nosniff matters here: report bodies quote hook and table names that come
     * from third-party plugins. They are escaped by the template, and the file
     * is served as an attachment, but there is no reason to let a browser
     * second-guess the content type either.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'Content-Type: ' . $this->contentType,
            'Content-Disposition: attachment; filename="' . $this->filename . '"',
            'Content-Length: ' . strlen($this->body),
            'X-Content-Type-Options: nosniff',
        ];
    }
}
