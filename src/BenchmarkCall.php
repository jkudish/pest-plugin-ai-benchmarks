<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Closure;
use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationContext;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationRegistry;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionPolicy;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;
use Pest\PendingCalls\TestCall;

/**
 * A narrow compatibility boundary around Pest's internal TestCall.
 *
 * Pest keeps TestCall internal and final. Keeping that dependency in this one
 * class lets the plugin retain Pest's native datasets, filters, failures, and
 * lifecycle while giving compatibility tests one explicit boundary to cover.
 */
final class BenchmarkCall
{
    /** @internal */
    public const string BENCHMARK_GROUP = '__pest_ai_benchmark';

    private bool $hasConfigurations = false;

    public function __construct(
        private readonly TestCall $testCall,
        DeclarationContext $declarationContext,
        string $description,
    ) {
        $this->testCall->group(self::BENCHMARK_GROUP);
        DeclarationRegistry::register($this, $declarationContext, $description);
    }

    /**
     * @param  array<array-key, mixed>  $configurations
     */
    public function configurations(array $configurations): self
    {
        if ($this->hasConfigurations) {
            throw new InvalidArgumentException('Benchmark configurations may only be declared once.');
        }

        if ($configurations === []) {
            throw new InvalidArgumentException('Benchmark configurations may not be empty.');
        }

        foreach ($configurations as $name => $configuration) {
            if (! is_string($name) || $name === '' || trim($name) !== $name || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                throw new InvalidArgumentException('Benchmark configuration names must be non-empty, stable strings without surrounding whitespace or control characters.');
            }

            if (! $configuration instanceof Configuration) {
                throw new InvalidArgumentException(sprintf('Benchmark configuration [%s] must be an instance of %s.', $name, Configuration::class));
            }
        }

        $this->testCall->with(array_map(
            static fn (Configuration $configuration): ConfigurationDatasetValue => new ConfigurationDatasetValue($configuration),
            $configurations,
        ));
        $this->hasConfigurations = true;
        /** @var array<string, Configuration> $configurations */
        DeclarationRegistry::setConfigurations($this, $configurations);

        return $this;
    }

    /**
     * @param  array<mixed>  $context
     */
    public function context(array $context): self
    {
        DeclarationRegistry::setContext($this, new OpaqueContext($context));

        return $this;
    }

    public function reference(string $configuration): self
    {
        if (! $this->hasConfigurations) {
            throw new InvalidArgumentException('A benchmark reference must be declared after configurations.');
        }

        DeclarationRegistry::setReference($this, $configuration);

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $thresholds
     */
    public function failWhen(array $thresholds): self
    {
        DeclarationRegistry::setRegressionPolicy($this, RegressionPolicy::from($thresholds));

        return $this;
    }

    /**
     * Declare the reusable expectation boundary invoked with target output.
     *
     * @param  Closure(mixed, mixed...): mixed  $callback
     */
    public function evaluate(Closure $callback): self
    {
        DeclarationRegistry::setEvaluation($this, $callback);

        return $this;
    }

    /** @param array<array-key, mixed> $dependencies */
    public function dependsOn(array $dependencies): self
    {
        if ($dependencies === [] || ! array_is_list($dependencies)) {
            throw new InvalidArgumentException('Benchmark source dependencies must be a non-empty list.');
        }

        $validated = [];

        foreach ($dependencies as $dependency) {
            if (! is_string($dependency)
                || trim($dependency) === ''
                || trim($dependency) !== $dependency
                || preg_match('/[\x00-\x1F\x7F]/', $dependency) === 1) {
                throw new InvalidArgumentException('Benchmark source dependencies must be non-empty class names or file paths without surrounding whitespace or control characters.');
            }

            if (in_array($dependency, $validated, true)) {
                throw new InvalidArgumentException(sprintf('Benchmark source dependency [%s] is duplicated.', $dependency));
            }

            $validated[] = $dependency;
        }

        DeclarationRegistry::setDependencies($this, $validated);

        return $this;
    }

    /**
     * @param  Closure|iterable<array-key, mixed>|string  ...$data
     */
    public function with(Closure|iterable|string ...$data): self
    {
        $this->testCall->with(...$data);

        return $this;
    }

    public function repeat(int $times): self
    {
        $this->testCall->repeat($times);
        DeclarationRegistry::setRepetitions($this, $times);

        return $this;
    }

    public function group(string ...$groups): self
    {
        $this->testCall->group(...$groups);

        return $this;
    }

    public function skip(Closure|bool|string $conditionOrMessage = true, string $message = ''): self
    {
        $this->testCall->skip($conditionOrMessage, $message);

        return $this;
    }

    public function only(): self
    {
        $this->testCall->only();

        return $this;
    }

    public function depends(string ...$depends): self
    {
        $this->testCall->depends(...$depends);

        return $this;
    }
}
