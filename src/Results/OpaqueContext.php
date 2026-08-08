<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Results;

use InvalidArgumentException;
use JsonException;

final readonly class OpaqueContext
{
    public const int MAX_BYTES = 16_384;

    public const int MAX_KEYS = 100;

    public const int MAX_DEPTH = 5;

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<mixed> $values */
    public function __construct(array $values = [])
    {
        if ($values !== [] && array_is_list($values)) {
            throw new InvalidArgumentException('Opaque context must be a JSON object.');
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Opaque context root keys must be strings.');
            }

            $normalized[$key] = $value;
        }

        $keyCount = 0;
        $this->validate($normalized, 1, $keyCount);

        if ($keyCount > self::MAX_KEYS) {
            throw new InvalidArgumentException('Opaque context contains too many keys.');
        }

        try {
            $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Opaque context must be JSON-safe.', previous: $exception);
        }

        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Opaque context exceeds the maximum encoded size.');
        }

        $this->values = $normalized;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    private function validate(mixed $value, int $depth, int &$keyCount): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Opaque context exceeds the maximum nesting depth.');
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Opaque context must contain finite numeric values.');
        }

        if (is_scalar($value) || $value === null) {
            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Opaque context must contain only JSON-safe values.');
        }

        foreach ($value as $item) {
            $keyCount++;
            $this->validate($item, $depth + 1, $keyCount);
        }
    }
}
