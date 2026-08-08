<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Results;

use InvalidArgumentException;

final readonly class OpaqueContext
{
    public const int MAX_BYTES = 16_384;

    public const int MAX_KEYS = 100;

    public const int MAX_DEPTH = 5;

    public const int MAX_STRING_BYTES = 4_096;

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<mixed> $values */
    public function __construct(array $values = [])
    {
        if ($values !== [] && array_is_list($values)) {
            throw new InvalidArgumentException('Opaque context must be a JSON object.');
        }

        try {
            $this->values = StableEvidenceSanitizer::object(
                value: $this->record($values),
                maxDepth: self::MAX_DEPTH,
                maxEntries: self::MAX_KEYS,
                maxBytes: self::MAX_BYTES,
                maxStringBytes: self::MAX_STRING_BYTES,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(str_replace('Stable evidence context', 'Opaque context', $exception->getMessage()), previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function record(array $values): array
    {
        $record = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Opaque context root keys must be strings.');
            }

            $record[$key] = $value;
        }

        return $record;
    }
}
