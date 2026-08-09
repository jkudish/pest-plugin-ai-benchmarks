<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;
use Jkudish\PestAiBenchmarks\Reporters\TerminalReporter;
use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\HandlesOriginalArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\Plugins\Parallel;
use Pest\Support\Container;
use Symfony\Component\Console\Output\OutputInterface;

final class Plugin implements AddsOutput, HandlesArguments, HandlesOriginalArguments, Terminable
{
    private const string EVAL_MODE_ENV = 'PEST_EVALS';

    private static bool $evalMode = false;

    private static ?string $benchmarkFilter = null;

    /** @param array<int, string> $arguments */
    public function handleOriginalArguments(array $arguments): void
    {
        $evalMode = in_array('--evals', $arguments, true);
        $benchmarkFilter = $this->benchmarkFilter($arguments);

        if ($evalMode && $this->hasParallelArgument($arguments)) {
            throw new InvalidArgumentException('AI benchmarks do not support parallel execution; remove [--parallel] or [-p].');
        }

        self::$evalMode = $evalMode;
        self::$benchmarkFilter = $benchmarkFilter;
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

            if ($argument === '--benchmark') {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($argument, '--benchmark=')) {
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

    public function terminate(): void
    {
        ExecutionRecorder::flush();
    }

    public function addOutput(int $exitCode): int
    {
        $output = Container::getInstance()->get(OutputInterface::class);

        if ($output instanceof OutputInterface) {
            $reporter = new TerminalReporter;

            foreach (ExecutionRecorder::flush() as $scorecard) {
                $output->write(PHP_EOL.$reporter->render($scorecard));
            }
        }

        return $exitCode;
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
