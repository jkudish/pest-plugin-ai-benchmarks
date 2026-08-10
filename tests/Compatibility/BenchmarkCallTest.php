<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Comparisons\DeclarationRegistry;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionPolicy;
use Jkudish\PestAiBenchmarks\Configuration;
use PHPUnit\Framework\TestCase;

dataset('benchmark compatibility cases', [
    'first case' => ['first'],
    'second case' => ['second'],
]);

benchmark('uses native Pest Cartesian datasets and hides configurations', function (string $case): void {
    expect($case)->toBeIn(['first', 'second'])
        ->and(func_get_args())->toHaveCount(1);
})
    ->with('benchmark compatibility cases')
    ->configurations([
        'production' => Configuration::production(),
        'alternate model' => Configuration::model('openrouter', 'google/gemini-3-flash'),
    ]);

benchmark('retains native Pest repetition semantics', function (): void {
    expect(func_get_args())->toHaveCount(0);
})
    ->configurations([
        'production' => Configuration::production(),
        'new prompt' => Configuration::settings(['receipt.prompt' => 'v2']),
    ])
    ->repeat(2);

benchmark('preserves the bound Pest test case', function (): void {
    expect($this)->toBeInstanceOf(TestCase::class);
})->configurations([
    'production' => Configuration::production(),
]);

test('benchmark dependency seed', function (): string {
    expect(true)->toBeTrue();

    return 'dependency value';
});

benchmark('delegates dependencies to Pest', function (string $dependency): void {
    expect($dependency)->toBe('dependency value');
})
    ->configurations([
        'production' => Configuration::production(),
    ])
    ->depends('benchmark dependency seed');

benchmark('delegates skip to Pest', function (): void {
    throw new RuntimeException('This skipped benchmark must not execute.');
})->skip('compatibility skip');

$lockedApiBenchmark = benchmark('stores locked declaration metadata', function (): void {})
    ->skip('declaration metadata fixture')
    ->configurations([
        'production' => Configuration::production(),
        'candidate' => Configuration::model('openrouter', 'candidate/model'),
    ])
    ->context(['work_reference' => 'TASK-123', 'trace_id' => 'trace-456'])
    ->reference('production')
    ->evaluate(function (mixed $output): void {
        expect($output)->toBeNull();
    })
    ->dependsOn([Configuration::class, 'README.md'])
    ->failWhen([
        'pass_rate_drop' => 0.03,
        'median_latency_increase' => 0.20,
        'average_cost_increase' => 0.15,
    ]);

it('stores immutable metadata for the locked public API', function () use ($lockedApiBenchmark): void {
    $declaration = DeclarationRegistry::get($lockedApiBenchmark);

    expect($declaration->configurations)->toBe(['production', 'candidate'])
        ->and($declaration->reference)->toBe('production')
        ->and($declaration->evaluation)->toBeInstanceOf(Closure::class)
        ->and($declaration->dependencies)->toBe([Configuration::class, 'README.md'])
        ->and($declaration->context?->toArray())->toBe([
            'work_reference' => 'TASK-123',
            'trace_id' => 'trace-456',
        ])
        ->and($declaration->regressionPolicy?->thresholds)->toBe([
            RegressionPolicy::PASS_RATE_DROP => 0.03,
            RegressionPolicy::MEDIAN_LATENCY_INCREASE => 0.20,
            RegressionPolicy::AVERAGE_COST_INCREASE => 0.15,
        ]);
});

$referenceValidationBenchmark = benchmark('validates reference metadata', function (): void {})
    ->skip('reference validation fixture')
    ->configurations(['production' => Configuration::production()]);

it('requires a declared configuration as the reference', function () use ($referenceValidationBenchmark): void {
    expect(fn () => $referenceValidationBenchmark->reference('missing'))
        ->toThrow(InvalidArgumentException::class, 'Benchmark reference [missing] is not a declared configuration.');
});

$unconfiguredReferenceBenchmark = benchmark('validates reference ordering', function (): void {})
    ->skip('reference ordering fixture');

it('requires configurations before a reference', function () use ($unconfiguredReferenceBenchmark): void {
    expect(fn () => $unconfiguredReferenceBenchmark->reference('production'))
        ->toThrow(InvalidArgumentException::class, 'A benchmark reference must be declared after configurations.');
});

it('rejects duplicate locked metadata declarations', function () use ($lockedApiBenchmark): void {
    expect(fn () => $lockedApiBenchmark->context(['second' => true]))
        ->toThrow(InvalidArgumentException::class, 'Benchmark context may only be declared once.')
        ->and(fn () => $lockedApiBenchmark->reference('candidate'))
        ->toThrow(InvalidArgumentException::class, 'A benchmark reference may only be declared once.')
        ->and(fn () => $lockedApiBenchmark->failWhen(['pass_rate_drop' => 0.1]))
        ->toThrow(InvalidArgumentException::class, 'Benchmark regression gates may only be declared once.')
        ->and(fn () => $lockedApiBenchmark->evaluate(fn (): null => null))
        ->toThrow(InvalidArgumentException::class, 'A benchmark evaluation callback may only be declared once.')
        ->and(fn () => $lockedApiBenchmark->dependsOn(['README.md']))
        ->toThrow(InvalidArgumentException::class, 'Benchmark source dependencies may only be declared once.')
        ->and(fn () => $lockedApiBenchmark->dependsOn([]))
        ->toThrow(InvalidArgumentException::class, 'must be a non-empty list');
});

$lifecycleBenchmark = benchmark('retains declaration metadata for native Pest execution', function (): void {
    $declaration = DeclarationRegistry::current();

    expect($declaration->configurations)->toBe(['production'])
        ->and($declaration->reference)->toBe('production')
        ->and($declaration->context?->toArray())->toBe(['trace_id' => 'lifecycle-test']);
})
    ->configurations(['production' => Configuration::production()])
    ->reference('production')
    ->context(['trace_id' => 'lifecycle-test']);

unset($lifecycleBenchmark);
gc_collect_cycles();
