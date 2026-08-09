<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

final readonly class RegressionEvaluation
{
    /**
     * @param  array<string, float|string>  $observedChanges
     * @param  list<string>  $failures
     */
    public function __construct(
        public RegressionStatus $status,
        public array $observedChanges = [],
        public array $failures = [],
    ) {}

    public function passed(): bool
    {
        return in_array($this->status, [RegressionStatus::EvidenceOnly, RegressionStatus::Passed], true);
    }
}
