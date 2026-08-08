<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Evidence;

use InvalidArgumentException;
use Pest\Evals\Scorers\ScorerResult;

final readonly class ScorerEvidence
{
    public bool $passed;

    public function __construct(
        public string $sampleId,
        public int $sampleOrder,
        public string $input,
        public string $output,
        public ?string $expected,
        public float $threshold,
        public ScorerResult $result,
    ) {
        if (trim($this->sampleId) === '') {
            throw new InvalidArgumentException('The sample ID must not be empty.');
        }

        if ($this->sampleOrder < 1) {
            throw new InvalidArgumentException('The sample order must be at least 1.');
        }

        if ($this->threshold < 0.0 || $this->threshold > 1.0) {
            throw new InvalidArgumentException('The threshold must be between 0.0 and 1.0.');
        }

        $this->passed = $this->result->passed($this->threshold);
    }
}
