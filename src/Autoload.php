<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\BenchmarkExecutor;
use Jkudish\PestAiBenchmarks\Benchmarks;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationContext;
use Jkudish\PestAiBenchmarks\Comparisons\DeclarationRegistry;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Evidence\RuntimeScorerCollector;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\Plugin;
use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;
use Jkudish\PestAiBenchmarks\Runs\ReplayReader;
use Jkudish\PestAiBenchmarks\Runs\ResumeReader;
use Jkudish\PestAiBenchmarks\Runs\RunPaths;
use Pest\TestSuite;
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
                $evaluationIdentity = $declaration->evaluation instanceof Closure
                    ? ExecutionRecorder::targetIdentity($declaration->evaluation)
                    : null;
                $fingerprint = ExecutionRecorder::trialFingerprint(
                    benchmark: $description,
                    caseId: $caseId,
                    configurationName: $configurationName,
                    configuration: $configuration,
                    targetIdentity: $targetIdentity,
                    evaluationIdentity: $evaluationIdentity,
                );
                $paths = RunPaths::forProject(TestSuite::getInstance()->rootPath);

                if (Plugin::replayRunId() !== null) {
                    if (! $declaration->evaluation instanceof Closure) {
                        throw new LogicException('Benchmark replay requires an evaluate(...) callback.');
                    }

                    $output = null;
                    $sourceTrial = null;
                    RuntimeScorerCollector::begin();

                    try {
                        (new ReplayReader($paths))->replayTrial(
                            runId: Plugin::replayRunId(),
                            benchmark: $description,
                            caseId: $caseId,
                            configuration: $configurationName,
                            repeat: $repeat,
                            fingerprint: $fingerprint,
                            consume: function (array $trial, mixed $replayedOutput) use (&$output, &$sourceTrial): void {
                                $sourceTrial = $trial;
                                $output = $replayedOutput;
                            },
                        );

                        $declaration->evaluation->call($this, $output, ...$caseArguments);
                    } finally {
                        $scorerObservations = RuntimeScorerCollector::finish();

                        if (is_array($sourceTrial)) {
                            ExecutionRecorder::recordReplay(
                                benchmark: $description,
                                caseId: $caseId,
                                configuration: $configurationName,
                                repeat: $repeat,
                                fingerprint: $fingerprint,
                                output: $output,
                                sourceTrial: $sourceTrial,
                                scorerObservations: $scorerObservations,
                                declaration: $declaration,
                            );
                        }
                    }

                    return $output;
                }

                if (Plugin::resumeRunId() !== null) {
                    $output = null;
                    $reusedFingerprint = null;
                    $reused = (new ResumeReader($paths))->reuseCompletedTrial(
                        runId: Plugin::resumeRunId(),
                        benchmark: $description,
                        caseId: $caseId,
                        configuration: $configurationName,
                        repeat: $repeat,
                        fingerprint: $fingerprint,
                        reuse: function (array $trial, mixed $replayedOutput) use (&$output, &$reusedFingerprint, $declaration, $description): void {
                            $output = $replayedOutput;
                            $reusedFingerprint = $trial['fingerprint'] ?? null;
                            ExecutionRecorder::reuse($description, $trial, $replayedOutput, $declaration);
                        },
                    );

                    if ($reused) {
                        Assert::assertSame($fingerprint, $reusedFingerprint, 'Resumed trial fingerprint must match the requested execution.');

                        return $output;
                    }
                }

                return (new BenchmarkExecutor)->run(
                    $configuration,
                    function (ModelIdentityEvidence $identity) use ($caseArguments, $caseId, $configuration, $configurationName, $declaration, $description, $evaluationIdentity, $fingerprint, $repeat, $targetIdentity, $test): mixed {
                        $startedAt = hrtime(true);
                        $output = null;
                        $passed = false;
                        RuntimeObservationCollector::begin();
                        RuntimeScorerCollector::begin();

                        try {
                            $output = $test->call($this, ...$caseArguments);
                            $passed = true;

                            if ($declaration->evaluation instanceof Closure) {
                                $declaration->evaluation->call($this, $output, ...$caseArguments);
                            }
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
                                fingerprint: $fingerprint,
                                declaration: $declaration,
                                evaluationIdentity: $evaluationIdentity,
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

if (! function_exists('benchmarks')) {
    /** Configure how benchmark candidates map to application configuration. */
    function benchmarks(): Benchmarks
    {
        return new Benchmarks;
    }
}
