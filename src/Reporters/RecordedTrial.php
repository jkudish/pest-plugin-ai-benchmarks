<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\PestAiBenchmarks\Evidence\PestEvalObservation;
use Jkudish\PestAiBenchmarks\LaravelAi\AgentObservation;
use Jkudish\PestAiBenchmarks\ModelIdentityEvidence;

/** @internal */
final readonly class RecordedTrial
{
    /**
     * @param  list<AgentObservation>  $observations
     * @param  list<CostQuote>  $pricingQuotes
     * @param  list<PestEvalObservation>  $scorerObservations
     * @param  list<array<string, mixed>>|null  $stableResults
     * @param  list<array<string, mixed>>|null  $sourceMeasurements
     */
    public function __construct(
        public string $benchmark,
        public string $caseId,
        public string $configuration,
        public int $repeat,
        public string $fingerprint,
        public ModelIdentityEvidence $identity,
        public float $latencyMs,
        public bool $passed,
        public mixed $output,
        public array $observations = [],
        public array $pricingQuotes = [],
        public array $scorerObservations = [],
        public ?array $stableResults = null,
        public ?array $sourceMeasurements = null,
    ) {}
}
