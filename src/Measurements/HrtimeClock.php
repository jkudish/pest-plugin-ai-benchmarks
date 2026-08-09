<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use RuntimeException;

final class HrtimeClock implements Clock
{
    public function nowNanoseconds(): int
    {
        $nanoseconds = hrtime(true);

        if (! is_int($nanoseconds)) {
            throw new RuntimeException('The monotonic clock did not return integer nanoseconds.');
        }

        return $nanoseconds;
    }
}
