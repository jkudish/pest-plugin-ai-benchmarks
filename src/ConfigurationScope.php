<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Closure;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use RuntimeException;

/** @internal */
final class ConfigurationScope
{
    /** @var list<string> */
    private readonly array $supportedSettings;

    /**
     * @param  list<mixed>  $supportedSettings
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly string $providerKey,
        private readonly string $modelKey,
        private readonly ?string $optionsKey,
        array $supportedSettings = [],
    ) {
        $keys = [$providerKey, $modelKey];
        $validatedSettings = [];

        if ($optionsKey !== null) {
            $keys[] = $optionsKey;
        }

        foreach ($supportedSettings as $setting) {
            if (! is_string($setting)) {
                throw new InvalidArgumentException('Benchmark configuration settings must be strings.');
            }

            $validatedSettings[] = $setting;
            $keys[] = $setting;
        }

        $this->supportedSettings = $validatedSettings;

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Benchmark configuration keys must be unique.');
        }

        foreach ($keys as $key) {
            if ($key === '' || trim($key) !== $key) {
                throw new InvalidArgumentException('Benchmark configuration keys must be non-empty strings without surrounding whitespace.');
            }
        }

        foreach ($keys as $left) {
            foreach ($keys as $right) {
                if ($left !== $right && str_starts_with($left, $right.'.')) {
                    throw new InvalidArgumentException('Benchmark configuration keys must not overlap hierarchically.');
                }
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
                if ($this->optionsKey === null) {
                    throw new InvalidArgumentException('Model options require an application configuration key in benchmarks()->configure(...).');
                }

                $productionOptions = $this->repository->get($this->optionsKey);

                if (! is_array($productionOptions)) {
                    throw new RuntimeException(sprintf('Application configuration [%s] must contain an array before model options can be scoped.', $this->optionsKey));
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

    /** @return array<string, mixed> */
    public function fingerprint(Configuration $configuration): array
    {
        $settings = $this->validatedSettings($configuration);
        $dependencies = [
            $this->providerKey => $configuration->provider ?? $this->repository->get($this->providerKey),
            $this->modelKey => $configuration->model ?? $this->repository->get($this->modelKey),
        ];

        if ($this->optionsKey !== null) {
            $options = $this->repository->get($this->optionsKey);

            if ($configuration->options === []) {
                $dependencies[$this->optionsKey] = $options;
            } else {
                if (! is_array($options)) {
                    throw new RuntimeException(sprintf('Application configuration [%s] must contain an array before it can be fingerprinted.', $this->optionsKey));
                }

                $dependencies[$this->optionsKey] = array_replace($options, $configuration->options);
            }
        } elseif ($configuration->options !== []) {
            throw new InvalidArgumentException('Model options require an application configuration key in benchmarks()->configure(...).');
        }

        foreach ($this->supportedSettings as $setting) {
            $dependencies[$setting] = array_key_exists($setting, $settings)
                ? $settings[$setting]
                : $this->repository->get($setting);
        }

        ksort($dependencies);

        return $this->stableDependencies($dependencies);
    }

    /**
     * @return array<string, bool|float|int|string|null|array<mixed>>
     */
    private function validatedSettings(Configuration $configuration): array
    {
        $unsupported = array_diff(array_keys($configuration->settings), $this->supportedSettings);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported benchmark configuration setting(s): %s.',
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
                    'Application configuration [%s] must exist before it can be scoped.',
                    $key,
                ));
            }
        }
    }

    private function stringValue(string $key): string
    {
        $value = $this->repository->get($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Application configuration [%s] must resolve to a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $dependencies
     * @return array<string, mixed>
     */
    private function stableDependencies(array $dependencies): array
    {
        $stable = [];

        foreach ($dependencies as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException('Application configuration dependency keys must be non-empty strings.');
            }

            $stable[$key] = $this->stableDependencyValue($value, $key);
        }

        return $stable;
    }

    private function stableDependencyValue(mixed $value, string $key): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new RuntimeException(sprintf('Application configuration dependency [%s] must be JSON-safe.', $key));
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new RuntimeException(sprintf('Application configuration dependency [%s] must be JSON-safe.', $key));
        }

        $stable = [];

        foreach ($value as $nestedKey => $nestedValue) {
            $stable[$nestedKey] = $this->stableDependencyValue($nestedValue, $key);
        }

        if (! array_is_list($stable)) {
            ksort($stable);
        }

        return $stable;
    }
}
