<?php
declare(strict_types=1);

namespace WooOps\Auditor\Audit;

use Throwable;
use WooOps\Auditor\Checks\ActionSchedulerFailedCheck;
use WooOps\Auditor\Checks\ActionSchedulerPastDueCheck;
use WooOps\Auditor\Checks\CheckInterface;
use WooOps\Auditor\Checks\CronCheck;
use WooOps\Auditor\Checks\DatabaseCheck;
use WooOps\Auditor\Checks\EnvironmentCheck;
use WooOps\Auditor\Checks\FailedOrdersCheck;
use WooOps\Auditor\Checks\PendingOrdersCheck;
use WooOps\Auditor\Store\StoreGateway;

final class AuditRunner
{
    public const VERSION = '0.1.1';

    /** @var list<CheckInterface> */
    private array $checks;

    /** @param list<CheckInterface>|null $checks */
    public function __construct(?array $checks = null)
    {
        $this->checks = $checks ?? self::defaultChecks();
    }

    /** The seven v0.1 checks, in report order. */
    public static function defaultChecks(): array
    {
        return [
            new EnvironmentCheck(),
            new CronCheck(),
            new ActionSchedulerFailedCheck(),
            new ActionSchedulerPastDueCheck(),
            new PendingOrdersCheck(),
            new FailedOrdersCheck(),
            new DatabaseCheck(),
        ];
    }

    public function run(StoreGateway $store): AuditResult
    {
        $findings = [];
        $data = [];
        $errors = [];

        foreach ($this->checks as $check) {
            try {
                $result = $check->run($store);
                foreach ($result['findings'] as $finding) {
                    $findings[] = $finding;
                }
                $data[$check->key()] = $result['data'];
            } catch (Throwable $e) {
                // One broken check must not lose the rest of the audit. The
                // failure is reported as data, not swallowed.
                $errors[] = [
                    'check' => $check->key(),
                    'message' => $e->getMessage(),
                ];
                $data[$check->key()] = ['error' => $e->getMessage()];
                $findings[] = new Finding(
                    'auditor.check.error',
                    'auditor',
                    Severity::INFO,
                    sprintf('Check "%s" could not complete', $check->title()),
                    'The check raised an error and its results are missing from this report.',
                    'An incomplete audit is not a clean audit. Treat this domain as unknown, not as healthy.',
                    'Report the message below to whoever maintains the auditor.',
                    ['check' => $check->key(), 'message' => $e->getMessage()]
                );
            }
        }

        return new AuditResult(
            findings: $findings,
            checks: $data,
            environment: $data['environment'] ?? [],
            auditorVersion: self::VERSION,
            timestamp: $store->now(),
            errors: $errors,
        );
    }
}
