<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Results;

use InvalidArgumentException;

final readonly class EvidenceId
{
    private const array PREFIXES = ['sc', 'exec', 'trial', 'res'];

    private function __construct(public string $value) {}

    public static function generate(string $prefix): self
    {
        self::validatePrefix($prefix);

        return new self($prefix.'_'.bin2hex(random_bytes(16)));
    }

    public static function from(string $value, string $prefix): self
    {
        self::validatePrefix($prefix);

        if (! preg_match('/^'.preg_quote($prefix, '/').'_[A-Za-z0-9_-]{8,128}$/', $value)) {
            throw new InvalidArgumentException("The evidence ID must be a valid [{$prefix}] identifier.");
        }

        return new self($value);
    }

    private static function validatePrefix(string $prefix): void
    {
        if (! in_array($prefix, self::PREFIXES, true)) {
            throw new InvalidArgumentException('Unsupported evidence ID prefix.');
        }
    }
}
