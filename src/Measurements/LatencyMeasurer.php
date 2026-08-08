<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use Closure;
use RuntimeException;

final readonly class LatencyMeasurer
{
    public function __construct(private Clock $clock) {}

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return MeasuredValue<TValue>
     */
    public function measure(Closure $callback): MeasuredValue
    {
        $startedAt = $this->clock->nowNanoseconds();
        $value = $callback();
        $finishedAt = $this->clock->nowNanoseconds();

        if ($finishedAt < $startedAt) {
            throw new RuntimeException('The monotonic clock moved backwards.');
        }

        return new MeasuredValue($value, new Latency($finishedAt - $startedAt));
    }
}
