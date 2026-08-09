<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Laravel;

use Closure;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Configuration;
use RuntimeException;

final class LaravelConfigurationScope
{
    /**
     * @param  list<string>  $supportedSettings
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly string $providerKey,
        private readonly string $modelKey,
        private readonly string $optionsKey,
        private readonly array $supportedSettings = [],
    ) {
        $keys = [$providerKey, $modelKey, $optionsKey, ...$supportedSettings];

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Laravel benchmark configuration keys must be unique.');
        }

        foreach ($keys as $key) {
            if ($key === '' || trim($key) !== $key) {
                throw new InvalidArgumentException('Laravel benchmark configuration keys must be non-empty strings without surrounding whitespace.');
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(ModelIdentityEvidence): TResult  $callback
     * @return TResult
     */
    public function run(Configuration $configuration, Closure $callback): mixed
    {
        $settings = $this->validatedSettings($configuration);
        $changes = $settings;

        if ($configuration->provider !== null) {
            $changes[$this->providerKey] = $configuration->provider;
            $changes[$this->modelKey] = $configuration->model;

            if ($configuration->options !== []) {
                $productionOptions = $this->repository->get($this->optionsKey);

                if (! is_array($productionOptions)) {
                    throw new RuntimeException(sprintf('Laravel configuration [%s] must contain an array before model options can be scoped.', $this->optionsKey));
                }

                $changes[$this->optionsKey] = array_replace($productionOptions, $configuration->options);
            }
        }

        $this->assertRestorable(array_keys($changes));

        $original = [];

        foreach ($changes as $key => $value) {
            $original[$key] = $this->repository->get($key);
            $this->repository->set($key, $value);
        }

        try {
            return $callback(new ModelIdentityEvidence(
                requestedProvider: $configuration->provider,
                requestedModel: $configuration->model,
                effectiveProvider: $this->stringValue($this->providerKey),
                effectiveModel: $this->stringValue($this->modelKey),
            ));
        } finally {
            foreach ($original as $key => $value) {
                $this->repository->set($key, $value);
            }
        }
    }

    /**
     * @return array<string, bool|float|int|string|null|array<mixed>>
     */
    private function validatedSettings(Configuration $configuration): array
    {
        $unsupported = array_diff(array_keys($configuration->settings), $this->supportedSettings);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Laravel benchmark configuration setting(s): %s.',
                implode(', ', $unsupported),
            ));
        }

        return $configuration->settings;
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertRestorable(array $keys): void
    {
        foreach ($keys as $key) {
            if (! $this->repository->has($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Laravel configuration [%s] must exist before it can be scoped.',
                    $key,
                ));
            }
        }
    }

    private function stringValue(string $key): string
    {
        $value = $this->repository->get($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Laravel configuration [%s] must resolve to a non-empty string.', $key));
        }

        return $value;
    }
}
