<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationContext;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationRegistry;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Evidence\RuntimeScorerCollector;
use Jkudish\PestAiBenchmarks\Laravel\BenchmarkExecutor;
use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\Plugin;
use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;
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
        $testCall = test($description, function () use ($declarationContext, $description, $test): mixed {
            if (! Plugin::isEvalMode()) {
                Assert::markTestSkipped('Benchmark skipped. Run with [--evals] to execute production-path benchmarks.');
            }

            $arguments = func_get_args();

            return DeclarationRegistry::within($declarationContext, function () use ($arguments, $description, $test): mixed {
                $configuration = Configuration::production();
                $configurationName = 'production';

                foreach ($arguments as $index => $argument) {
                    if ($argument instanceof Configuration) {
                        $configuration = $argument;
                        $configurationName = DeclarationRegistry::configurationName($argument);
                        unset($arguments[$index]);

                        break;
                    }
                }

                $declaration = DeclarationRegistry::current();
                $repeat = 1;

                if ($declaration->repetitions > 1) {
                    $repeatArgument = array_pop($arguments);

                    if (! is_int($repeatArgument) || $repeatArgument < 1 || $repeatArgument > $declaration->repetitions) {
                        throw new LogicException('Pest did not provide a valid benchmark repetition index.');
                    }

                    $repeat = $repeatArgument;
                }

                $caseArguments = array_values($arguments);
                $caseId = ExecutionRecorder::caseId($caseArguments);
                $targetIdentity = ExecutionRecorder::targetIdentity($test);

                return (new BenchmarkExecutor)->run(
                    $configuration,
                    function (ModelIdentityEvidence $identity) use ($caseArguments, $caseId, $configuration, $configurationName, $declaration, $description, $repeat, $targetIdentity, $test): mixed {
                        $startedAt = hrtime(true);
                        $output = null;
                        $passed = false;
                        RuntimeObservationCollector::begin();
                        RuntimeScorerCollector::begin();

                        try {
                            $output = $test->call($this, ...$caseArguments);
                            $passed = true;
                        } catch (Throwable $exception) {
                            throw $exception;
                        } finally {
                            $observations = RuntimeObservationCollector::finish();
                            $scorerObservations = RuntimeScorerCollector::finish();

                            ExecutionRecorder::record(
                                benchmark: $description,
                                caseId: $caseId,
                                configurationName: $configurationName,
                                configuration: $configuration,
                                identity: $identity,
                                latencyMs: (hrtime(true) - $startedAt) / 1_000_000,
                                passed: $passed,
                                output: $output,
                                context: $declaration->context,
                                targetIdentity: $targetIdentity,
                                observations: $observations,
                                scorerObservations: $scorerObservations,
                                repeat: $repeat,
                            );
                        }

                        return $output;
                    },
                );
            });
        });

        $call = new BenchmarkCall($testCall, $declarationContext);

        if (! Plugin::matches($description)) {
            $call->skip('Benchmark does not match the active [--benchmark] filter.');
        }

        return $call;
    }
}
