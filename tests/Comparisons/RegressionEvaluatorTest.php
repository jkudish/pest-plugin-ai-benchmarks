<?php

declare(strict_types=1);

use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\PestAiBenchmarks\Comparisons\AggregateEvidence;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionEvaluator;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionPolicy;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionStatus;

it('keeps historical comparison evidence-only without explicit gates', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('same-cases', 0.4, 300, new Money('3')),
        historical: new AggregateEvidence('different-cases', 1.0, 100, new Money('1')),
        policy: RegressionPolicy::evidenceOnly(),
    );

    expect($evaluation->status)->toBe(RegressionStatus::EvidenceOnly)
        ->and($evaluation->passed())->toBeTrue();
});

it('passes changes at or below explicit thresholds', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('same-cases', 0.87, 120, new Money('11.50')),
        historical: new AggregateEvidence('same-cases', 0.90, 100, new Money('10.00')),
        policy: RegressionPolicy::from([
            'pass_rate_drop' => 0.03,
            'median_latency_increase' => 0.20,
            'average_cost_increase' => 0.15,
        ]),
    );

    expect($evaluation->status)->toBe(RegressionStatus::Passed)
        ->and($evaluation->passed())->toBeTrue();

    expect($evaluation->observedChanges[RegressionPolicy::PASS_RATE_DROP])->toEqualWithDelta(0.03, 0.000_000_001)
        ->and($evaluation->observedChanges[RegressionPolicy::MEDIAN_LATENCY_INCREASE])->toEqualWithDelta(0.20, 0.000_000_001)
        ->and($evaluation->observedChanges[RegressionPolicy::AVERAGE_COST_INCREASE])->toBe('0.15');
});

it('fails when an enabled regression gate is exceeded', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('same-cases', 0.80, 121, new Money('10')),
        historical: new AggregateEvidence('same-cases', 0.90, 100, new Money('10')),
        policy: RegressionPolicy::from([
            'pass_rate_drop' => 0.05,
            'median_latency_increase' => 0.20,
        ]),
    );

    expect($evaluation->status)->toBe(RegressionStatus::Failed)
        ->and($evaluation->passed())->toBeFalse()
        ->and($evaluation->failures)->toBe([
            'Regression gate [pass_rate_drop] exceeded its threshold.',
            'Regression gate [median_latency_increase] exceeded its threshold.',
        ]);
});

it('fails as not evaluable when enabled evidence is missing', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('same-cases', passRate: 0.9),
        historical: new AggregateEvidence('same-cases', passRate: 0.9),
        policy: RegressionPolicy::from(['average_cost_increase' => 0.15]),
    );

    expect($evaluation->status)->toBe(RegressionStatus::NotEvaluable)
        ->and($evaluation->passed())->toBeFalse()
        ->and($evaluation->failures)->toBe([
            'Regression gate [average_cost_increase] is missing required evidence.',
        ]);
});

it('fails as not evaluable when aggregate evidence is incompatible', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('current-cases', 0.9),
        historical: new AggregateEvidence('historical-cases', 0.9),
        policy: RegressionPolicy::from(['pass_rate_drop' => 0.05]),
    );

    expect($evaluation->status)->toBe(RegressionStatus::NotEvaluable)
        ->and($evaluation->passed())->toBeFalse()
        ->and($evaluation->failures)->toBe(['Current and historical aggregate evidence are incompatible.']);
});

it('does not silently define relative change from a zero historical value', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('same-cases', averageCost: new Money('1')),
        historical: new AggregateEvidence('same-cases', averageCost: new Money('0')),
        policy: RegressionPolicy::from(['average_cost_increase' => 0.15]),
    );

    expect($evaluation->status)->toBe(RegressionStatus::NotEvaluable)
        ->and($evaluation->passed())->toBeFalse();
});

it('rejects mixed currencies as not evaluable', function (): void {
    $evaluation = (new RegressionEvaluator)->evaluate(
        current: new AggregateEvidence('same-cases', averageCost: new Money('12', 'CAD')),
        historical: new AggregateEvidence('same-cases', averageCost: new Money('10', 'USD')),
        policy: RegressionPolicy::from(['average_cost_increase' => 0.15]),
    );

    expect($evaluation->status)->toBe(RegressionStatus::NotEvaluable)
        ->and($evaluation->passed())->toBeFalse()
        ->and($evaluation->failures)->toBe([
            'Regression gate [average_cost_increase] cannot compare mixed currencies [CAD] and [USD].',
        ]);
});
