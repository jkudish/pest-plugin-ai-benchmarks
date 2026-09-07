<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Closure;
use ReflectionFunction;

/** @internal */
final class BenchmarkClosureInvoker
{
    public static function invoke(Closure $closure, object $testCase, mixed ...$arguments): mixed
    {
        if ((new ReflectionFunction($closure))->isStatic()) {
            return $closure(...$arguments);
        }

        return $closure->call($testCase, ...$arguments);
    }
}
