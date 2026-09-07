<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\LaravelAi\AgentObservation;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Scorecards\Component;

function recordObservedLifecycleAttempts(string $phase): void
{
    $component = RuntimeObservationCollector::component() ?? Component::Target;

    RuntimeObservationCollector::record(new AgentObservation(
        requestedProvider: 'fixture',
        requestedModel: "{$phase}/primary",
        effectiveProvider: null,
        effectiveModel: null,
        usage: new NormalizedUsage,
        latencyMs: 1,
        succeeded: false,
        component: $component,
    ));
    RuntimeObservationCollector::record(new AgentObservation(
        requestedProvider: 'fixture',
        requestedModel: "{$phase}/fallback",
        effectiveProvider: null,
        effectiveModel: null,
        usage: new NormalizedUsage(inputTokens: 2, outputTokens: 1),
        latencyMs: 2,
        succeeded: true,
        component: $component,
    ));
}

benchmark('preserves observed attempts through lifecycle modes', function (): array {
    recordObservedLifecycleAttempts('target');

    return ['score' => 1.0];
})->evaluate(function (array $output): void {
    recordObservedLifecycleAttempts('judge');

    expect($output['score'])->toBe(1.0);
});
