<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

/**
 * @template TPricingEvidence
 */
interface PricingAdapter
{
    /**
     * @return TPricingEvidence
     */
    public function price(PricingInput $input): mixed;
}
