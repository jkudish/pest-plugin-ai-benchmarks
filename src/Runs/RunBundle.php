<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use RuntimeException;
use Throwable;

/** @internal */
final readonly class RunBundle
{
    public const string SCORECARD_FILE = 'scorecard.json';

    public const string REPLAY_FILE = 'replay.private.json';

    public function __construct(
        private RunPaths $paths,
        private RunId $runId,
    ) {}

    public function write(Scorecard $scorecard, ReplayPayload $replay): void
    {
        self::ensureDirectory($this->paths->runs, 0700);

        $destination = $this->paths->run($this->runId);

        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException("Run [{$this->runId->value}] already exists.");
        }

        $staging = $this->paths->runs.DIRECTORY_SEPARATOR.'.'.$this->runId->value.'.'.bin2hex(random_bytes(8)).'.tmp';

        if (! mkdir($staging, 0700) && ! is_dir($staging)) {
            throw new RuntimeException('Unable to create the run staging directory.');
        }

        try {
            self::writeFile($staging.DIRECTORY_SEPARATOR.self::SCORECARD_FILE, $scorecard->toJson(), 0644);
            self::writeFile($staging.DIRECTORY_SEPARATOR.self::REPLAY_FILE, $replay->toJson(), 0600);

            if (! rename($staging, $destination)) {
                throw new RuntimeException('Unable to publish the run bundle atomically.');
            }
        } catch (Throwable $exception) {
            self::removeStagingDirectory($staging);

            throw $exception;
        }
    }

    private static function ensureDirectory(string $directory, int $permissions): void
    {
        if (is_link($directory)) {
            throw new RuntimeException('Run storage may not be a symbolic link.');
        }

        if (! is_dir($directory) && ! mkdir($directory, $permissions, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }
    }

    private static function writeFile(string $path, string $contents, int $permissions): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write [{$path}].");
        }

        if (! chmod($path, $permissions)) {
            throw new RuntimeException("Unable to secure [{$path}].");
        }
    }

    private static function removeStagingDirectory(string $staging): void
    {
        foreach ([self::SCORECARD_FILE, self::REPLAY_FILE] as $filename) {
            $path = $staging.DIRECTORY_SEPARATOR.$filename;

            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($staging)) {
            rmdir($staging);
        }
    }
}
