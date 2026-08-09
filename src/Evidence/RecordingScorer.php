<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Evidence;

use Closure;
use InvalidArgumentException;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

final readonly class RecordingScorer implements Scorer
{
    /**
     * @param  Closure(ScorerEvidence): void  $record
     */
    public function __construct(
        private Scorer $scorer,
        private string $sampleId,
        private int $sampleOrder,
        private float $threshold,
        private Closure $record,
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
    }

    public function score(string $input, string $output, ?string $expected = null): ScorerResult
    {
        $result = $this->scorer->score($input, $output, $expected);

        ($this->record)(new ScorerEvidence(
            sampleId: $this->sampleId,
            sampleOrder: $this->sampleOrder,
            input: $input,
            output: $output,
            expected: $expected,
            threshold: $this->threshold,
            result: $result,
        ));

        return $result;
    }
}
