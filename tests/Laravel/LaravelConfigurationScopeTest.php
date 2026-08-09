<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Laravel\LaravelConfigurationScope;
use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;

function configurationRepository(): Repository
{
    return new Repository([
        'ai' => [
            'provider' => 'openrouter',
            'model' => 'production-model',
            'options' => ['temperature' => 0.7, 'max_tokens' => 1000],
        ],
        'receipt' => [
            'prompt' => 'v1',
            'parser' => 'production',
        ],
    ]);
}

function configurationScope(Repository $repository): LaravelConfigurationScope
{
    return new LaravelConfigurationScope(
        repository: $repository,
        providerKey: 'ai.provider',
        modelKey: 'ai.model',
        optionsKey: 'ai.options',
        supportedSettings: ['receipt.prompt', 'receipt.parser'],
    );
}

it('applies model options and settings only for the callback scope', function (): void {
    $repository = configurationRepository();
    $scope = configurationScope($repository);
    $configuration = Configuration::model(
        provider: 'google',
        model: 'gemini-flash',
        options: ['temperature' => 0.2],
    )->withSettings([
        'receipt.prompt' => 'v2',
    ]);

    $result = $scope->run($configuration, function (ModelIdentityEvidence $identity) use ($repository): string {
        expect($repository->get('ai.provider'))->toBe('google')
            ->and($repository->get('ai.model'))->toBe('gemini-flash')
            ->and($repository->get('ai.options'))->toBe([
                'temperature' => 0.2,
                'max_tokens' => 1000,
            ])
            ->and($repository->get('receipt.prompt'))->toBe('v2')
            ->and($identity->requestedProvider)->toBe('google')
            ->and($identity->requestedModel)->toBe('gemini-flash')
            ->and($identity->effectiveProvider)->toBe('google')
            ->and($identity->effectiveModel)->toBe('gemini-flash');

        return 'result';
    });

    expect($result)->toBe('result')
        ->and($repository->get('ai.provider'))->toBe('openrouter')
        ->and($repository->get('ai.model'))->toBe('production-model')
        ->and($repository->get('ai.options'))->toBe(['temperature' => 0.7, 'max_tokens' => 1000])
        ->and($repository->get('receipt.prompt'))->toBe('v1');
});

it('records production as effective without claiming it was requested', function (): void {
    $repository = configurationRepository();

    configurationScope($repository)->run(
        Configuration::production(),
        function (ModelIdentityEvidence $identity): void {
            expect($identity->requestedProvider)->toBeNull()
                ->and($identity->requestedModel)->toBeNull()
                ->and($identity->effectiveProvider)->toBe('openrouter')
                ->and($identity->effectiveModel)->toBe('production-model');
        },
    );
});

it('restores every changed value when the callback throws', function (): void {
    $repository = configurationRepository();
    $scope = configurationScope($repository);

    expect(fn (): mixed => $scope->run(
        Configuration::model('google', 'gemini-flash')
            ->withSettings(['receipt.parser' => 'experimental']),
        fn (): never => throw new RuntimeException('target failed'),
    ))->toThrow(RuntimeException::class, 'target failed');

    expect($repository->get('ai.provider'))->toBe('openrouter')
        ->and($repository->get('ai.model'))->toBe('production-model')
        ->and($repository->get('receipt.parser'))->toBe('production');
});

it('rejects unsupported application settings before changing production state', function (): void {
    $repository = configurationRepository();

    expect(fn (): mixed => configurationScope($repository)->run(
        Configuration::settings(['unsupported.setting' => true]),
        fn (): null => null,
    ))->toThrow(InvalidArgumentException::class, 'Unsupported Laravel benchmark configuration setting(s): unsupported.setting.');

    expect($repository->all())->toBe(configurationRepository()->all());
});

it('rejects settings that cannot be restored exactly', function (): void {
    $repository = configurationRepository();
    $scope = new LaravelConfigurationScope(
        repository: $repository,
        providerKey: 'ai.provider',
        modelKey: 'ai.model',
        optionsKey: 'ai.options',
        supportedSettings: ['receipt.missing'],
    );

    expect(fn (): mixed => $scope->run(
        Configuration::settings(['receipt.missing' => 'value']),
        fn (): null => null,
    ))->toThrow(InvalidArgumentException::class, 'Laravel configuration [receipt.missing] must exist before it can be scoped.');

    expect($repository->has('receipt.missing'))->toBeFalse();
});
