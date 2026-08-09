<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Evidence\PestEvalObservation;
use Pest\Evals\Events\Scored;
use Pest\Evals\Scorers\ScorerResult;

it('normalizes unsafe custom scorer evidence before recording it', function (): void {
    $observation = PestEvalObservation::fromEvent(new Scored(
        result: new ScorerResult(1.2, 'Custom scorer result.', '  '),
        threshold: 1.1,
        input: 'input',
        output: 'output',
        expected: null,
        sample: 1,
        samples: 1,
    ));

    expect($observation->scorer)->toBe('unnamed-scorer')
        ->and($observation->score)->toBe(1.0)
        ->and($observation->threshold)->toBe(1.0)
        ->and($observation->passed)->toBeTrue();
});

it('turns non-finite custom scorer evidence into a safe failure', function (): void {
    $observation = PestEvalObservation::fromEvent(new Scored(
        result: new ScorerResult(NAN, 'Invalid scorer result.', 'custom'),
        threshold: 0.5,
        input: 'input',
        output: 'output',
        expected: null,
        sample: 1,
        samples: 1,
    ));

    expect($observation->score)->toBe(0.0)
        ->and($observation->passed)->toBeFalse();
});
