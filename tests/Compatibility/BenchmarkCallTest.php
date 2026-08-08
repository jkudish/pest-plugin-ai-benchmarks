<?php

declare(strict_types=1);

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
    expect(func_get_args())->toHaveCount(1)
        ->and(func_get_arg(0))->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2);
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
