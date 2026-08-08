<?php

namespace Jkudish\PestAiBenchmarks\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Jkudish\PestAiBenchmarks\PestAiBenchmarks
 */
class PestAiBenchmarks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Jkudish\PestAiBenchmarks\PestAiBenchmarks::class;
    }
}
