<?php
declare(strict_types=1);

namespace WooOps\Auditor\Audit;

use InvalidArgumentException;

/**
 * A single audit observation. Immutable, JSON-serialisable.
 */
final class Finding implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $whyItMatters = '',
        public readonly string $recommendedAction = '',
        public readonly array $evidence = [],
        public readonly string $technicalDetails = '',
    ) {
        if (!Severity::isValid($severity)) {
            throw new InvalidArgumentException("Unknown severity: {$severity}");
        }
        if ($id === '') {
            throw new InvalidArgumentException('Finding id cannot be empty');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'category'          => $this->category,
            'severity'          => $this->severity,
            'title'             => $this->title,
            'summary'           => $this->summary,
            'technical_details' => $this->technicalDetails,
            'why_it_matters'    => $this->whyItMatters,
            'recommended_action'=> $this->recommendedAction,
            'evidence'          => $this->evidence,
        ];
    }
}
