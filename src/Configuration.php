<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use InvalidArgumentException;

final readonly class Configuration
{
    /**
     * @param  array<string, bool|float|int|string|null|array<mixed>>  $options
     * @param  array<string, bool|float|int|string|null|array<mixed>>  $settings
     */
    private function __construct(
        public ?string $provider,
        public ?string $model,
        public array $options,
        public array $settings,
    ) {}

    public static function production(): self
    {
        return new self(null, null, [], []);
    }

    /**
     * @param  array<string, bool|float|int|string|null|array<mixed>>  $options
     */
    public static function model(string $provider, string $model, array $options = []): self
    {
        self::assertNonEmptyString($provider, 'Model provider');
        self::assertNonEmptyString($model, 'Model name');
        self::assertJsonObject($options, 'Model options');

        return new self($provider, $model, $options, []);
    }

    /**
     * @param  array<string, bool|float|int|string|null|array<mixed>>  $settings
     */
    public static function settings(array $settings): self
    {
        self::assertJsonObject($settings, 'Application settings');

        return new self(null, null, [], $settings);
    }

    /**
     * @param  array<string, bool|float|int|string|null|array<mixed>>  $settings
     */
    public function withSettings(array $settings): self
    {
        self::assertJsonObject($settings, 'Application settings');

        return new self(
            $this->provider,
            $this->model,
            $this->options,
            array_replace($this->settings, $settings),
        );
    }

    private static function assertNonEmptyString(string $value, string $label): void
    {
        if ($value === '' || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string without surrounding whitespace or control characters.', $label));
        }
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function assertJsonObject(array $values, string $label): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException(sprintf('%s must use non-empty string keys.', $label));
            }

            self::assertJsonValue($value, sprintf('%s [%s]', $label, $key));
        }
    }

    private static function assertJsonValue(mixed $value, string $label): void
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException(sprintf('%s must be JSON-safe.', $label));
        }

        if (is_scalar($value) || $value === null) {
            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('%s must be JSON-safe.', $label));
        }

        foreach ($value as $nestedValue) {
            self::assertJsonValue($nestedValue, $label);
        }
    }
}
