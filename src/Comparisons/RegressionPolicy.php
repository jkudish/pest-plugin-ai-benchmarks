<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use InvalidArgumentException;

final readonly class RegressionPolicy
{
    public const string PASS_RATE_DROP = 'pass_rate_drop';

    public const string MEDIAN_LATENCY_INCREASE = 'median_latency_increase';

    public const string AVERAGE_COST_INCREASE = 'average_cost_increase';

    private const array SUPPORTED = [
        self::PASS_RATE_DROP,
        self::MEDIAN_LATENCY_INCREASE,
        self::AVERAGE_COST_INCREASE,
    ];

    /** @param array<string, float> $thresholds */
    private function __construct(public array $thresholds) {}

    /**
     * @param  array<array-key, mixed>  $thresholds
     */
    public static function from(array $thresholds): self
    {
        $normalized = [];

        foreach ($thresholds as $metric => $threshold) {
            if (! is_string($metric) || ! in_array($metric, self::SUPPORTED, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported benchmark regression gate [%s].', (string) $metric));
            }

            if (! is_int($threshold) && ! is_float($threshold)) {
                throw new InvalidArgumentException(sprintf('Benchmark regression gate [%s] must be numeric.', $metric));
            }

            $value = (float) $threshold;

            if (! is_finite($value) || $value < 0) {
                throw new InvalidArgumentException(sprintf('Benchmark regression gate [%s] must be finite and non-negative.', $metric));
            }

            $normalized[$metric] = $value;
        }

        return new self($normalized);
    }

    public static function evidenceOnly(): self
    {
        return new self([]);
    }

    public function threshold(string $metric): ?float
    {
        return $this->thresholds[$metric] ?? null;
    }

    public function hasGates(): bool
    {
        return $this->thresholds !== [];
    }
}
