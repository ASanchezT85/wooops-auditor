<?php
declare(strict_types=1);

namespace WooOps\Auditor\Audit;

/**
 * Health score, 0-100.
 *
 *   score = 100 - sum over categories of (penalty of the worst finding in that category)
 *
 * Penalties: CRITICAL 25, HIGH 12, MEDIUM 5, LOW 2, INFO/PASS 0.
 *
 * Worst-per-category rather than a sum of every finding, because findings are
 * not independent: one broken extension can emit six of them, and a purely
 * additive score bottoms out at zero for any store with more than one real
 * problem, which makes it useless for telling "bad" from "catastrophic".
 *
 * This is a headline number and nothing more. Decisions come from the findings
 * list, which the report always shows in full regardless of the score.
 */
final class HealthScore
{
    /** @param list<Finding> $findings */
    public static function calculate(array $findings): int
    {
        $worst = [];

        foreach ($findings as $finding) {
            $penalty = Severity::penalty($finding->severity);
            $worst[$finding->category] = max($worst[$finding->category] ?? 0, $penalty);
        }

        return max(0, 100 - array_sum($worst));
    }
}
