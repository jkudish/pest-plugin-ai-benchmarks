<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Configuration;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

$cases = getenv('BENCHMARK_INCLUDE_SECOND') === '1'
    ? ['first-case', 'second-case']
    : ['first-case'];

benchmark('replays and resumes declared evaluation callbacks', function (string $case): string {
    $targetCounter = getenv('BENCHMARK_TARGET_COUNTER');

    if (is_string($targetCounter) && $targetCounter !== '') {
        file_put_contents($targetCounter, "target\n", FILE_APPEND | LOCK_EX);
    }

    return "output:{$case}";
})
    ->configurations(['production' => Configuration::production()])
    ->with($cases)
    ->evaluate(function (string $output, string $case): void {
        $evaluationCounter = getenv('BENCHMARK_EVALUATION_COUNTER');

        if (is_string($evaluationCounter) && $evaluationCounter !== '') {
            file_put_contents($evaluationCounter, "evaluation\n", FILE_APPEND | LOCK_EX);
        }

        $scorer = new class implements Scorer
        {
            public function score(string $input, string $output, ?string $expected = null): ScorerResult
            {
                return new ScorerResult(
                    score: $output === $expected ? 1.0 : 0.0,
                    reasoning: 'Output must match the case expectation.',
                    scorer: 'lifecycle-exact-match',
                );
            }
        };

        expect($output)->toPassScorer(
            scorer: $scorer,
            threshold: 1.0,
            expected: "output:{$case}",
        );
    });
