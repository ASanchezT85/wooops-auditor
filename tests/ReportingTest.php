<?php
declare(strict_types=1);

namespace WooOps\Auditor\Tests;

use PHPUnit\Framework\TestCase;
use WooOps\Auditor\Audit\AuditResult;
use WooOps\Auditor\Audit\AuditRunner;
use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\HealthScore;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Checks\CheckInterface;
use WooOps\Auditor\Report\HtmlReporter;
use WooOps\Auditor\Report\JsonReporter;
use WooOps\Auditor\Store\StoreGateway;

final class ReportingTest extends TestCase
{
    public function testHealthScoreIsOneHundredForAHealthyStore(): void
    {
        $result = (new AuditRunner())->run(Fixtures::healthy());
        self::assertSame(100, $result->score());
        self::assertSame([], $result->actionable());
    }

    public function testHealthScoreDropsForATroubledStoreAndNeverGoesNegative(): void
    {
        $result = (new AuditRunner())->run(Fixtures::troubledStore());

        self::assertLessThan(100, $result->score());
        self::assertGreaterThanOrEqual(0, $result->score());
    }

    public function testScoreCannotGoNegative(): void
    {
        $findings = [];
        for ($i = 0; $i < 40; $i++) {
            $findings[] = new Finding("x.{$i}", 'cat' . $i, Severity::CRITICAL, 't', 's');
        }

        self::assertSame(0, HealthScore::calculate($findings));
    }

    public function testOnlyTheWorstFindingOfACategoryCounts(): void
    {
        $findings = [];
        for ($i = 0; $i < 10; $i++) {
            $findings[] = new Finding("x.{$i}", 'action_scheduler', Severity::CRITICAL, 't', 's');
        }
        $findings[] = new Finding('y.1', 'action_scheduler', Severity::LOW, 't', 's');

        // One category, worst finding CRITICAL: a flat -25, however many
        // findings that category emitted.
        self::assertSame(75, HealthScore::calculate($findings));
    }

    public function testFindingsAreOrderedBySeverity(): void
    {
        $result = (new AuditRunner())->run(Fixtures::troubledStore());

        $ranks = array_map(
            static fn (Finding $f) => Severity::rank($f->severity),
            $result->sortedFindings()
        );

        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);
    }

    public function testAllSevenChecksRunAndAppearInTheReport(): void
    {
        $result = (new AuditRunner())->run(Fixtures::troubledStore());

        self::assertSame([
            'environment',
            'cron',
            'action_scheduler_failed',
            'action_scheduler_past_due',
            'pending_orders',
            'failed_orders',
            'database',
        ], array_keys($result->checks));
        self::assertSame([], $result->errors);
    }

    public function testABrokenCheckDoesNotLoseTheRestOfTheAudit(): void
    {
        $broken = new class implements CheckInterface {
            public function key(): string
            {
                return 'broken';
            }

            public function title(): string
            {
                return 'Broken';
            }

            public function run(StoreGateway $store): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $result = (new AuditRunner([$broken, ...AuditRunner::defaultChecks()]))->run(Fixtures::healthy());

        self::assertCount(1, $result->errors);
        self::assertSame('broken', $result->errors[0]['check']);
        self::assertArrayHasKey('environment', $result->checks);
    }

    public function testJsonIsValidAndCarriesTheVersionedSchema(): void
    {
        $json = (new JsonReporter())->render((new AuditRunner())->run(Fixtures::troubledStore()));
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['metadata', 'environment', 'score', 'summary', 'findings', 'checks', 'errors'],
            array_keys($decoded)
        );
        self::assertSame(AuditResult::SCHEMA_VERSION, $decoded['metadata']['schema_version']);
        self::assertSame(AuditRunner::VERSION, $decoded['metadata']['auditor_version']);
        self::assertTrue($decoded['metadata']['read_only']);
        self::assertIsInt($decoded['metadata']['timestamp']);

        foreach ($decoded['findings'] as $finding) {
            self::assertSame(
                ['id', 'category', 'severity', 'title', 'summary', 'technical_details', 'why_it_matters', 'recommended_action', 'evidence'],
                array_keys($finding)
            );
        }
    }

    public function testHtmlIsGeneratedAndStandalone(): void
    {
        $html = (new HtmlReporter())->render((new AuditRunner())->run(Fixtures::troubledStore()));

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('WooOps Audit', $html);
        self::assertStringContainsString('43 scheduled action(s)', $html);

        // No network: no external stylesheet, script, font or image.
        self::assertDoesNotMatchRegularExpression('#<(script|link|img)[^>]+(src|href)=["\']https?://#i', $html);
        self::assertStringNotContainsString('cdn.', $html);
        self::assertStringNotContainsString('fonts.googleapis', $html);
    }

    public function testHtmlEscapesFindingContent(): void
    {
        $evil = new class implements CheckInterface {
            public function key(): string
            {
                return 'evil';
            }

            public function title(): string
            {
                return 'Evil';
            }

            public function run(StoreGateway $store): array
            {
                return [
                    'findings' => [new Finding(
                        'evil.xss',
                        'evil',
                        Severity::HIGH,
                        '<script>alert(1)</script>',
                        'summary "quoted" & <b>bold</b>',
                        '',
                        '',
                        ['hook' => '<img src=x onerror=alert(2)>']
                    )],
                    'data' => [],
                ];
            }
        };

        $html = (new HtmlReporter())->render((new AuditRunner([$evil]))->run(Fixtures::healthy()));

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
