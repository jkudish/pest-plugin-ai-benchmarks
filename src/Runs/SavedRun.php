<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use JsonException;
use RuntimeException;

/** @internal */
final readonly class SavedRun
{
    public function __construct(
        private RunPaths $paths,
        private RunId $runId,
    ) {}

    /**
     * @return array{trial: array<string, mixed>, output: mixed}|null
     *
     * @throws JsonException
     */
    public function completedTrial(
        string $benchmark,
        string $caseId,
        string $configuration,
        int $repeat,
        string $fingerprint,
    ): ?array {
        if (trim($benchmark) === '' || trim($caseId) === '' || trim($configuration) === '' || $repeat < 1 || trim($fingerprint) === '') {
            throw new RuntimeException('Saved trial identity must include a benchmark, case, configuration, repeat, and fingerprint.');
        }

        $scorecard = $this->scorecard();

        if (($scorecard['benchmark'] ?? null) !== $benchmark) {
            throw new RuntimeException("Run [{$this->runId->value}] belongs to a different benchmark.");
        }

        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials)) {
            throw new RuntimeException('Saved scorecard has an unsupported trial structure.');
        }

        $matched = null;

        foreach ($trials as $trial) {
            if (! is_array($trial)
                || ($trial['case_id'] ?? null) !== $caseId
                || ($trial['configuration'] ?? null) !== $configuration
                || ($trial['repeat'] ?? null) !== $repeat) {
                continue;
            }

            if ($matched !== null) {
                throw new RuntimeException('Saved run contains duplicate trial identities.');
            }

            $matched = $this->record($trial);
        }

        if ($matched === null) {
            return null;
        }

        if (($matched['fingerprint'] ?? null) !== $fingerprint) {
            throw new RuntimeException('Completed trial fingerprint does not match the requested execution.');
        }

        return [
            'trial' => $matched,
            'output' => $this->replayOutput($matched['trial_id'] ?? null, $fingerprint),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function scorecard(): array
    {
        $contents = file_get_contents($this->directory().DIRECTORY_SEPARATOR.RunBundle::SCORECARD_FILE);

        if ($contents === false) {
            throw new RuntimeException("Scorecard for run [{$this->runId->value}] is unavailable.");
        }

        $scorecard = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($scorecard)
            || ! is_string($scorecard['benchmark'] ?? null)
            || ! is_string($scorecard['schema_version'] ?? null)
            || ! is_array($scorecard['trials'] ?? null)) {
            throw new RuntimeException('Saved scorecard has an unsupported structure.');
        }

        return $this->record($scorecard);
    }

    /** @throws JsonException */
    private function replayOutput(mixed $trialId, string $fingerprint): mixed
    {
        if (! is_string($trialId)) {
            throw new RuntimeException('Completed trial is missing its stable identity.');
        }

        $contents = file_get_contents($this->directory().DIRECTORY_SEPARATOR.RunBundle::REPLAY_FILE);

        if ($contents === false) {
            throw new RuntimeException('Completed trial has no private replay payload.');
        }

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $trials = is_array($payload) && ($payload['schema_version'] ?? null) === ReplayPayload::SCHEMA_VERSION
            ? ($payload['trials'] ?? null)
            : null;

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

    private function directory(): string
    {
        $root = realpath($this->paths->runs);
        $run = realpath($this->paths->run($this->runId));

        if ($root === false || $run === false || ! is_dir($run) || ! str_starts_with($run, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Run [{$this->runId->value}] does not exist within run storage.");
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
