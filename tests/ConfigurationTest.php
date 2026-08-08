<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Configuration;

it('represents the unchanged production configuration', function (): void {
    $configuration = Configuration::production();

    expect($configuration)
        ->provider->toBeNull()
        ->model->toBeNull()
        ->options->toBe([])
        ->settings->toBe([]);
});

it('represents a model configuration with inference options', function (): void {
    $configuration = Configuration::model(
        provider: 'openrouter',
        model: 'google/gemini-3-flash',
        options: [
            'temperature' => 0.2,
            'reasoning' => ['effort' => 'low'],
        ],
    );

    expect($configuration)
        ->provider->toBe('openrouter')
        ->model->toBe('google/gemini-3-flash')
        ->options->toBe([
            'temperature' => 0.2,
            'reasoning' => ['effort' => 'low'],
        ])
        ->settings->toBe([]);
});

it('composes application settings immutably', function (): void {
    $original = Configuration::settings([
        'receipt.prompt' => 'v1',
        'receipt.parser' => 'strict',
    ]);

    $configured = $original->withSettings([
        'receipt.prompt' => 'v2',
    ]);

    expect($original->settings)->toBe([
        'receipt.prompt' => 'v1',
        'receipt.parser' => 'strict',
    ])->and($configured->settings)->toBe([
        'receipt.prompt' => 'v2',
        'receipt.parser' => 'strict',
    ]);
});

it('combines a model and application settings on one axis', function (): void {
    $configuration = Configuration::model('openrouter', 'google/gemini-3-flash')
        ->withSettings(['receipt.prompt' => 'v2']);

    expect($configuration)
        ->provider->toBe('openrouter')
        ->model->toBe('google/gemini-3-flash')
        ->settings->toBe(['receipt.prompt' => 'v2']);
});

it('rejects invalid model identities', function (string $provider, string $model): void {
    expect(fn (): Configuration => Configuration::model($provider, $model))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty provider' => ['', 'model'],
    'empty model' => ['provider', ''],
    'provider whitespace' => [' provider', 'model'],
    'model control character' => ['provider', "model\n"],
]);

it('rejects settings and options that are not stable JSON objects', function (Closure $create): void {
    expect($create)->toThrow(InvalidArgumentException::class);
})->with([
    'numeric option key' => fn (): Configuration => Configuration::model('provider', 'model', ['value']),
    'empty setting key' => fn (): Configuration => Configuration::settings(['' => true]),
    'object setting' => fn (): Configuration => Configuration::settings(['parser' => new stdClass]),
    'infinite option' => fn (): Configuration => Configuration::model('provider', 'model', ['temperature' => INF]),
]);
