<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Jkudish\PestAiBenchmarks\Laravel\LaravelConfigurationScope;
use Jkudish\PestAiBenchmarks\PestAiBenchmarksServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PestAiBenchmarksServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'benchmark.provider' => 'openrouter',
            'benchmark.model' => 'production/model',
            'benchmark.options' => [],
            'benchmark.prompt' => 'v1',
            'receipt.prompt' => 'v1',
        ]);

        $app->singleton(LaravelConfigurationScope::class, fn (Application $app): LaravelConfigurationScope => new LaravelConfigurationScope(
            repository: $app->make(Repository::class),
            providerKey: 'benchmark.provider',
            modelKey: 'benchmark.model',
            optionsKey: 'benchmark.options',
            supportedSettings: ['benchmark.prompt', 'receipt.prompt'],
        ));
    }
}
