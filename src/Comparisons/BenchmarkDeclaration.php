<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use Closure;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;

/** @internal */
final readonly class BenchmarkDeclaration
{
    /**
     * @param  list<string>  $configurations
     * @param  array<string, Configuration>  $configurationValues
     * @param  list<string>  $dependencies
     */
    public function __construct(
        public array $configurations = [],
        public array $configurationValues = [],
        public ?string $reference = null,
        public ?OpaqueContext $context = null,
        public ?RegressionPolicy $regressionPolicy = null,
        public int $repetitions = 1,
        public ?Closure $evaluation = null,
        public array $dependencies = [],
    ) {}

    /** @param array<string, Configuration> $configurations */
    public function withConfigurations(array $configurations): self
    {
        return new self(array_keys($configurations), $configurations, $this->reference, $this->context, $this->regressionPolicy, $this->repetitions, $this->evaluation, $this->dependencies);
    }

    public function withReference(string $reference): self
    {
        return new self($this->configurations, $this->configurationValues, $reference, $this->context, $this->regressionPolicy, $this->repetitions, $this->evaluation, $this->dependencies);
    }

    public function withContext(OpaqueContext $context): self
    {
        return new self($this->configurations, $this->configurationValues, $this->reference, $context, $this->regressionPolicy, $this->repetitions, $this->evaluation, $this->dependencies);
    }

    public function withRegressionPolicy(RegressionPolicy $regressionPolicy): self
    {
        return new self($this->configurations, $this->configurationValues, $this->reference, $this->context, $regressionPolicy, $this->repetitions, $this->evaluation, $this->dependencies);
    }

    public function withRepetitions(int $repetitions): self
    {
        return new self($this->configurations, $this->configurationValues, $this->reference, $this->context, $this->regressionPolicy, $repetitions, $this->evaluation, $this->dependencies);
    }

    public function withEvaluation(Closure $evaluation): self
    {
        return new self($this->configurations, $this->configurationValues, $this->reference, $this->context, $this->regressionPolicy, $this->repetitions, $evaluation, $this->dependencies);
    }

    /** @param list<string> $dependencies */
    public function withDependencies(array $dependencies): self
    {
        return new self($this->configurations, $this->configurationValues, $this->reference, $this->context, $this->regressionPolicy, $this->repetitions, $this->evaluation, $dependencies);
    }
}
