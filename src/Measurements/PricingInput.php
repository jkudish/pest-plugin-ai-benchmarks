<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;

final readonly class PricingInput
{
    public function __construct(
        public ModelIdentityEvidence $model,
        public NormalizedUsage $usage,
    ) {}
}
