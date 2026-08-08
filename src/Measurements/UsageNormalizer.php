<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

/**
 * @template TResponse
 */
interface UsageNormalizer
{
    /**
     * @param  TResponse  $response
     */
    public function normalize(mixed $response): NormalizedUsage;
}
