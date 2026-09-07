<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Configuration;

dataset('configuration collision cases', [
    'first config case' => ['first', Configuration::model('dataset', 'dataset/first')],
    'second config case' => ['second', Configuration::model('dataset', 'dataset/second')],
]);

test('configuration collision dependency', function (): Configuration {
    expect(true)->toBeTrue();

    return Configuration::model('dependency', 'dependency/model');
});

benchmark('preserves a legitimate Configuration dataset without an axis', function (string $case, Configuration $datasetConfiguration): string {
    expect(func_get_args())->toHaveCount(2);

    return implode('|', [$case, $datasetConfiguration->model]);
})->with('configuration collision cases');

benchmark('preserves Configuration datasets with dataset then configuration chaining', function (string $case, Configuration $datasetConfiguration, Configuration $dependency): string {
    expect(func_get_args())->toHaveCount(3)
        ->and($dependency->model)->toBe('dependency/model');

    return implode('|', [$case, $datasetConfiguration->model, $dependency->model]);
})
    ->with('configuration collision cases')
    ->configurations([
        'production' => Configuration::production(),
        'candidate' => Configuration::production(),
    ])
    ->depends('configuration collision dependency');

benchmark('preserves Configuration datasets with configuration then dataset chaining', function (string $case, Configuration $datasetConfiguration, Configuration $dependency): string {
    expect(func_get_args())->toHaveCount(3)
        ->and($dependency->model)->toBe('dependency/model');

    return implode('|', [$case, $datasetConfiguration->model, $dependency->model]);
})
    ->configurations([
        'production' => Configuration::production(),
        'candidate' => Configuration::production(),
    ])
    ->with('configuration collision cases')
    ->depends('configuration collision dependency')
    ->repeat(2);
