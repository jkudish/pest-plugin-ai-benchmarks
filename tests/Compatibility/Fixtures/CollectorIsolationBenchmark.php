<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\LaravelAi\AgentObservation;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Scorecards\Component;

function recordCollectorIsolationObservation(string $model): void
{
    RuntimeObservationCollector::record(new AgentObservation(
        requestedProvider: 'fixture',
        requestedModel: $model,
        effectiveProvider: null,
        effectiveModel: null,
        usage: new NormalizedUsage,
        latencyMs: 1,
        succeeded: true,
        component: RuntimeObservationCollector::component() ?? Component::Target,
    ));
}

benchmark('fails after collecting target and judge evidence', function (): string {
    recordCollectorIsolationObservation('failed-target');

    return 'first output';
})->evaluate(function (): void {
    recordCollectorIsolationObservation('failed-judge');

    expect(true)->toBeFalse();
});

benchmark('starts with an isolated observation collector', function (): string {
    recordCollectorIsolationObservation('clean-target');

    return 'second output';
});
