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

    private const string REDACTED = '[REDACTED]';

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
                'output' => $this->redact($trial['output']),
            ];
        }

        try {
            json_encode($normalized, JSON_THROW_ON_ERROR);
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
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:api[_-]?key|authorization|password|secret|access[_-]?token)/i', $key) === 1) {
            return self::REDACTED;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Replay outputs must contain finite numeric values.');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Replay outputs must contain only JSON-safe values.');
        }

        $redacted = [];

        foreach ($value as $nestedKey => $nestedValue) {
            $redacted[$nestedKey] = $this->redact($nestedValue, is_string($nestedKey) ? $nestedKey : null);
        }

        return $redacted;
    }
}
