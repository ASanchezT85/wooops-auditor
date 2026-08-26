<?php
declare(strict_types=1);

namespace WooOps\Auditor\Tests;

use PHPUnit\Framework\TestCase;
use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Checks\ActionSchedulerFailedCheck;
use WooOps\Auditor\Checks\ActionSchedulerPastDueCheck;
use WooOps\Auditor\Checks\CheckInterface;
use WooOps\Auditor\Checks\CronCheck;
use WooOps\Auditor\Checks\DatabaseCheck;
use WooOps\Auditor\Checks\EnvironmentCheck;
use WooOps\Auditor\Checks\FailedOrdersCheck;
use WooOps\Auditor\Checks\PendingOrdersCheck;
use WooOps\Auditor\Store\ArrayGateway;

final class ChecksTest extends TestCase
{
    /** @return list<Finding> */
    private function findings(CheckInterface $check, array $overrides = []): array
    {
        return $check->run(new ArrayGateway($overrides, Fixtures::NOW))['findings'];
    }

    private function ids(array $findings): array
    {
        return array_map(static fn (Finding $f) => $f->id, $findings);
    }

    private function severityOf(array $findings, string $id): string
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding->severity;
            }
        }
        self::fail("Finding {$id} not present. Got: " . implode(', ', $this->ids($findings)));
    }

    // --- 01 Environment -----------------------------------------------------

    public function testWooActiveAndHealthyPasses(): void
    {
        $findings = $this->findings(new EnvironmentCheck());
        self::assertSame(['environment.ok'], $this->ids($findings));
        self::assertSame(Severity::PASS, $findings[0]->severity);
    }

    public function testWooMissingIsCriticalAndStopsTheCheck(): void
    {
        $findings = $this->findings(new EnvironmentCheck(), [
            'environment' => ['woocommerce_active' => false, 'woocommerce_version' => null, 'https' => false],
        ]);

        // Only the WooCommerce finding: everything else would be noise.
        self::assertSame(['environment.woocommerce.inactive'], $this->ids($findings));
        self::assertSame(Severity::CRITICAL, $findings[0]->severity);
    }

    public function testHposDisabledIsNotAProblem(): void
    {
        $findings = $this->findings(new EnvironmentCheck(), ['environment' => ['hpos_enabled' => false]]);
        self::assertSame(['environment.ok'], $this->ids($findings));
        self::assertFalse($findings[0]->evidence['hpos_enabled']);
    }

    public function testOutdatedDatabaseSchemaAndLowMemoryAreReported(): void
    {
        $findings = $this->findings(new EnvironmentCheck(), [
            'environment' => [
                'woocommerce_db_version' => '8.7.0',
                'wp_memory_limit' => '64M',
                'php_memory_limit' => '64M',
                'https' => false,
                'site_url' => 'http://shop.example.com',
            ],
        ]);

        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'environment.woocommerce.db_outdated'));
        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'environment.memory.low'));
        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'environment.https.missing'));
    }

    public function testDefaultMemoryLimitAgainstAnUnlimitedPhpLimitIsNotFlagged(): void
    {
        // Found on a real staging store: WordPress defaults WP_MEMORY_LIMIT to
        // 40M and only ever raises the ini limit, never lowers it. Judging the
        // WordPress constant alone flagged HIGH on a perfectly healthy store.
        $findings = $this->findings(new EnvironmentCheck(), [
            'environment' => ['wp_memory_limit' => '40M', 'php_memory_limit' => '-1'],
        ]);

        self::assertSame(['environment.ok'], $this->ids($findings));
    }

    public function testTheEffectiveLimitIsTheHigherOfTheTwo(): void
    {
        $findings = $this->findings(new EnvironmentCheck(), [
            'environment' => ['wp_memory_limit' => '512M', 'php_memory_limit' => '96M'],
        ]);

        self::assertSame(['environment.ok'], $this->ids($findings));
    }

    public function testHttpOnALocalOrStagingHostnameIsOnlyInformational(): void
    {
        foreach (['http://wooops-staging.test', 'http://localhost', 'http://shop.local'] as $url) {
            $findings = $this->findings(new EnvironmentCheck(), [
                'environment' => ['https' => false, 'site_url' => $url],
            ]);

            self::assertSame(Severity::INFO, $this->severityOf($findings, 'environment.https.missing'), $url);
        }
    }

    public function testHttpOnARealHostnameStaysHigh(): void
    {
        $findings = $this->findings(new EnvironmentCheck(), [
            'environment' => ['https' => false, 'site_url' => 'http://shop.example.com'],
        ]);

        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'environment.https.missing'));
    }

    public function testNoSecretsLeakIntoTheEnvironmentPayload(): void
    {
        $data = (new EnvironmentCheck())->run(Fixtures::troubledStore())['data'];
        $serialized = strtolower(json_encode($data));

        foreach (['secret', 'password', 'api_key', 'token', 'salt', 'auth_key'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    // --- 02 Cron ------------------------------------------------------------

    public function testCronWithNoOverdueEventsPasses(): void
    {
        $findings = $this->findings(new CronCheck());
        self::assertSame(['cron.ok'], $this->ids($findings));
    }

    public function testDisableWpCronAloneIsOnlyInformational(): void
    {
        // The central false-positive guard: an external system cron cannot be
        // seen from WordPress, so DISABLE_WP_CRON on its own proves nothing.
        $findings = $this->findings(new CronCheck(), ['cron' => ['disabled' => true]]);

        self::assertSame(Severity::INFO, $this->severityOf($findings, 'cron.disabled.unverifiable'));
        self::assertNotContains('cron.overdue.critical', $this->ids($findings));
    }

    public function testHoursOfOverdueEventsIsCritical(): void
    {
        $findings = $this->findings(new CronCheck(), [
            'cron' => ['overdue' => [['hook' => 'woocommerce_scheduled_sales', 'timestamp' => Fixtures::NOW - 30000, 'delay' => 30000]]],
        ]);

        self::assertSame(Severity::CRITICAL, $this->severityOf($findings, 'cron.overdue.critical'));
    }

    public function testSecondsOfLagAreNotReported(): void
    {
        $findings = $this->findings(new CronCheck(), [
            'cron' => ['overdue' => [['hook' => 'wc_admin_daily', 'timestamp' => Fixtures::NOW - 30, 'delay' => 30]]],
        ]);

        self::assertSame(['cron.ok'], $this->ids($findings));
    }

    public function testStaleCronLockIsReported(): void
    {
        $findings = $this->findings(new CronCheck(), ['cron' => ['doing_cron_stale' => 4000]]);
        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'cron.lock.stale'));
    }

    // --- 03 Action Scheduler failed ----------------------------------------

    public function testZeroFailedActionsPasses(): void
    {
        $findings = $this->findings(new ActionSchedulerFailedCheck());
        self::assertSame(['action_scheduler.failed.none'], $this->ids($findings));
    }

    public function testAFewFailedActionsIsLow(): void
    {
        $findings = $this->findings(new ActionSchedulerFailedCheck(), [
            'failed_actions' => ['total' => 3, 'by_hook' => ['wc_update_product_lookup_tables' => 3]],
        ]);

        self::assertSame(Severity::LOW, $this->severityOf($findings, 'action_scheduler.failed.volume'));
        self::assertNotContains('action_scheduler.failed.concentration', $this->ids($findings));
    }

    public function testLargeFailedVolumeIsHighAndNamesTheDominantHook(): void
    {
        $findings = $this->findings(new ActionSchedulerFailedCheck(), [
            'failed_actions' => [
                'total' => 83,
                'oldest' => Fixtures::NOW - 86400,
                'by_hook' => ['gla/jobs/update_products' => 31, 'wc_update_product_lookup_tables' => 47, 'other' => 5],
            ],
        ]);

        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'action_scheduler.failed.volume'));
        $concentration = $findings[1];
        self::assertSame('action_scheduler.failed.concentration', $concentration->id);
        self::assertSame('wc_update_product_lookup_tables', $concentration->evidence['hook']);
    }

    public function testHugeFailedVolumeIsCritical(): void
    {
        $findings = $this->findings(new ActionSchedulerFailedCheck(), [
            'failed_actions' => ['total' => 900, 'by_hook' => ['a' => 450, 'b' => 450]],
        ]);

        self::assertSame(Severity::CRITICAL, $this->severityOf($findings, 'action_scheduler.failed.volume'));
    }

    public function testActionSchedulerAbsenceIsReportedAsUnknownNotHealthy(): void
    {
        $findings = $this->findings(new ActionSchedulerFailedCheck(), ['action_scheduler_available' => false]);
        self::assertSame(['action_scheduler.unavailable'], $this->ids($findings));
    }

    // --- 04 Action Scheduler past due --------------------------------------

    public function testPastDueSeverityLadder(): void
    {
        self::assertSame(Severity::INFO, ActionSchedulerPastDueCheck::severityForDelay(120));
        self::assertSame(Severity::LOW, ActionSchedulerPastDueCheck::severityForDelay(600));
        self::assertSame(Severity::MEDIUM, ActionSchedulerPastDueCheck::severityForDelay(1800));
        self::assertSame(Severity::HIGH, ActionSchedulerPastDueCheck::severityForDelay(7200));
        self::assertSame(Severity::CRITICAL, ActionSchedulerPastDueCheck::severityForDelay(90000));
    }

    public function testEmptyPastDueQueuePasses(): void
    {
        $findings = $this->findings(new ActionSchedulerPastDueCheck());
        self::assertSame(['action_scheduler.past_due.none'], $this->ids($findings));
    }

    public function testOverdueQueueUsesTheOldestDelay(): void
    {
        $findings = $this->findings(new ActionSchedulerPastDueCheck(), [
            'past_due_actions' => [
                'total' => 12,
                'oldest_delay' => 27000,
                'median_delay' => 9400,
                'by_hook' => ['action_scheduler_run_queue' => 12],
            ],
        ]);

        $finding = $findings[0];
        self::assertSame(Severity::CRITICAL, $finding->severity);
        self::assertSame(12, $finding->evidence['past_due_count']);
    }

    public function testAStragglerIsDistinguishedFromABacklog(): void
    {
        // From the staging run: 32 past-due actions, median lag 3 minutes,
        // oldest 1.2 hours. That is one stuck action, not a stalled queue.
        $findings = $this->findings(new ActionSchedulerPastDueCheck(), [
            'past_due_actions' => [
                'total' => 32,
                'oldest_delay' => 4200,
                'median_delay' => 180,
                'by_hook' => ['action_scheduler_run_queue' => 32],
            ],
        ]);

        self::assertTrue($findings[0]->evidence['queue_draining']);
        self::assertStringContainsString('draining', $findings[0]->technicalDetails);
    }

    public function testARealBacklogIsNotCalledDraining(): void
    {
        $findings = $this->findings(new ActionSchedulerPastDueCheck(), [
            'past_due_actions' => [
                'total' => 4000,
                'oldest_delay' => 90000,
                'median_delay' => 70000,
                'by_hook' => ['action_scheduler_run_queue' => 4000],
            ],
        ]);

        self::assertFalse($findings[0]->evidence['queue_draining']);
        self::assertSame('', $findings[0]->technicalDetails);
    }

    // --- 05 / 06 Orders -----------------------------------------------------

    public function testNoPendingOrdersPasses(): void
    {
        $findings = $this->findings(new PendingOrdersCheck());
        self::assertSame(['orders.pending.none'], $this->ids($findings));
    }

    public function testRecentPendingOrdersAreInformationalOnly(): void
    {
        $findings = $this->findings(new PendingOrdersCheck(), [
            'orders' => ['pending' => [
                'count' => 4,
                'total_value' => 210.0,
                'by_age_bucket' => ['<1h' => 2, '1-6h' => 2],
            ]],
        ]);

        self::assertSame(Severity::INFO, $findings[0]->severity);
    }

    public function testOldPendingOrdersEscalate(): void
    {
        $findings = $this->findings(new PendingOrdersCheck(), [
            'orders' => ['pending' => [
                'count' => 9,
                'total_value' => 1400.0,
                'by_age_bucket' => ['<1h' => 1, '1-7d' => 5, '>7d' => 3],
            ]],
        ]);

        self::assertSame(Severity::MEDIUM, $findings[0]->severity);
        self::assertSame(8, $findings[0]->evidence['stale_over_24h']);
    }

    public function testALargePileOfStalePendingOrdersIsHigh(): void
    {
        $findings = $this->findings(new PendingOrdersCheck(), [
            'orders' => ['pending' => [
                'count' => 40,
                'total_value' => 9800.0,
                'by_age_bucket' => ['<1h' => 5, '1-7d' => 20, '>7d' => 15],
            ]],
        ]);

        self::assertSame(Severity::HIGH, $findings[0]->severity);
    }

    public function testPendingValueIsNeverCalledLostRevenue(): void
    {
        $finding = (new PendingOrdersCheck())->run(Fixtures::troubledStore())['findings'][0];
        $text = strtolower($finding->summary . ' ' . $finding->whyItMatters . ' ' . $finding->technicalDetails);

        self::assertStringNotContainsString('revenue lost', $text);
        self::assertStringNotContainsString('lost revenue', $text);
        self::assertStringContainsString('not revenue', $text);
    }

    public function testFailedOrdersReportAttemptedValueNotLostRevenue(): void
    {
        $findings = (new FailedOrdersCheck())->run(Fixtures::troubledStore())['findings'];
        $volume = $findings[0];

        self::assertSame('orders.failed.volume', $volume->id);
        self::assertSame(Severity::LOW, $volume->severity);
        self::assertArrayHasKey('attempted_value', $volume->evidence);
        self::assertArrayNotHasKey('revenue_lost', $volume->evidence);
        self::assertStringContainsString('NOT revenue lost', $volume->technicalDetails);
    }

    public function testFailedOrdersFlagGatewayConcentration(): void
    {
        $findings = $this->findings(new FailedOrdersCheck(), [
            'orders' => ['failed' => [
                'count' => 14,
                'total_value' => 1843.17,
                'by_payment_method' => ['Credit Card (Stripe)' => 11, 'PayPal' => 2, 'Other' => 1],
            ]],
        ]);

        $concentration = $findings[1];
        self::assertSame('orders.failed.gateway_concentration', $concentration->id);
        self::assertSame('Credit Card (Stripe)', $concentration->evidence['payment_method']);
    }

    public function testOrderFindingsCarryNoPersonalData(): void
    {
        $serialized = strtolower(json_encode([
            (new PendingOrdersCheck())->run(Fixtures::troubledStore()),
            (new FailedOrdersCheck())->run(Fixtures::troubledStore()),
        ]));

        foreach (['email', 'phone', 'first_name', 'last_name', 'address', 'postcode'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    // --- 07 Database --------------------------------------------------------

    public function testNormalTablesPass(): void
    {
        $findings = $this->findings(new DatabaseCheck());
        self::assertSame(['database.ok'], $this->ids($findings));
    }

    public function testLargeActionSchedulerLogTableIsCritical(): void
    {
        $findings = (new DatabaseCheck())->run(Fixtures::troubledStore())['findings'];
        $ids = $this->ids($findings);

        self::assertContains('database.actionscheduler_logs.bloat', $ids);
        self::assertSame(Severity::CRITICAL, $this->severityOf($findings, 'database.actionscheduler_logs.bloat'));
        self::assertSame(Severity::HIGH, $this->severityOf($findings, 'database.actionscheduler_actions.bloat'));
    }

    public function testCustomDatabasePrefixIsHandled(): void
    {
        $findings = $this->findings(new DatabaseCheck(), [
            'environment' => ['db_prefix' => 'shop7x_'],
            'tables' => [
                'shop7x_actionscheduler_actions' => ArrayGateway::table(1200, 2 * 1024 * 1024),
                'shop7x_actionscheduler_logs' => ArrayGateway::table(2000000, 800 * 1024 * 1024),
            ],
        ]);

        self::assertSame(Severity::CRITICAL, $this->severityOf($findings, 'database.actionscheduler_logs.bloat'));
        self::assertSame('shop7x_actionscheduler_logs', $findings[0]->evidence['table']);
    }
}
