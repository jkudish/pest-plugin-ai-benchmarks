<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use InvalidArgumentException;

final readonly class NormalizedUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $reasoningTokens = 0,
    ) {
        foreach (get_object_vars($this) as $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException('Normalized token usage may not be negative.');
            }
        }
    }
}
