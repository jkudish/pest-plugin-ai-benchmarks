<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use JsonException;
use RuntimeException;
use stdClass;

/** @internal */
final readonly class BaselineStore
{
    public function __construct(private RunPaths $paths) {}

    public function save(string $name, Scorecard $scorecard): void
    {
        $this->write($name, $scorecard->toArray());
    }

    public function promote(RunId $runId, string $name): void
    {
        $stableScorecard = (new SavedRun($this->paths, $runId))->scorecard();
        $this->write($name, $stableScorecard);
    }

    /** @param array<string, mixed> $stableScorecard */
    private function write(string $name, array $stableScorecard): void
    {
        StableScorecardValidator::assert($stableScorecard);
        $baseline = $this->baselineEvidence($stableScorecard);
        $this->assertPromotable($baseline);
        $json = json_encode(
            $baseline,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        $path = $this->paths->baseline($name);

        if (is_link($this->paths->baselines) || is_link($path)) {
            throw new RuntimeException('Baseline storage may not use symbolic links.');
        }

        if (! is_dir($this->paths->baselines)
            && ! mkdir($this->paths->baselines, 0755, true)
            && ! is_dir($this->paths->baselines)) {
            throw new RuntimeException('Unable to create baseline storage.');
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        if (file_put_contents($temporary, $json, LOCK_EX) === false
            || ! chmod($temporary, 0644)
            || ! rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("Unable to save baseline [{$name}] atomically.");
        }
    }

    /**
     * @param  array<string, mixed>  $scorecard
     * @return array<string, mixed>
     */
    private function baselineEvidence(array $scorecard): array
    {
        unset($scorecard['context']);
        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials)) {
            return $scorecard;
        }

        foreach ($trials as $trialIndex => $trial) {
            if (! is_array($trial) || ! is_array($trial['results'] ?? null)) {
                continue;
            }

            $results = $trial['results'];

            foreach ($results as $resultIndex => $result) {
                if (is_array($result)) {
                    $result['reasoning'] = null;
                    $measurements = $result['measurements'] ?? null;

                    if (is_array($measurements)) {
                        foreach ($measurements as $measurementIndex => $measurement) {
                            if (! is_array($measurement)) {
                                continue;
                            }

                            if (($measurement['usage'] ?? null) === []) {
                                $measurement['usage'] = new stdClass;
                            }

                            $pricing = $measurement['pricing'] ?? null;

                            if (is_array($pricing) && ($pricing['snapshot'] ?? null) === []) {
                                $pricing['snapshot'] = new stdClass;
                                $measurement['pricing'] = $pricing;
                            }

                            $measurements[$measurementIndex] = $measurement;
                        }

                        $result['measurements'] = $measurements;
                    }

                    $results[$resultIndex] = $result;
                }
            }

            $trial['results'] = $results;
            $trials[$trialIndex] = $trial;
        }

        $scorecard['trials'] = $trials;

        return $scorecard;
    }

    /** @throws JsonException */
    public function load(string $name): Baseline
    {
        $path = $this->paths->baseline($name);
        $root = realpath($this->paths->baselines);
        $resolved = realpath($path);

        if ($root === false || $resolved === false || ! is_file($resolved) || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Baseline [{$name}] does not exist within baseline storage.");
        }

        $contents = file_get_contents($resolved);

        if ($contents === false) {
            throw new RuntimeException("Baseline [{$name}] could not be read.");
        }

        $scorecard = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($scorecard)
            || ! is_string($scorecard['schema_version'] ?? null)
            || ! is_array($scorecard['trials'] ?? null)) {
            throw new RuntimeException("Baseline [{$name}] is not a stable scorecard.");
        }

        return new Baseline($this->record($scorecard));
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

    /** @param array<string, mixed> $scorecard */
    public function assertPromotable(array $scorecard): void
    {
        StableScorecardValidator::assert($scorecard);
        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials)) {
            throw new RuntimeException('Only stable scorecards may be promoted as baselines.');
        }

        foreach ($trials as $trial) {
            if (! is_array($trial) || ! is_array($trial['results'] ?? null)) {
                throw new RuntimeException('Only stable scorecards may be promoted as baselines.');
            }

            foreach ($trial['results'] as $result) {
                if (! is_array($result) || ! is_array($result['measurements'] ?? null)) {
                    throw new RuntimeException('Only stable scorecards may be promoted as baselines.');
                }

                foreach ($result['measurements'] as $measurement) {
                    if (! is_array($measurement) || ! is_string($measurement['mode'] ?? null)) {
                        throw new RuntimeException('Only stable scorecards may be promoted as baselines.');
                    }

                    if ($measurement['mode'] === 'simulated') {
                        throw new RuntimeException('Simulated evidence cannot be promoted as a baseline.');
                    }

                    if ($measurement['mode'] !== 'live') {
                        throw new RuntimeException('Only directly observed live evidence can be promoted as a baseline.');
                    }
                }
            }
        }
    }
}
