<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Jkudish\PestAiBenchmarks\Runs\BaselineStore;
use Jkudish\PestAiBenchmarks\Runs\RunId;
use Jkudish\PestAiBenchmarks\Runs\RunPaths;
use LogicException;
use Pest\TestSuite;

final class Benchmarks
{
    /** Promote a completed, non-simulated run to a named stable baseline. */
    public function promote(string $run, string $baseline): void
    {
        (new BaselineStore(RunPaths::forProject(TestSuite::getInstance()->rootPath)))
            ->promote(new RunId($run), $baseline);
    }

    /**
     * @param  list<string>  $settings
     */
    public function configure(
        string $provider,
        string $model,
        ?string $options = null,
        array $settings = [],
    ): void {
        $container = Container::getInstance();

        if (! $container->bound(Repository::class)) {
            throw new LogicException('Benchmark configuration requires an application config repository. Call benchmarks()->configure(...) after the test application boots.');
        }

        $container->instance(ConfigurationScope::class, new ConfigurationScope(
            repository: $container->make(Repository::class),
            providerKey: $provider,
            modelKey: $model,
            optionsKey: $options,
            supportedSettings: $settings,
        ));
    }
}
