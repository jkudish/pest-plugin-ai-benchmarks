<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Comparisons\RegressionPolicy;

it('accepts only the three locked non-negative finite thresholds', function (): void {
    $policy = RegressionPolicy::from([
        'pass_rate_drop' => 0.03,
        'median_latency_increase' => 1,
        'average_cost_increase' => 0.15,
    ]);

    expect($policy->thresholds)->toBe([
        RegressionPolicy::PASS_RATE_DROP => 0.03,
        RegressionPolicy::MEDIAN_LATENCY_INCREASE => 1.0,
        RegressionPolicy::AVERAGE_COST_INCREASE => 0.15,
    ])->and($policy->hasGates())->toBeTrue();
});

it('rejects invalid regression thresholds', function (array $thresholds, string $message): void {
    expect(fn (): RegressionPolicy => RegressionPolicy::from($thresholds))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown gate' => [['accuracy_drop' => 0.1], 'Unsupported benchmark regression gate [accuracy_drop].'],
    'negative threshold' => [['pass_rate_drop' => -0.1], 'Benchmark regression gate [pass_rate_drop] must be finite and non-negative.'],
    'infinite threshold' => [['median_latency_increase' => INF], 'Benchmark regression gate [median_latency_increase] must be finite and non-negative.'],
    'string threshold' => [['average_cost_increase' => '0.1'], 'Benchmark regression gate [average_cost_increase] must be numeric.'],
]);

it('represents the default evidence-only policy', function (): void {
    expect(RegressionPolicy::evidenceOnly())
        ->hasGates()->toBeFalse()
        ->thresholds->toBe([]);
});
