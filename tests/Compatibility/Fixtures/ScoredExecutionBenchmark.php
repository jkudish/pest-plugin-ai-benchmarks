<?php

declare(strict_types=1);

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

benchmark('records native Pest scorer evidence', function (): string {
    $output = '{"merchant":"Uber","account":"493"}';
    $scorer = new class implements Scorer
    {
        public function score(string $input, string $output, ?string $expected = null): ScorerResult
        {
            return new ScorerResult(
                score: 0.96,
                reasoning: 'The expected merchant and account matched.',
                scorer: 'receipt-fields',
            );
        }
    };

    expect($output)->toPassScorer(
        scorer: $scorer,
        threshold: 0.9,
        expected: '{"merchant":"Uber","account":"493"}',
    );

    return $output;
});
