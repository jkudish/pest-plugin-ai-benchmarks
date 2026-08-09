<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

interface Clock
{
    public function nowNanoseconds(): int;
}
