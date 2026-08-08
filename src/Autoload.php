<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationContext;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationRegistry;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Plugin;
use PHPUnit\Framework\Assert;

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
            if (! Plugin::isEvalMode()) {
                Assert::markTestSkipped('Benchmark skipped. Run with [--evals] to execute production-path benchmarks.');
            }

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

        $call = new BenchmarkCall($testCall, $declarationContext);

        if (! Plugin::matches($description)) {
            $call->skip('Benchmark does not match the active [--benchmark] filter.');
        }

        return $call;
    }
}
