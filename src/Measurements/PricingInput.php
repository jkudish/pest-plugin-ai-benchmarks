<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PriceDefinition;
use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;

final readonly class PricingInput
{
    public function __construct(
        public ModelIdentityEvidence $model,
        public NormalizedUsage $usage,
        public ?Money $providerReportedCost = null,
        public ?PriceDefinition $providerNativePricing = null,
    ) {}
}
