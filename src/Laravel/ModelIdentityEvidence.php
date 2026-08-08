<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Laravel;

final readonly class ModelIdentityEvidence
{
    public function __construct(
        public ?string $requestedProvider,
        public ?string $requestedModel,
        public ?string $effectiveProvider,
        public ?string $effectiveModel,
    ) {}
}
