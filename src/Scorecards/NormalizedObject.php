<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** @internal */
final readonly class NormalizedObject
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<mixed> $values */
    public function __construct(array $values)
    {
        if ($values !== [] && array_is_list($values)) {
            throw new InvalidArgumentException('Normalized evidence must be a JSON object.');
        }

        $normalized = $this->normalizeObject($values);

        try {
            json_encode($normalized, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Normalized evidence must contain only JSON-safe values.', previous: $exception);
        }

        $this->values = $normalized;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @return array<string, mixed>|stdClass */
    public function toJsonValue(): array|stdClass
    {
        return $this->values === [] ? new stdClass : $this->values;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeObject(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $item) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Normalized evidence object keys must be non-empty strings.');
            }

            $normalized[$key] = $this->normalizeValue($item);
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Normalized evidence must contain finite numeric values.');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Normalized evidence must contain only JSON-safe values.');
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        return $this->normalizeObject($value);
    }
}
