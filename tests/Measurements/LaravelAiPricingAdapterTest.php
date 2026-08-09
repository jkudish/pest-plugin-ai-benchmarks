<?php

declare(strict_types=1);

use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PriceDefinition;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\LaravelAiPricing\ValueObjects\Rate;
use Jkudish\PestAiBenchmarks\Measurements\LaravelAiPricingAdapter;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Measurements\PricingInput;
use Jkudish\PestAiBenchmarks\ModelIdentityEvidence;

it('delegates effective model and normalized usage to the shared pricing resolver', function (): void {
    $observation = null;
    $expected = CostQuote::unavailable();
    $resolver = new class($observation, $expected) implements CostResolver
    {
        public function __construct(
            private mixed &$observation,
            private readonly CostQuote $quote,
        ) {}

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->observation = $observation;

            return $this->quote;
        }
    };

    $quote = (new LaravelAiPricingAdapter($resolver))->price(new PricingInput(
        model: new ModelIdentityEvidence(
            requestedProvider: 'openrouter',
            requestedModel: 'router/automatic',
            effectiveProvider: 'google',
            effectiveModel: 'gemini-flash',
        ),
        usage: new NormalizedUsage(
            inputTokens: 120,
            outputTokens: 30,
            cachedInputTokens: 20,
            reasoningTokens: 10,
        ),
    ));

    expect($quote)->toBe($expected)
        ->and($observation)->toBeInstanceOf(PricingObservation::class)
        ->and($observation->identity->toArray())->toBe([
            'provider' => 'google',
            'model' => 'gemini-flash',
        ])
        ->and($observation->requestedIdentity?->toArray())->toBe([
            'provider' => 'openrouter',
            'model' => 'router/automatic',
        ])
        ->and($observation->usage->toArray())->toBe([
            'input_tokens' => '120',
            'output_tokens' => '30',
            'cached_input_tokens' => '20',
            'reasoning_tokens' => '10',
        ]);
});

it('preserves arbitrary usage and authoritative provider pricing without understating cost', function (): void {
    $observation = null;
    $resolver = new class($observation) implements CostResolver
    {
        public function __construct(private mixed &$observation) {}

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->observation = $observation;

            return CostQuote::unavailable();
        }
    };
    $reportedCost = new Money('0.012345', 'USD');
    $nativePricing = new PriceDefinition(
        identity: new ModelIdentity('google', 'gemini-flash'),
        rates: [
            'images' => new Rate('images', '0.01'),
            'audio_seconds' => new Rate('audio_seconds', '0.001'),
        ],
        source: PricingSource::ProviderNative,
    );

    (new LaravelAiPricingAdapter($resolver))->price(new PricingInput(
        model: new ModelIdentityEvidence(
            requestedProvider: 'openrouter',
            requestedModel: 'router/automatic',
            effectiveProvider: 'google',
            effectiveModel: 'gemini-flash',
        ),
        usage: new NormalizedUsage(
            inputTokens: 120,
            outputTokens: 30,
            additionalUnits: ['images' => 2, 'audio_seconds' => '1.5'],
        ),
        providerReportedCost: $reportedCost,
        providerNativePricing: $nativePricing,
    ));

    expect($observation)->toBeInstanceOf(PricingObservation::class)
        ->and($observation->usage->toArray())->toBe([
            'input_tokens' => '120',
            'output_tokens' => '30',
            'cached_input_tokens' => '0',
            'reasoning_tokens' => '0',
            'images' => '2',
            'audio_seconds' => '1.5',
        ])
        ->and($observation->providerReportedCost)->toBe($reportedCost)
        ->and($observation->providerNativePricing)->toBe($nativePricing);
});

it('falls back to the requested identity only when no effective identity is available', function (): void {
    $observation = null;
    $resolver = new class($observation) implements CostResolver
    {
        public function __construct(private mixed &$observation) {}

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->observation = $observation;

            return CostQuote::unavailable();
        }
    };

    (new LaravelAiPricingAdapter($resolver))->price(new PricingInput(
        model: new ModelIdentityEvidence('openrouter', 'router/model', null, null),
        usage: new NormalizedUsage(inputTokens: 1),
    ));

    expect($observation->identity->toArray())->toBe([
        'provider' => 'openrouter',
        'model' => 'router/model',
    ]);
});

it('rejects incomplete pricing identities', function (): void {
    $resolver = new class implements CostResolver
    {
        public function resolve(PricingObservation $observation): CostQuote
        {
            return CostQuote::unavailable();
        }
    };

    expect(fn () => (new LaravelAiPricingAdapter($resolver))->price(new PricingInput(
        model: new ModelIdentityEvidence('openrouter', null, null, null),
        usage: new NormalizedUsage(inputTokens: 1),
    )))->toThrow(InvalidArgumentException::class, 'requested provider and model');
});
