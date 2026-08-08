<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

final readonly class LaravelAiPricingAdapter implements PricingAdapter
{
    public function __construct(private CostResolver $resolver) {}

    public function price(PricingInput $input): CostQuote
    {
        $requestedIdentity = null;

        if ($input->model->requestedProvider !== null && $input->model->requestedModel !== null) {
            $requestedIdentity = new ModelIdentity(
                $input->model->requestedProvider,
                $input->model->requestedModel,
            );
        }

        return $this->resolver->resolve(new PricingObservation(
            identity: new ModelIdentity(
                $input->model->effectiveProvider,
                $input->model->effectiveModel,
            ),
            usage: new Usage([
                'input_tokens' => $input->usage->inputTokens,
                'output_tokens' => $input->usage->outputTokens,
                'cached_input_tokens' => $input->usage->cachedInputTokens,
                'reasoning_tokens' => $input->usage->reasoningTokens,
            ]),
            requestedIdentity: $requestedIdentity,
        ));
    }
}
