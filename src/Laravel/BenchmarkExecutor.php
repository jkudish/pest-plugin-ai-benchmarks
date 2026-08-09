<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Laravel;

use Closure;
use Illuminate\Container\Container;
use Jkudish\PestAiBenchmarks\Configuration;
use LogicException;

/** @internal */
final class BenchmarkExecutor
{
    /**
     * @template TResult
     *
     * @param  Closure(ModelIdentityEvidence): TResult  $callback
     * @return TResult
     */
    public function run(Configuration $configuration, Closure $callback): mixed
    {
        $container = Container::getInstance();

        if ($container->bound(LaravelConfigurationScope::class)) {
            $scope = $container->make(LaravelConfigurationScope::class);

            return $scope->run($configuration, $callback);
        }

        if ($configuration->provider !== null || $configuration->settings !== []) {
            throw new LogicException(sprintf(
                'Configuration overrides require a bound %s with the application config keys used by the production path.',
                LaravelConfigurationScope::class,
            ));
        }

        return $callback(new ModelIdentityEvidence(
            requestedProvider: null,
            requestedModel: null,
            effectiveProvider: null,
            effectiveModel: null,
        ));
    }
}
