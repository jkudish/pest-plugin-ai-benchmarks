<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Measurements;

use InvalidArgumentException;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

/** @implements PricingAdapter<CostQuote> */
final readonly class LaravelAiPricingAdapter implements PricingAdapter
{
    public function __construct(private CostResolver $resolver) {}

    public function price(PricingInput $input): CostQuote
    {
        $requestedIdentity = self::identity(
            $input->model->requestedProvider,
            $input->model->requestedModel,
            'requested',
        );
        $effectiveIdentity = self::identity(
            $input->model->effectiveProvider,
            $input->model->effectiveModel,
            'effective',
        );

        return $this->resolver->resolve(new PricingObservation(
            identity: $effectiveIdentity ?? $requestedIdentity
                ?? throw new InvalidArgumentException('Pricing requires a requested or effective provider and model.'),
            usage: new Usage($input->usage->toArray()),
            providerReportedCost: $input->providerReportedCost,
            providerNativePricing: $input->providerNativePricing,
            requestedIdentity: $requestedIdentity,
        ));
    }

    private static function identity(?string $provider, ?string $model, string $label): ?ModelIdentity
    {
        if (($provider === null) !== ($model === null)) {
            throw new InvalidArgumentException("Pricing {$label} provider and model must be supplied together.");
        }

        return $provider !== null && $model !== null ? new ModelIdentity($provider, $model) : null;
    }
}
