<?php

declare(strict_types=1);

use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\Measurements\LaravelAiPricingAdapter;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Measurements\PricingInput;

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
