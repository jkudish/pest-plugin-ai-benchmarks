<?php

declare(strict_types=1);

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

benchmark('preserves a successful null target output', function (): null {
    return null;
})->evaluate(function (mixed $output): void {
    expect($output)->toBeNull();

    $scorer = new class implements Scorer
    {
        public function score(string $input, string $output, ?string $expected = null): ScorerResult
        {
            return new ScorerResult(
                score: $output === $expected ? 1.0 : 0.0,
                reasoning: 'Derived status should match.',
                scorer: 'nullable-derived-status',
            );
        }
    };

    expect('derived status')->toPassBenchmarkScorer(
        scorer: $scorer,
        threshold: 1.0,
        expected: 'derived status',
    );
});
