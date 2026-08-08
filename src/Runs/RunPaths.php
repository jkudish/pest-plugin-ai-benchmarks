<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use InvalidArgumentException;

/** @internal */
final readonly class RunPaths
{
    public function __construct(
        public string $runs,
        public string $baselines,
    ) {
        self::assertAbsolute($this->runs);
        self::assertAbsolute($this->baselines);
    }

    public static function forProject(string $projectRoot): self
    {
        self::assertAbsolute($projectRoot);
        $separator = self::separator($projectRoot);
        $root = rtrim($projectRoot, '/\\');

        if ($separator === '\\') {
            $root = str_replace('/', '\\', $root);
        }

        return new self(
            runs: implode($separator, [$root, 'storage', 'app', 'ai-evals', 'runs']),
            baselines: implode($separator, [$root, 'tests', 'Evals', 'Baselines']),
        );
    }

    public function run(RunId $runId): string
    {
        return $this->runs.self::separator($this->runs).$runId->value;
    }

    public function baseline(string $name): string
    {
        $id = new RunId($name);

        return $this->baselines.self::separator($this->baselines).$id->value.'.json';
    }

    private static function assertAbsolute(string $path): void
    {
        $isUnix = str_starts_with($path, '/');
        $isWindowsDrive = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $isUnc = preg_match('/^(?:\\\\\\\\|\/\/)[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $path) === 1;

        if ($path === '' || str_contains($path, "\0") || (! $isUnix && ! $isWindowsDrive && ! $isUnc)) {
            throw new InvalidArgumentException('Run paths must be absolute.');
        }
    }

    private static function separator(string $path): string
    {
        return str_contains($path, '\\') ? '\\' : '/';
    }
}
