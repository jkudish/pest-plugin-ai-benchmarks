<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Closure;
use Illuminate\Container\Container;
use LogicException;

/** @internal */
final class BenchmarkExecutor
{
    /** @return array<string, mixed>|null */
    public function fingerprint(Configuration $configuration): ?array
    {
        $container = Container::getInstance();

        if ($container->bound(ConfigurationScope::class)) {
            return $container->make(ConfigurationScope::class)->fingerprint($configuration);
        }

        if ($configuration->provider !== null || $configuration->settings !== []) {
            throw new LogicException('Configuration overrides require benchmarks()->configure(...) with the application config keys used by the production path.');
        }

        return null;
    }

    /**
     * @template TResult
     *
     * @param  Closure(ModelIdentityEvidence): TResult  $callback
     * @return TResult
     */
    public function run(Configuration $configuration, Closure $callback): mixed
    {
        $container = Container::getInstance();

        if ($container->bound(ConfigurationScope::class)) {
            $scope = $container->make(ConfigurationScope::class);

            return $scope->run($configuration, $callback);
        }

        if ($configuration->provider !== null || $configuration->settings !== []) {
            throw new LogicException('Configuration overrides require benchmarks()->configure(...) with the application config keys used by the production path.');
        }

        return $callback(new ModelIdentityEvidence(
            requestedProvider: null,
            requestedModel: null,
            effectiveProvider: null,
            effectiveModel: null,
        ));
    }
}
