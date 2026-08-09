<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use InvalidArgumentException;

/** @internal */
final readonly class RunId
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $this->value) !== 1
            || $this->value === '.'
            || $this->value === '..') {
            throw new InvalidArgumentException('Run IDs must be safe, explicit path segments.');
        }
    }
}
