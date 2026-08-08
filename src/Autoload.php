<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationContext;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationRegistry;
use Jkudish\PestAiBenchmarks\Configuration;

if (! function_exists('benchmark')) {
    /**
     * Declare an AI benchmark using Pest's native test lifecycle.
     *
     * @param  Closure(mixed...): mixed  $test
     */
    function benchmark(string $description, Closure $test): BenchmarkCall
    {
        $declarationContext = new DeclarationContext;
        $testCall = test($description, function () use ($declarationContext, $test): mixed {
            $arguments = func_get_args();

            return DeclarationRegistry::within($declarationContext, function () use ($arguments, $test): mixed {

                foreach ($arguments as $index => $argument) {
                    if ($argument instanceof Configuration) {
                        unset($arguments[$index]);

                        break;
                    }
                }

                return $test->call($this, ...array_values($arguments));
            });
        });

        return new BenchmarkCall($testCall, $declarationContext);
    }
}
