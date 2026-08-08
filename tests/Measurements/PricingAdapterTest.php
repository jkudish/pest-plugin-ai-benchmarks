<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Measurements\PricingAdapter;
use Jkudish\PestAiBenchmarks\Measurements\PricingInput;

it('passes normalized usage and effective identity through the pricing seam', function (): void {
    $adapter = new class implements PricingAdapter
    {
        /** @return array{provider: string, model: string, tokens: int} */
        public function price(PricingInput $input): array
        {
            return [
                'provider' => $input->model->effectiveProvider,
                'model' => $input->model->effectiveModel,
                'tokens' => $input->usage->inputTokens + $input->usage->outputTokens,
            ];
        }
    };

    $result = $adapter->price(new PricingInput(
        model: new ModelIdentityEvidence(
            requestedProvider: 'openrouter',
            requestedModel: 'router/model',
            effectiveProvider: 'google',
            effectiveModel: 'gemini-flash',
        ),
        usage: new NormalizedUsage(inputTokens: 120, outputTokens: 30),
    ));

    expect($result)->toBe([
        'provider' => 'google',
        'model' => 'gemini-flash',
        'tokens' => 150,
    ]);
});

it('rejects negative normalized usage', function (): void {
    expect(fn (): NormalizedUsage => new NormalizedUsage(inputTokens: -1))
        ->toThrow(InvalidArgumentException::class, 'Normalized token usage may not be negative.');
});
