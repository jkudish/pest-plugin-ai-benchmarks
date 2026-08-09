<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use InvalidArgumentException;

final readonly class Latency
{
    public function __construct(public int $nanoseconds)
    {
        if ($nanoseconds < 0) {
            throw new InvalidArgumentException('Latency may not be negative.');
        }
    }

    public function milliseconds(): float
    {
        return $this->nanoseconds / 1_000_000;
    }
}
