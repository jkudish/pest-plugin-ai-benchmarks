<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Jkudish\PestAiBenchmarks\BenchmarkExecutor;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\ConfigurationScope;
use Jkudish\PestAiBenchmarks\ModelIdentityEvidence;

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

function configurationScope(Repository $repository): ConfigurationScope
{
    return new ConfigurationScope(
        repository: $repository,
        providerKey: 'ai.provider',
        modelKey: 'ai.model',
        optionsKey: 'ai.options',
        supportedSettings: ['receipt.prompt', 'receipt.parser'],
    );
}

it('fingerprints JSON-safe production options without requiring an override array', function (mixed $options): void {
    $repository = configurationRepository();
    $repository->set('ai.options', $options);

    $fingerprint = configurationScope($repository)->fingerprint(Configuration::production());

    expect($fingerprint['ai.options'])->toBe($options);
})->with([
    'null provider default' => null,
    'scalar provider default' => 'provider-managed',
    'numeric provider default' => 42,
]);

it('configures application keys through the global benchmark API', function (): void {
    config()->set([
        'api.provider' => 'openrouter',
        'api.model' => 'production-model',
        'api.options' => [],
        'api.prompt' => 'v1',
    ]);

    benchmarks()->configure(
        provider: 'api.provider',
        model: 'api.model',
        options: 'api.options',
        settings: ['api.prompt'],
    );

    expect(app()->bound(ConfigurationScope::class))->toBeTrue();

    app(ConfigurationScope::class)->run(
        Configuration::model('openai', 'gpt-test')->withSettings(['api.prompt' => 'v2']),
        function (ModelIdentityEvidence $identity): void {
            expect(config('api.provider'))->toBe('openai')
                ->and(config('api.model'))->toBe('gpt-test')
                ->and(config('api.prompt'))->toBe('v2')
                ->and($identity->effectiveModel)->toBe('gpt-test');
        },
    );

    expect(config('api.provider'))->toBe('openrouter')
        ->and(config('api.model'))->toBe('production-model')
        ->and(config('api.prompt'))->toBe('v1');
});

it('does not require an options key when candidates do not override options', function (): void {
    config()->set([
        'simple.provider' => 'openrouter',
        'simple.model' => 'production-model',
    ]);

    benchmarks()->configure(
        provider: 'simple.provider',
        model: 'simple.model',
    );

    app(ConfigurationScope::class)->run(
        Configuration::model('openai', 'gpt-test'),
        function (ModelIdentityEvidence $identity): void {
            expect(config('simple.provider'))->toBe('openai')
                ->and(config('simple.model'))->toBe('gpt-test')
                ->and($identity->effectiveModel)->toBe('gpt-test');
        },
    );

    expect(config('simple.provider'))->toBe('openrouter')
        ->and(config('simple.model'))->toBe('production-model');
});

it('fails closed when model options have no configured application key', function (): void {
    config()->set([
        'simple.provider' => 'openrouter',
        'simple.model' => 'production-model',
    ]);

    benchmarks()->configure(
        provider: 'simple.provider',
        model: 'simple.model',
    );

    expect(fn (): mixed => app(ConfigurationScope::class)->run(
        Configuration::model('openai', 'gpt-test', ['temperature' => 0.2]),
        fn (): null => null,
    ))->toThrow(InvalidArgumentException::class, 'Model options require an application configuration key');

    expect(config('simple.provider'))->toBe('openrouter')
        ->and(config('simple.model'))->toBe('production-model');
});

it('fails closed when an override runs without application configuration', function (): void {
    app()->forgetInstance(ConfigurationScope::class);

    expect(fn (): mixed => (new BenchmarkExecutor)->run(
        Configuration::model('openai', 'gpt-test'),
        fn (): null => null,
    ))->toThrow(LogicException::class, 'Configuration overrides require benchmarks()->configure(...)');
});

it('rejects non-string configuration setting keys', function (): void {
    expect(fn (): ConfigurationScope => new ConfigurationScope(
        repository: configurationRepository(),
        providerKey: 'ai.provider',
        modelKey: 'ai.model',
        optionsKey: 'ai.options',
        supportedSettings: [null],
    ))->toThrow(InvalidArgumentException::class, 'Benchmark configuration settings must be strings.');
});

it('rejects hierarchically overlapping configuration keys', function (): void {
    expect(fn (): ConfigurationScope => new ConfigurationScope(
        repository: configurationRepository(),
        providerKey: 'ai.provider',
        modelKey: 'ai.model',
        optionsKey: 'ai.options',
        supportedSettings: ['ai.options.temperature'],
    ))->toThrow(InvalidArgumentException::class, 'Benchmark configuration keys must not overlap hierarchically.');
});

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
    ))->toThrow(InvalidArgumentException::class, 'Unsupported benchmark configuration setting(s): unsupported.setting.');

    expect($repository->all())->toBe(configurationRepository()->all());
});

it('rejects settings that cannot be restored exactly', function (): void {
    $repository = configurationRepository();
    $scope = new ConfigurationScope(
        repository: $repository,
        providerKey: 'ai.provider',
        modelKey: 'ai.model',
        optionsKey: 'ai.options',
        supportedSettings: ['receipt.missing'],
    );

    expect(fn (): mixed => $scope->run(
        Configuration::settings(['receipt.missing' => 'value']),
        fn (): null => null,
    ))->toThrow(InvalidArgumentException::class, 'Application configuration [receipt.missing] must exist before it can be scoped.');

    expect($repository->has('receipt.missing'))->toBeFalse();
});
