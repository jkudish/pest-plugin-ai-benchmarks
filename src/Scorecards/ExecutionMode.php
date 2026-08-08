<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

/** @internal */
enum ExecutionMode: string
{
    case Live = 'live';
    case Recorded = 'recorded';
    case Simulated = 'simulated';
}
