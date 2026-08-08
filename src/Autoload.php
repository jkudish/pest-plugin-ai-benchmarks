<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Configuration;

if (! function_exists('benchmark')) {
    /**
     * Declare an AI benchmark using Pest's native test lifecycle.
     *
     * @param  Closure(mixed...): mixed  $test
     */
    function benchmark(string $description, Closure $test): BenchmarkCall
    {
        $testCall = test($description, function () use ($test): mixed {
            $arguments = func_get_args();

            foreach ($arguments as $index => $argument) {
                if ($argument instanceof Configuration) {
                    unset($arguments[$index]);

                    break;
                }
            }

            return $test->call($this, ...array_values($arguments));
        });

        return new BenchmarkCall($testCall);
    }
}
