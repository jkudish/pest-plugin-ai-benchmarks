<?php

declare(strict_types=1);

use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

benchmark('retains failed scorer replay evidence', function (): string {
    $output = '{"merchant":"Private Merchant","account":"000"}';
    $scorer = new class implements Scorer
    {
        public function score(string $input, string $output, ?string $expected = null): ScorerResult
        {
            return new ScorerResult(
                score: 0.2,
                reasoning: 'Bearer private-token did not satisfy the required account.',
                scorer: 'receipt-fields',
            );
        }
    };

    expect($output)->toPassScorer(
        scorer: $scorer,
        threshold: 0.9,
        expected: '{"merchant":"Expected Merchant","account":"493"}',
    );

    return $output;
});
