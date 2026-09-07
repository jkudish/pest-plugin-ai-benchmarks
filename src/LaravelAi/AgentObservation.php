<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use InvalidArgumentException;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;

final readonly class AgentObservation
{
    public function __construct(
        public string $requestedProvider,
        public string $requestedModel,
        public ?string $effectiveProvider,
        public ?string $effectiveModel,
        public NormalizedUsage $usage,
        public float $latencyMs,
        public bool $succeeded,
        public ExecutionMode $mode = ExecutionMode::Live,
        public ?Money $providerReportedCost = null,
        public Component $component = Component::Target,
    ) {
        if (trim($this->requestedProvider) === '' || trim($this->requestedModel) === '') {
            throw new InvalidArgumentException('Requested provider and model must not be empty.');
        }

        if (($this->effectiveProvider === null) !== ($this->effectiveModel === null)) {
            throw new InvalidArgumentException('Effective provider and model must either both be present or both be absent.');
        }

        if ($this->effectiveProvider !== null
            && (trim($this->effectiveProvider) === '' || trim((string) $this->effectiveModel) === '')) {
            throw new InvalidArgumentException('Effective provider and model must not be empty.');
        }

        if (! is_finite($this->latencyMs) || $this->latencyMs < 0) {
            throw new InvalidArgumentException('Agent observation latency must be finite and non-negative.');
        }
    }
}
