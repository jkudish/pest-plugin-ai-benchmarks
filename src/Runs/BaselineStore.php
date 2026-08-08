<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use JsonException;
use RuntimeException;

/** @internal */
final readonly class BaselineStore
{
    public function __construct(private RunPaths $paths) {}

    public function save(string $name, Scorecard $scorecard): void
    {
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

        if (file_put_contents($temporary, $scorecard->toJson(), LOCK_EX) === false
            || ! chmod($temporary, 0644)
            || ! rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("Unable to save baseline [{$name}] atomically.");
        }
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
}
