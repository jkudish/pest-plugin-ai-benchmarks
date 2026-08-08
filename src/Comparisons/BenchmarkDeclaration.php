<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use Jkudish\PestAiBenchmarks\Results\OpaqueContext;

/** @internal */
final readonly class BenchmarkDeclaration
{
    /**
     * @param  list<string>  $configurations
     */
    public function __construct(
        public array $configurations = [],
        public ?string $reference = null,
        public ?OpaqueContext $context = null,
        public ?RegressionPolicy $regressionPolicy = null,
    ) {}

    /** @param list<string> $configurations */
    public function withConfigurations(array $configurations): self
    {
        return new self($configurations, $this->reference, $this->context, $this->regressionPolicy);
    }

    public function withReference(string $reference): self
    {
        return new self($this->configurations, $reference, $this->context, $this->regressionPolicy);
    }

    public function withContext(OpaqueContext $context): self
    {
        return new self($this->configurations, $this->reference, $context, $this->regressionPolicy);
    }

    public function withRegressionPolicy(RegressionPolicy $regressionPolicy): self
    {
        return new self($this->configurations, $this->reference, $this->context, $regressionPolicy);
    }
}
