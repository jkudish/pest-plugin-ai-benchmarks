<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Closure;
use JsonException;
use RuntimeException;

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
        string $caseId,
        string $configuration,
        int $repeat,
        string $fingerprint,
        Closure $reuse,
    ): bool {
        if (trim($caseId) === '' || trim($configuration) === '' || $repeat < 1 || trim($fingerprint) === '') {
            throw new RuntimeException('Resume identity must include a case, configuration, repeat, and fingerprint.');
        }

        $run = $this->verifiedRunDirectory($runId);
        $scorecardPath = $run.DIRECTORY_SEPARATOR.RunBundle::SCORECARD_FILE;
        $contents = file_get_contents($scorecardPath);

        if ($contents === false) {
            throw new RuntimeException("Scorecard for run [{$runId->value}] is unavailable.");
        }

        $scorecard = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($scorecard) || ! is_array($scorecard['trials'] ?? null)) {
            throw new RuntimeException('Resume scorecard has an unsupported structure.');
        }

        foreach ($scorecard['trials'] as $trial) {
            if (! is_array($trial)
                || ($trial['case_id'] ?? null) !== $caseId
                || ($trial['configuration'] ?? null) !== $configuration
                || ($trial['repeat'] ?? null) !== $repeat) {
                continue;
            }

            $trial = $this->record($trial);

            if (($trial['fingerprint'] ?? null) !== $fingerprint) {
                throw new RuntimeException('Completed trial fingerprint does not match the requested execution.');
            }

            $replay = $this->replayTrial($run, $trial['trial_id'] ?? null, $fingerprint);
            $reuse($trial, $replay);

            return true;
        }

        return false;
    }

    /** @throws JsonException */
    private function replayTrial(string $run, mixed $trialId, string $fingerprint): mixed
    {
        if (! is_string($trialId)) {
            throw new RuntimeException('Completed trial is missing its stable identity.');
        }

        $contents = file_get_contents($run.DIRECTORY_SEPARATOR.RunBundle::REPLAY_FILE);

        if ($contents === false) {
            throw new RuntimeException('Completed trial has no private replay payload.');
        }

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $trials = is_array($payload) ? ($payload['trials'] ?? null) : null;

        if (! is_array($trials)) {
            throw new RuntimeException('Completed trial has an invalid private replay payload.');
        }

        foreach ($trials as $replay) {
            if (! is_array($replay) || ($replay['trial_id'] ?? null) !== $trialId) {
                continue;
            }

            if (($replay['fingerprint'] ?? null) !== $fingerprint) {
                throw new RuntimeException('Private replay fingerprint does not match the completed trial.');
            }

            if (! array_key_exists('output', $replay)) {
                throw new RuntimeException('Private replay trial is missing its stored output.');
            }

            return $replay['output'];
        }

        throw new RuntimeException('Completed trial has no matching private replay output.');
    }

    private function verifiedRunDirectory(RunId $runId): string
    {
        $root = realpath($this->paths->runs);
        $run = realpath($this->paths->run($runId));

        if ($root === false || $run === false || ! is_dir($run) || ! str_starts_with($run, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Run [{$runId->value}] does not contain a resumable scorecard.");
        }

        return $run;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function record(array $value): array
    {
        $record = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $record[$key] = $item;
            }
        }

        return $record;
    }
}
