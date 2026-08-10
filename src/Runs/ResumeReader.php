<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Closure;
use JsonException;

/** @internal */
final readonly class ResumeReader
{
    public function __construct(private RunPaths $paths) {}

    /**
     * @param  Closure(array<string, mixed>, mixed): void  $reuse
     *
     * @throws JsonException
     */
    public function reuseCompletedTrial(
        RunId $runId,
        string $benchmark,
        string $caseId,
        string $configuration,
        int $repeat,
        string $fingerprint,
        Closure $reuse,
    ): bool {
        $completed = (new SavedRun($this->paths, $runId))->completedTrial(
            $benchmark,
            $caseId,
            $configuration,
            $repeat,
            $fingerprint,
            requirePassed: true,
        );

        if ($completed === null) {
            return false;
        }

        $reuse($completed['trial'], $completed['output']);

        return true;
    }
}
