<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use JsonException;

/** @internal */
final readonly class ReplayPayload
{
    public const string SCHEMA_VERSION = '0.1.0';

    /** @var array<int, array{trial_id: string, fingerprint: string, output: mixed}> */
    private array $trials;

    /**
     * @param  array<int, array{trial_id: string, fingerprint: string, output: mixed}>  $trials
     */
    public function __construct(array $trials)
    {
        $normalized = [];

        foreach ($trials as $trial) {
            EvidenceId::from($trial['trial_id'], 'trial');

            if (trim($trial['fingerprint']) === '') {
                throw new InvalidArgumentException('Replay trial fingerprints must not be empty.');
            }

            $normalized[] = [
                'trial_id' => $trial['trial_id'],
                'fingerprint' => $trial['fingerprint'],
                'output' => $this->assertJsonSafe($trial['output']),
            ];
        }

        try {
            json_encode($normalized, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Replay outputs must be JSON-safe.', previous: $exception);
        }

        $this->trials = $normalized;
    }

    /** @return array{schema_version: string, trials: array<int, array{trial_id: string, fingerprint: string, output: mixed}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'trials' => $this->trials,
        ];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        )."\n";
    }

    private function assertJsonSafe(mixed $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Replay outputs must contain finite numeric values.');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Replay outputs must contain only JSON-safe values.');
        }

        $safe = [];

        foreach ($value as $nestedKey => $nestedValue) {
            $safe[$nestedKey] = $this->assertJsonSafe($nestedValue);
        }

        return $safe;
    }
}
