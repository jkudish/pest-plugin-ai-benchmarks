<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

/**
 * @template TValue
 */
final readonly class MeasuredValue
{
    /**
     * @param  TValue  $value
     */
    public function __construct(
        public mixed $value,
        public Latency $latency,
    ) {}
}
