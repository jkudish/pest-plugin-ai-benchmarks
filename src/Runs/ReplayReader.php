<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Closure;
use JsonException;
use RuntimeException;

/** @internal */
final readonly class ReplayReader
{
    public function __construct(private RunPaths $paths) {}

    /**
     * @param  Closure(string, mixed, string): void  $consume
     *
     * @throws JsonException
     */
    public function replay(RunId $runId, Closure $consume): void
    {
        $run = $this->verifiedRunDirectory($runId);
        $contents = file_get_contents($run.DIRECTORY_SEPARATOR.RunBundle::REPLAY_FILE);

        if ($contents === false) {
            throw new RuntimeException("Replay payload for run [{$runId->value}] is unavailable.");
        }

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ($payload['schema_version'] ?? null) !== ReplayPayload::SCHEMA_VERSION || ! is_array($payload['trials'] ?? null)) {
            throw new RuntimeException('Replay payload has an unsupported structure.');
        }

        foreach ($payload['trials'] as $trial) {
            if (! is_array($trial)
                || ! is_string($trial['trial_id'] ?? null)
                || ! is_string($trial['fingerprint'] ?? null)
                || ! array_key_exists('output', $trial)) {
                throw new RuntimeException('Replay payload contains an invalid trial.');
            }

            $consume($trial['trial_id'], $trial['output'], $trial['fingerprint']);
        }
    }

    /**
     * @param  Closure(array<string, mixed>, mixed): void  $consume
     *
     * @throws JsonException
     */
    public function replayTrial(
        RunId $runId,
        string $benchmark,
        string $caseId,
        string $configuration,
        int $repeat,
        string $fingerprint,
        Closure $consume,
    ): void {
        $completed = (new SavedRun($this->paths, $runId))->completedTrial(
            $benchmark,
            $caseId,
            $configuration,
            $repeat,
            $fingerprint,
        );

        if ($completed === null) {
            throw new RuntimeException('Replay run does not contain the requested completed trial.');
        }

        $consume($completed['trial'], $completed['output']);
    }

    private function verifiedRunDirectory(RunId $runId): string
    {
        $root = realpath($this->paths->runs);
        $run = realpath($this->paths->run($runId));

        if ($root === false || $run === false || ! is_dir($run) || ! str_starts_with($run, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Run [{$runId->value}] does not exist within run storage.");
        }

        return $run;
    }
}
