<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Evidence\PestEvalObservation;
use Jkudish\PestAiBenchmarks\Evidence\RuntimeScorerCollector;
use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;
use Jkudish\PestAiBenchmarks\Reporters\TerminalReporter;
use Jkudish\PestAiBenchmarks\Runs\RunId;
use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\HandlesOriginalArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\Evals\Events\Scored;
use Pest\Plugins\Parallel;
use Pest\Support\Container;
use Symfony\Component\Console\Output\OutputInterface;

final class Plugin implements AddsOutput, Bootable, HandlesArguments, HandlesOriginalArguments, Terminable
{
    private const string EVAL_MODE_ENV = 'PEST_EVALS';

    private static bool $evalMode = false;

    private static ?string $benchmarkFilter = null;

    private static ?RunId $replayRunId = null;

    private static ?RunId $resumeRunId = null;

    private static ?string $baselineName = null;

    public function boot(): void
    {
        pest()->evals()->afterScored(function (Scored $event): void {
            RuntimeScorerCollector::record(PestEvalObservation::fromEvent($event));
        });
    }

    /** @param array<int, string> $arguments */
    public function handleOriginalArguments(array $arguments): void
    {
        $evalMode = in_array('--evals', $arguments, true);
        $benchmarkFilter = $this->benchmarkFilter($arguments);
        $replayRunId = $this->runOption($arguments, '--benchmark-replay');
        $resumeRunId = $this->runOption($arguments, '--benchmark-resume');
        $baselineName = $this->namedOption($arguments, '--benchmark-baseline');

        if ($evalMode && $this->hasParallelArgument($arguments)) {
            throw new InvalidArgumentException('AI benchmarks do not support parallel execution; remove [--parallel] or [-p].');
        }

        if ($replayRunId instanceof RunId && $resumeRunId instanceof RunId) {
            throw new InvalidArgumentException('The [--benchmark-replay] and [--benchmark-resume] options are mutually exclusive.');
        }

        if (! $evalMode && ($replayRunId instanceof RunId || $resumeRunId instanceof RunId || $baselineName !== null)) {
            throw new InvalidArgumentException('Benchmark lifecycle options require explicit [--evals] mode.');
        }

        self::$evalMode = $evalMode;
        self::$benchmarkFilter = $benchmarkFilter;
        self::$replayRunId = $replayRunId;
        self::$resumeRunId = $resumeRunId;
        self::$baselineName = $baselineName;
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        $filtered = [];
        $skipNext = false;

        foreach ($arguments as $argument) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if (in_array($argument, ['--benchmark', '--benchmark-replay', '--benchmark-resume', '--benchmark-baseline'], true)) {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($argument, '--benchmark=')
                || str_starts_with($argument, '--benchmark-replay=')
                || str_starts_with($argument, '--benchmark-resume=')
                || str_starts_with($argument, '--benchmark-baseline=')) {
                continue;
            }

            $filtered[] = $argument;
        }

        if (self::$benchmarkFilter !== null) {
            $filtered[] = '--group='.BenchmarkCall::BENCHMARK_GROUP;
        }

        return $filtered;
    }

    public static function isEvalMode(): bool
    {
        return self::$evalMode
            || ($_SERVER[self::EVAL_MODE_ENV] ?? $_ENV[self::EVAL_MODE_ENV] ?? null) === '1'
            || Parallel::getGlobal(self::EVAL_MODE_ENV) === true
            || (class_exists(\Pest\Evals\Plugin::class) && \Pest\Evals\Plugin::isEvalMode());
    }

    public static function replayRunId(): ?RunId
    {
        return self::$replayRunId;
    }

    public static function resumeRunId(): ?RunId
    {
        return self::$resumeRunId;
    }

    public static function baselineName(): ?string
    {
        return self::$baselineName;
    }

    public function terminate(): void
    {
        ExecutionRecorder::flush();
    }

    public function addOutput(int $exitCode): int
    {
        $output = Container::getInstance()->get(OutputInterface::class);
        $gateFailed = false;

        if ($output instanceof OutputInterface) {
            $reporter = new TerminalReporter;

            foreach (ExecutionRecorder::flush() as $scorecard) {
                $output->write(PHP_EOL.$reporter->render($scorecard));
                $evaluation = ExecutionRecorder::evaluation($scorecard);

                if ($evaluation !== null) {
                    $output->write($reporter->renderComparison(
                        evaluation: $evaluation,
                        reference: ExecutionRecorder::reference($scorecard),
                        baseline: self::$baselineName,
                    ));
                    $output->write($reporter->renderReferenceComparisons(
                        evaluations: ExecutionRecorder::referenceEvaluations($scorecard),
                        reference: ExecutionRecorder::reference($scorecard),
                    ));
                    $gateFailed = $gateFailed || ! $evaluation->passed();
                }
            }
        }

        return $gateFailed ? max(1, $exitCode) : $exitCode;
    }

    public static function matches(string $description): bool
    {
        return self::$benchmarkFilter === null
            || stripos($description, self::$benchmarkFilter) !== false;
    }

    /** @param array<int, string> $arguments */
    private function benchmarkFilter(array $arguments): ?string
    {
        $filter = null;

        foreach ($arguments as $index => $argument) {
            if ($argument === '--benchmark') {
                $filter = $this->uniqueFilter($filter, $this->validatedFilter($arguments[$index + 1] ?? null));
            }

            if (str_starts_with($argument, '--benchmark=')) {
                $filter = $this->uniqueFilter($filter, $this->validatedFilter(substr($argument, strlen('--benchmark='))));
            }
        }

        return $filter;
    }

    private function validatedFilter(?string $filter): string
    {
        if ($filter === null
            || trim($filter) === ''
            || trim($filter) !== $filter
            || str_starts_with($filter, '-')
            || preg_match('/[\x00-\x1F\x7F]/', $filter) === 1) {
            throw new InvalidArgumentException('The [--benchmark] option requires a non-empty benchmark name.');
        }

        return $filter;
    }

    /** @param array<int, string> $arguments */
    private function runOption(array $arguments, string $option): ?RunId
    {
        $value = $this->optionValue($arguments, $option);

        return $value === null ? null : new RunId($value);
    }

    /** @param array<int, string> $arguments */
    private function namedOption(array $arguments, string $option): ?string
    {
        $value = $this->optionValue($arguments, $option);

        if ($value !== null) {
            new RunId($value);
        }

        return $value;
    }

    /** @param array<int, string> $arguments */
    private function optionValue(array $arguments, string $option): ?string
    {
        $value = null;

        foreach ($arguments as $index => $argument) {
            if ($argument !== $option && ! str_starts_with($argument, $option.'=')) {
                continue;
            }

            if ($value !== null) {
                throw new InvalidArgumentException("The [{$option}] option may only be supplied once.");
            }

            $candidate = $argument === $option
                ? ($arguments[$index + 1] ?? null)
                : substr($argument, strlen($option) + 1);

            if (! is_string($candidate)
                || trim($candidate) === ''
                || trim($candidate) !== $candidate
                || str_starts_with($candidate, '-')) {
                throw new InvalidArgumentException("The [{$option}] option requires a non-empty name.");
            }

            $value = $candidate;
        }

        return $value;
    }

    private function uniqueFilter(?string $existing, string $filter): string
    {
        if ($existing !== null) {
            throw new InvalidArgumentException('The [--benchmark] option may only be supplied once.');
        }

        return $filter;
    }

    /** @param array<int, string> $arguments */
    private function hasParallelArgument(array $arguments): bool
    {
        return in_array('--parallel', $arguments, true) || in_array('-p', $arguments, true);
    }
}
