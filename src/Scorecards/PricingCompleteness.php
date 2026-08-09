<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

/** @internal */
enum PricingCompleteness: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
