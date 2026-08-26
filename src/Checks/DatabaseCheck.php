<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Audit\Finding;
use WooOps\Auditor\Audit\Severity;
use WooOps\Auditor\Store\StoreGateway;
use WooOps\Auditor\Support\Format;

/**
 * Check 07 — size of the Action Scheduler and order tables.
 *
 * This is not a general database profiler. It answers one question: is
 * WooCommerce background-job bookkeeping accumulating without bound? The
 * Action Scheduler log table in particular grows forever on stores where
 * retention was never configured.
 */
final class DatabaseCheck implements CheckInterface
{
    /** Row-count ladder applied to Action Scheduler tables. */
    private const ROWS_CRITICAL = 1000000;
    private const ROWS_HIGH = 250000;
    private const ROWS_MEDIUM = 50000;

    /** Size ladder, bytes. Applied to every inspected table. */
    private const SIZE_CRITICAL = 1073741824; // 1 GB
    private const SIZE_HIGH = 268435456;      // 256 MB

    private const SCHEDULER_TABLES = [
        'actionscheduler_actions',
        'actionscheduler_logs',
        'actionscheduler_claims',
        'actionscheduler_groups',
    ];

    public function key(): string
    {
        return 'database';
    }

    public function title(): string
    {
        return 'Database / Action Scheduler Tables';
    }

    public function run(StoreGateway $store): array
    {
        $tables = $store->tables();
        $findings = [];

        foreach (self::SCHEDULER_TABLES as $suffix) {
            $table = $this->find($tables, $suffix);
            if ($table === null || !$table['stats']['exists']) {
                continue;
            }

            $stats = $table['stats'];
            $severity = $this->severityFor($stats['rows'], $stats['total_size']);
            if ($severity === Severity::PASS) {
                continue;
            }

            $findings[] = new Finding(
                'database.' . $suffix . '.bloat',
                'database',
                $severity,
                sprintf('Unusually large %s table', $suffix),
                sprintf(
                    'The table %s holds %s rows and occupies %s.',
                    $table['name'],
                    number_format($stats['rows']),
                    Format::bytes($stats['total_size'])
                ),
                'Action Scheduler keeps completed actions and their log entries until something prunes them. Left alone the tables grow without bound, which slows every queue claim and makes backups and migrations painful.',
                'Confirm the Action Scheduler retention period for this store and check whether the cleanup job is running. Do not delete rows manually without understanding what is still queued.',
                [
                    'table' => $table['name'],
                    'rows' => $stats['rows'],
                    'data_size' => $stats['data_size'],
                    'index_size' => $stats['index_size'],
                    'total_size' => $stats['total_size'],
                ],
                'Row counts come from the storage engine statistics and are approximate on InnoDB.'
            );
        }

        foreach ($tables as $name => $stats) {
            if (!$stats['exists'] || $this->isScheduler($name)) {
                continue;
            }
            if ($stats['total_size'] < self::SIZE_CRITICAL) {
                continue;
            }

            $findings[] = new Finding(
                'database.table.large',
                'database',
                Severity::MEDIUM,
                sprintf('Large table: %s', $name),
                sprintf('%s occupies %s across %s rows.', $name, Format::bytes($stats['total_size']), number_format($stats['rows'])),
                'A very large order or meta table is not a fault on its own, but it explains slow admin screens and long backups, and it is the first thing to look at before blaming WooCommerce.',
                'Review what is stored there before acting. No cleanup is recommended by this audit.',
                ['table' => $name, 'rows' => $stats['rows'], 'total_size' => $stats['total_size']]
            );
        }

        if ($findings === []) {
            $findings[] = new Finding(
                'database.ok',
                'database',
                Severity::PASS,
                'No table accumulation detected',
                'The Action Scheduler and order tables are within expected sizes.',
                '',
                '',
                ['inspected_tables' => array_keys($tables)]
            );
        }

        return ['findings' => $findings, 'data' => ['tables' => $tables]];
    }

    private function severityFor(int $rows, int $bytes): string
    {
        $byRows = match (true) {
            $rows >= self::ROWS_CRITICAL => Severity::CRITICAL,
            $rows >= self::ROWS_HIGH => Severity::HIGH,
            $rows >= self::ROWS_MEDIUM => Severity::MEDIUM,
            default => Severity::PASS,
        };

        $bySize = match (true) {
            $bytes >= self::SIZE_CRITICAL => Severity::CRITICAL,
            $bytes >= self::SIZE_HIGH => Severity::HIGH,
            default => Severity::PASS,
        };

        return Severity::max($byRows, $bySize);
    }

    /**
     * Tables are keyed by their real, prefixed name. Match on the suffix so a
     * custom $wpdb prefix works.
     *
     * @param array<string, array{exists:bool,rows:int,data_size:int,index_size:int,total_size:int}> $tables
     * @return array{name:string, stats:array}|null
     */
    private function find(array $tables, string $suffix): ?array
    {
        foreach ($tables as $name => $stats) {
            if (str_ends_with($name, $suffix)) {
                return ['name' => $name, 'stats' => $stats];
            }
        }

        return null;
    }

    private function isScheduler(string $name): bool
    {
        foreach (self::SCHEDULER_TABLES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
