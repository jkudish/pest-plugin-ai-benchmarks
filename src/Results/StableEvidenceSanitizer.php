<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Results;

use InvalidArgumentException;
use JsonException;

/** @internal */
final class StableEvidenceSanitizer
{
    public const string REDACTED = '[REDACTED]';

    private const string TRUNCATED = '[TRUNCATED]';

    private const string SENSITIVE_KEY_PATTERN = '/(?:api[_-]?key|authorization|password|secret|credential|private[_-]?key|(?:^|[_-])(?:access|refresh|id)?[_-]?token$)/i';

    private const string BEARER_TOKEN_PATTERN = '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i';

    private const string OPENAI_TOKEN_PATTERN = '/\bsk-[A-Za-z0-9_-]{12,}\b/';

    public static function text(?string $value, int $maxBytes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Stable evidence text bounds must be positive.');
        }

        $sanitized = mb_scrub($value, 'UTF-8');
        $sanitized = preg_replace(self::BEARER_TOKEN_PATTERN, 'Bearer '.self::REDACTED, $sanitized) ?? $sanitized;
        $sanitized = preg_replace(self::OPENAI_TOKEN_PATTERN, self::REDACTED, $sanitized) ?? $sanitized;

        if (strlen($sanitized) <= $maxBytes) {
            return $sanitized;
        }

        if ($maxBytes <= strlen(self::TRUNCATED)) {
            return mb_strcut(self::TRUNCATED, 0, $maxBytes, 'UTF-8');
        }

        return mb_strcut($sanitized, 0, $maxBytes - strlen(self::TRUNCATED), 'UTF-8').self::TRUNCATED;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    public static function object(
        array $value,
        int $maxDepth,
        int $maxEntries,
        int $maxBytes,
        int $maxStringBytes,
    ): array {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException('Stable evidence context must be a JSON object.');
        }

        $entries = 0;
        $sanitized = self::sanitizeArray(
            value: $value,
            depth: 1,
            entries: $entries,
            maxDepth: $maxDepth,
            maxEntries: $maxEntries,
            maxStringBytes: $maxStringBytes,
            root: true,
        );
        $object = [];

        foreach ($sanitized as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Stable evidence context root keys must be non-empty strings.');
            }

            $object[$key] = $item;
        }

        try {
            $json = json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stable evidence context must be JSON-safe.', previous: $exception);
        }

        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException('Stable evidence context exceeds the maximum encoded size.');
        }

        return $object;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sanitizeArray(
        array $value,
        int $depth,
        int &$entries,
        int $maxDepth,
        int $maxEntries,
        int $maxStringBytes,
        bool $root = false,
    ): array {
        if ($depth > $maxDepth) {
            throw new InvalidArgumentException('Stable evidence context exceeds the maximum nesting depth.');
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if ($root && (! is_string($key) || $key === '')) {
                throw new InvalidArgumentException('Stable evidence context root keys must be non-empty strings.');
            }

            $entries++;

            if ($entries > $maxEntries) {
                throw new InvalidArgumentException('Stable evidence context contains too many keys or list entries.');
            }

            $sanitized[$key] = is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1
                ? self::REDACTED
                : self::sanitizeValue($item, $depth, $entries, $maxDepth, $maxEntries, $maxStringBytes);
        }

        return $sanitized;
    }

    private static function sanitizeValue(
        mixed $value,
        int $depth,
        int &$entries,
        int $maxDepth,
        int $maxEntries,
        int $maxStringBytes,
    ): mixed {
        if ($depth + 1 > $maxDepth) {
            throw new InvalidArgumentException('Stable evidence context exceeds the maximum nesting depth.');
        }

        if (is_string($value)) {
            return self::text($value, $maxStringBytes);
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Stable evidence context must contain finite numeric values.');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Stable evidence context must contain only JSON-safe values.');
        }

        return self::sanitizeArray($value, $depth + 1, $entries, $maxDepth, $maxEntries, $maxStringBytes);
    }
}
