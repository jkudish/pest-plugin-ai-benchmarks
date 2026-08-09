<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\StableEvidenceSanitizer;
use stdClass;

/** @internal */
final readonly class NormalizedObject
{
    public const int MAX_BYTES = 65_536;

    public const int MAX_DEPTH = 8;

    public const int MAX_ENTRIES = 256;

    public const int MAX_STRING_BYTES = 8_192;

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<mixed> $values */
    public function __construct(array $values)
    {
        if ($values !== [] && array_is_list($values)) {
            throw new InvalidArgumentException('Normalized evidence must be a JSON object.');
        }

        try {
            $normalized = StableEvidenceSanitizer::object(
                value: $values,
                maxDepth: self::MAX_DEPTH,
                maxEntries: self::MAX_ENTRIES,
                maxBytes: self::MAX_BYTES,
                maxStringBytes: self::MAX_STRING_BYTES,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(str_replace('Stable evidence context', 'Normalized evidence', $exception->getMessage()), previous: $exception);
        }

        $this->values = $this->normalizeObject($normalized);
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
