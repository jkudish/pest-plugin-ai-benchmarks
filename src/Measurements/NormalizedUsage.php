<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use InvalidArgumentException;

final readonly class NormalizedUsage
{
    /** @var array<string, string|int> */
    private array $units;

    /**
     * @param  array<string, string|int>  $additionalUnits
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $reasoningTokens = 0,
        array $additionalUnits = [],
    ) {
        foreach ([$inputTokens, $outputTokens, $cachedInputTokens, $reasoningTokens] as $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException('Normalized token usage may not be negative.');
            }
        }

        $units = [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ];

        foreach ($additionalUnits as $unit => $quantity) {
            if (trim($unit) === '' || array_key_exists($unit, $units)) {
                throw new InvalidArgumentException('Additional usage units must have unique, non-empty names.');
            }

            if (! self::isNonNegativeDecimal($quantity)) {
                throw new InvalidArgumentException('Additional usage quantities must be non-negative integers or decimal strings.');
            }

            $units[$unit] = $quantity;
        }

        $this->units = $units;
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return $this->units;
    }

    private static function isNonNegativeDecimal(string|int $quantity): bool
    {
        if (is_int($quantity)) {
            return $quantity >= 0;
        }

        return preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $quantity) === 1;
    }
}
