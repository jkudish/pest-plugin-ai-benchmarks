<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LogicException;

final class Benchmarks
{
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
