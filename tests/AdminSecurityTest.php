<?php
declare(strict_types=1);

namespace WooOps\Auditor\Tests;

use PHPUnit\Framework\TestCase;
use WooOps\Auditor\Admin\Page;
use WooOps\Auditor\Audit\AuditResult;
use WooOps\Auditor\Report\ReportResponse;
use WooOps\Auditor\Store\ArrayGateway;

/**
 * The hardening guarantees: reports generated from the admin screen require a
 * capability and a nonce, are streamed from memory, and never become files.
 */
final class AdminSecurityTest extends TestCase
{
    private WordPressState $wp;

    protected function setUp(): void
    {
        $this->wp = WordPressState::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    private function page(): TestablePage
    {
        return new TestablePage(Fixtures::troubledStore());
    }

    // --- authorization ------------------------------------------------------

    public function testDownloadWithoutTheCapabilityIsDenied(): void
    {
        $this->wp->userCan = false;
        $_GET['format'] = 'html';

        $this->expectException(WordPressDied::class);

        try {
            $this->page()->handleDownload();
        } finally {
            self::assertSame('', $this->wp->output, 'no report body may be emitted');
            self::assertSame([], $this->wp->headers, 'no headers may be sent');
        }
    }

    public function testRunWithoutTheCapabilityIsDeniedAndStoresNothing(): void
    {
        $this->wp->userCan = false;

        try {
            $this->page()->handleRun();
            self::fail('expected a denial');
        } catch (WordPressDied) {
            self::assertArrayNotHasKey(Page::OPTION, $this->wp->options);
        }
    }

    public function testCapabilityAloneIsNotEnoughForDownload(): void
    {
        $this->wp->userCan = true;
        $this->wp->nonceValid = false;
        $_GET['format'] = 'html';

        $this->expectException(WordPressDied::class);

        try {
            $this->page()->handleDownload();
        } finally {
            self::assertSame('', $this->wp->output);
        }
    }

    public function testCapabilityAloneIsNotEnoughForRun(): void
    {
        $this->wp->userCan = true;
        $this->wp->nonceValid = false;

        try {
            $this->page()->handleRun();
            self::fail('expected a denial');
        } catch (WordPressDied) {
            self::assertArrayNotHasKey(Page::OPTION, $this->wp->options);
        }
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $_GET['format'] = 'pdf';

        $this->expectException(WordPressDied::class);
        $this->page()->handleDownload();
    }

    // --- delivery -----------------------------------------------------------

    public function testHtmlDownloadStreamsTheReportWithTheRightHeaders(): void
    {
        $_GET['format'] = 'html';
        $page = $this->page();
        $page->handleDownload();

        self::assertStringStartsWith('<!DOCTYPE html>', $this->wp->output);
        self::assertStringContainsString('WooOps Audit', $this->wp->output);

        self::assertContains('Content-Type: text/html; charset=utf-8', $this->wp->headers);
        self::assertContains('X-Content-Type-Options: nosniff', $this->wp->headers);
        self::assertContains('Content-Length: ' . strlen($this->wp->output), $this->wp->headers);
        self::assertTrue($this->wp->terminated);

        $disposition = $this->header('Content-Disposition');
        self::assertMatchesRegularExpression(
            '/^Content-Disposition: attachment; filename="wooops-audit-\d{4}-\d{2}-\d{2}-\d{6}\.html"$/',
            $disposition
        );
    }

    public function testJsonDownloadStreamsValidJsonWithTheRightHeaders(): void
    {
        $_GET['format'] = 'json';
        $this->page()->handleDownload();

        self::assertContains('Content-Type: application/json; charset=utf-8', $this->wp->headers);
        self::assertStringContainsString('.json"', $this->header('Content-Disposition'));

        $decoded = json_decode($this->wp->output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(AuditResult::SCHEMA_VERSION, $decoded['metadata']['schema_version']);
    }

    public function testTheDownloadIsMarkedNoCache(): void
    {
        $_GET['format'] = 'json';
        $this->page()->handleDownload();

        self::assertNotEmpty(array_filter(
            $this->wp->headers,
            static fn (string $h) => str_starts_with($h, 'Cache-Control:')
        ));
    }

    // --- persistence --------------------------------------------------------

    public function testTheAdminFlowWritesNoFilesAnywhere(): void
    {
        $before = $this->snapshot();

        $page = $this->page();
        $page->handleRun();

        $_GET['format'] = 'html';
        $this->page()->handleDownload();
        $_GET['format'] = 'json';
        $this->page()->handleDownload();

        self::assertSame($before, $this->snapshot(), 'the admin flow must not create report files');
    }

    public function testStoredMetadataCarriesNoPathsOrReportBody(): void
    {
        $this->page()->handleRun();

        $stored = $this->wp->options[Page::OPTION];

        self::assertSame(['timestamp', 'score', 'summary'], array_keys($stored));
        self::assertSame(Fixtures::NOW, $stored['timestamp']);
        self::assertIsInt($stored['score']);

        $serialized = strtolower(json_encode($stored));
        foreach (['path', 'file', 'http', 'uploads', '.html', '.json', 'doctype'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function testTheAdminScreenCannotWriteReportsByConstruction(): void
    {
        // A belt-and-braces static guarantee: even a future edit that reaches
        // for the filesystem from the admin path fails this test.
        $source = file_get_contents(dirname(__DIR__) . '/src/Admin/Page.php');

        foreach (['ReportWriter', 'file_put_contents', 'fopen', 'wp_upload_dir', 'readfile'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    // --- the response object ------------------------------------------------

    public function testReportResponseNamesTheFileAfterTheAuditTimestamp(): void
    {
        self::assertSame(
            'wooops-audit-2026-08-26-120000.html',
            ReportResponse::filename('html', Fixtures::NOW)
        );
    }

    public function testReportResponseRejectsAnythingButHtmlAndJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportResponse::create('csv', (new \WooOps\Auditor\Audit\AuditRunner())->run(Fixtures::healthy()));
    }

    // --- helpers ------------------------------------------------------------

    private function header(string $name): string
    {
        foreach ($this->wp->headers as $header) {
            if (str_starts_with($header, $name . ':')) {
                return $header;
            }
        }

        self::fail("Header {$name} was not sent. Sent: " . implode(' | ', $this->wp->headers));
    }

    /** Every file in the places a report could plausibly land. */
    private function snapshot(): array
    {
        $paths = [];
        foreach ([sys_get_temp_dir() . '/wooops-audit', getcwd(), dirname(__DIR__) . '/examples'] as $dir) {
            if (is_dir($dir)) {
                $paths[$dir] = scandir($dir) ?: [];
            }
        }

        return $paths;
    }
}

/**
 * The admin page with its three side-effecting seams captured instead of
 * performed: header(), echo and exit.
 */
final class TestablePage extends Page
{
    public function __construct(private ArrayGateway $store)
    {
        parent::__construct($store);
    }

    protected function sendHeader(string $header): void
    {
        WordPressState::get()->headers[] = $header;
    }

    protected function emit(string $body): void
    {
        WordPressState::get()->output .= $body;
    }

    protected function terminate(): void
    {
        WordPressState::get()->terminated = true;
    }
}
