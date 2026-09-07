<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Evidence\PestEvalObservation;
use Jkudish\PestAiBenchmarks\Evidence\ScorerEvidence;
use Pest\Evals\Scorers\ScorerResult;

it('normalizes unsafe custom scorer evidence before recording it', function (): void {
    $observation = PestEvalObservation::fromEvidence(new ScorerEvidence(
        sampleId: 'sample-1',
        sampleOrder: 1,
        input: 'input',
        output: 'output',
        expected: null,
        threshold: 1.0,
        result: new ScorerResult(1.2, 'Custom scorer result.', '  '),
    ));

    expect($observation->scorer)->toBe('unnamed-scorer')
        ->and($observation->score)->toBe(1.0)
        ->and($observation->threshold)->toBe(1.0)
        ->and($observation->passed)->toBeTrue();
});

it('turns non-finite custom scorer evidence into a safe failure', function (): void {
    $observation = PestEvalObservation::fromEvidence(new ScorerEvidence(
        sampleId: 'sample-1',
        sampleOrder: 1,
        input: 'input',
        output: 'output',
        expected: null,
        threshold: 0.5,
        result: new ScorerResult(NAN, 'Invalid scorer result.', 'custom'),
    ));

    expect($observation->score)->toBe(0.0)
        ->and($observation->passed)->toBeFalse();
});
