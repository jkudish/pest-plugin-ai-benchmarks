<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests;

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

        benchmarks()->configure(
            provider: 'benchmark.provider',
            model: 'benchmark.model',
            options: 'benchmark.options',
            settings: ['benchmark.prompt', 'receipt.prompt'],
        );
    }
}
