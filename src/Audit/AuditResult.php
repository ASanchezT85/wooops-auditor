<?php
declare(strict_types=1);

namespace WooOps\Auditor\Audit;

/**
 * The complete outcome of one audit run, and the single source of the JSON
 * schema. The schema is versioned because external monitoring will consume it.
 */
final class AuditResult implements \JsonSerializable
{
    public const SCHEMA_VERSION = '1.0.0';

    /**
     * @param list<Finding>       $findings
     * @param array<string,array> $checks   Raw per-check data, keyed by check key.
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $checks,
        public readonly array $environment,
        public readonly string $auditorVersion,
        public readonly int $timestamp,
        public readonly array $errors = [],
    ) {
    }

    /** Findings ordered most severe first, stable within a severity. */
    public function sortedFindings(): array
    {
        $findings = $this->findings;
        usort(
            $findings,
            static fn (Finding $a, Finding $b) => Severity::rank($a->severity) <=> Severity::rank($b->severity)
        );

        return $findings;
    }

    /** Findings that are not PASS/INFO, most severe first. */
    public function actionable(): array
    {
        return array_values(array_filter(
            $this->sortedFindings(),
            static fn (Finding $f) => Severity::penalty($f->severity) > 0
        ));
    }

    public function score(): int
    {
        return HealthScore::calculate($this->findings);
    }

    /** @return array<string,int> counts keyed by severity, in severity order */
    public function summary(): array
    {
        $counts = array_fill_keys(Severity::ORDER, 0);
        foreach ($this->findings as $finding) {
            $counts[$finding->severity]++;
        }

        return $counts;
    }

    public function jsonSerialize(): array
    {
        return [
            'metadata' => [
                'schema_version' => self::SCHEMA_VERSION,
                'auditor_version' => $this->auditorVersion,
                'timestamp' => $this->timestamp,
                'generated_at' => gmdate('c', $this->timestamp),
                'read_only' => true,
            ],
            'environment' => $this->environment,
            'score' => $this->score(),
            'summary' => $this->summary(),
            'findings' => array_map(
                static fn (Finding $f) => $f->jsonSerialize(),
                $this->sortedFindings()
            ),
            'checks' => $this->checks,
            'errors' => $this->errors,
        ];
    }
}
