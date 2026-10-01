<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

function failureEvidenceScorer(string $name, float $score): Scorer
{
    return new class($name, $score) implements Scorer
    {
        public function __construct(private readonly string $name, private readonly float $score) {}

        public function score(string $input, string $output, ?string $expected = null): ScorerResult
        {
            return new ScorerResult(score: $this->score, reasoning: "{$this->name} scored {$this->score}.", scorer: $this->name);
        }
    };
}

if (getenv('FAILURE_EVIDENCE_SCENARIO') === 'scorers') {
    benchmark('records every scorer before failing', fn (): string => '{"merchant":"Acme"}')
        ->evaluate(function (string $output): void {
            expect($output)->toPassBenchmarkScorers([
                [failureEvidenceScorer('safety', 0.0), 1.0],
                [failureEvidenceScorer('quality', 0.9), 0.8],
                [failureEvidenceScorer('format', 0.1), 0.5],
            ]);
        });
}

if (getenv('FAILURE_EVIDENCE_SCENARIO') === 'target') {
    benchmark('records why the target failed', function (): string {
        throw new RuntimeException('provider said: account 4417 is rate-limited');
    });
}

if (getenv('FAILURE_EVIDENCE_SCENARIO') === 'swallowed') {
    benchmark('fails a trial whose application swallowed an unfaithful agent', function (): string {
        // What BenchmarkAgentMiddleware does before throwing; the application
        // under test then catches the exception and carries on.
        RuntimeObservationCollector::flagUnfaithful('Benchmark agent [App\\Agent] must declare the same Laravel AI attributes.');

        return '{"merchant":"Acme"}';
    });
}

if (getenv('FAILURE_EVIDENCE_SCENARIO') === 'configuration') {
    beforeEach(function (): void {
        benchmarks()->configure(provider: 'benchmark.provider', model: 'benchmark.unset_model');
    });

    benchmark('records a configuration that cannot be applied', fn (): string => 'never runs');
}
