<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use InvalidArgumentException;
use Jkudish\LaravelAiPricing\ValueObjects\Money;

final readonly class AggregateEvidence
{
    public function __construct(
        public string $compatibilityFingerprint,
        public ?float $passRate = null,
        public ?float $medianLatency = null,
        public ?Money $averageCost = null,
    ) {
        if ($compatibilityFingerprint === '') {
            throw new InvalidArgumentException('Aggregate evidence requires a compatibility fingerprint.');
        }

        if ($passRate !== null && (! is_finite($passRate) || $passRate < 0 || $passRate > 1)) {
            throw new InvalidArgumentException('Aggregate pass rate must be between zero and one.');
        }

        if ($medianLatency !== null && (! is_finite($medianLatency) || $medianLatency < 0)) {
            throw new InvalidArgumentException('Aggregate median latency must be finite and non-negative.');
        }
    }
}
