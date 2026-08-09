<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Evidence\RecordingScorer;
use Jkudish\PestAiBenchmarks\Evidence\ScorerEvidence;
use Pest\Evals\Scorers\Scorer;
use Pest\Evals\Scorers\ScorerResult;

function fixedScorer(float $score = 0.85): Scorer
{
    return new class($score) implements Scorer
    {
        public function __construct(private readonly float $score) {}

        public function score(string $input, string $output, ?string $expected = null): ScorerResult
        {
            return new ScorerResult(
                score: $this->score,
                reasoning: 'Fixture scorer reasoning.',
                scorer: self::class,
            );
        }
    };
}

it('records scorer evidence through the public Pest scorer contract', function (): void {
    $evidence = null;
    $scorer = new RecordingScorer(
        scorer: fixedScorer(),
        sampleId: 'receipt-uber-001',
        sampleOrder: 2,
        threshold: 0.8,
        record: function (ScorerEvidence $recorded) use (&$evidence): void {
            $evidence = $recorded;
        },
    );

    $result = $scorer->score(
        input: 'Extract this receipt.',
        output: '{"total":"43.64"}',
        expected: '{"total":"43.64"}',
    );

    expect($evidence)->toBeInstanceOf(ScorerEvidence::class)
        ->and($evidence->sampleId)->toBe('receipt-uber-001')
        ->and($evidence->sampleOrder)->toBe(2)
        ->and($evidence->input)->toBe('Extract this receipt.')
        ->and($evidence->output)->toBe('{"total":"43.64"}')
        ->and($evidence->expected)->toBe('{"total":"43.64"}')
        ->and($evidence->threshold)->toBe(0.8)
        ->and($evidence->result)->toBe($result)
        ->and($evidence->result->reasoning)->toBe('Fixture scorer reasoning.')
        ->and($evidence->passed)->toBeTrue();
});

it('derives pass or fail from the scorer result and configured threshold', function (float $score, float $threshold, bool $passed): void {
    $evidence = null;
    $scorer = new RecordingScorer(
        scorer: fixedScorer($score),
        sampleId: 'sample-1',
        sampleOrder: 1,
        threshold: $threshold,
        record: function (ScorerEvidence $recorded) use (&$evidence): void {
            $evidence = $recorded;
        },
    );

    $scorer->score('', 'output');

    expect($evidence?->passed)->toBe($passed);
})->with([
    'above threshold' => [0.81, 0.8, true],
    'at threshold' => [0.8, 0.8, true],
    'below threshold' => [0.79, 0.8, false],
]);

it('records samples in callback invocation order', function (): void {
    $recorded = [];

    foreach (['case-a', 'case-b', 'case-c'] as $index => $sampleId) {
        $scorer = new RecordingScorer(
            scorer: fixedScorer(),
            sampleId: $sampleId,
            sampleOrder: $index + 1,
            threshold: 0.7,
            record: function (ScorerEvidence $evidence) use (&$recorded): void {
                $recorded[] = $evidence;
            },
        );

        $scorer->score('input', "output-{$sampleId}");
    }

    expect(array_map(fn (ScorerEvidence $evidence): string => $evidence->sampleId, $recorded))
        ->toBe(['case-a', 'case-b', 'case-c'])
        ->and(array_map(fn (ScorerEvidence $evidence): int => $evidence->sampleOrder, $recorded))
        ->toBe([1, 2, 3]);
});

it('works with the public Pest Evals expectation without parsing terminal output', function (): void {
    $recorded = [];
    $scorer = new RecordingScorer(
        scorer: fixedScorer(1.0),
        sampleId: 'pest-expectation',
        sampleOrder: 1,
        threshold: 0.9,
        record: function (ScorerEvidence $evidence) use (&$recorded): void {
            $recorded[] = $evidence;
        },
    );

    expect('actual output')->toPassScorer(
        scorer: $scorer,
        threshold: 0.9,
        expected: 'expected output',
    );

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]->output)->toBe('actual output')
        ->and($recorded[0]->expected)->toBe('expected output')
        ->and($recorded[0]->result->score)->toBe(1.0);
});

it('preserves scorer exceptions and does not invoke the callback', function (): void {
    $callbackInvoked = false;
    $exception = new RuntimeException('Provider request failed.');
    $failingScorer = new class($exception) implements Scorer
    {
        public function __construct(private readonly RuntimeException $exception) {}

        public function score(string $input, string $output, ?string $expected = null): ScorerResult
        {
            throw $this->exception;
        }
    };

    $scorer = new RecordingScorer(
        scorer: $failingScorer,
        sampleId: 'failing-sample',
        sampleOrder: 1,
        threshold: 0.7,
        record: function () use (&$callbackInvoked): void {
            $callbackInvoked = true;
        },
    );

    expect(fn (): ScorerResult => $scorer->score('input', 'output'))
        ->toThrow($exception)
        ->and($callbackInvoked)->toBeFalse();
});

it('rejects evidence metadata that cannot identify a valid sample', function (string $sampleId, int $sampleOrder, float $threshold, string $message): void {
    expect(fn (): RecordingScorer => new RecordingScorer(
        scorer: fixedScorer(),
        sampleId: $sampleId,
        sampleOrder: $sampleOrder,
        threshold: $threshold,
        record: function (): void {},
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty sample ID' => ['', 1, 0.7, 'sample ID'],
    'invalid sample order' => ['sample-1', 0, 0.7, 'sample order'],
    'threshold below zero' => ['sample-1', 1, -0.1, 'threshold'],
    'threshold above one' => ['sample-1', 1, 1.1, 'threshold'],
]);
