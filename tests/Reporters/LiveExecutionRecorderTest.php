<?php

declare(strict_types=1);

use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\LaravelAi\AgentObservation;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;

beforeEach(function (): void {
    ExecutionRecorder::reset();
});

afterEach(function (): void {
    ExecutionRecorder::reset();
});

it('writes live observations with usage attempts and catalog-derived pricing', function (): void {
    $resolved = [];
    $this->app->instance(CostResolver::class, new class($resolved) implements CostResolver
    {
        public function __construct(private array &$resolved) {}

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->resolved[] = $observation;

            return new CostQuote(
                cost: new Money('0.00123'),
                completeness: CostCompleteness::Complete,
                source: PricingSource::Configured,
            );
        }
    });

    ExecutionRecorder::record(
        benchmark: 'live Laravel AI evidence',
        caseId: 'receipt-1',
        configurationName: 'candidate',
        configuration: Configuration::model('openrouter', 'router/requested'),
        identity: new ModelIdentityEvidence('openrouter', 'router/requested', 'openrouter', 'router/requested'),
        latencyMs: 45.5,
        passed: true,
        output: ['merchant' => 'Acme'],
        context: null,
        targetIdentity: [
            'file' => 'tests/Evals/Receipt.php',
            'start_line' => 10,
            'end_line' => 20,
            'source_sha256' => str_repeat('a', 64),
        ],
        observations: [
            new AgentObservation(
                requestedProvider: 'openrouter',
                requestedModel: 'router/requested',
                effectiveProvider: null,
                effectiveModel: null,
                usage: new NormalizedUsage,
                latencyMs: 10.25,
                succeeded: false,
            ),
            new AgentObservation(
                requestedProvider: 'openrouter',
                requestedModel: 'router/fallback',
                effectiveProvider: 'google',
                effectiveModel: 'gemini-effective',
                usage: new NormalizedUsage(inputTokens: 120, outputTokens: 30),
                latencyMs: 35.25,
                succeeded: true,
            ),
        ],
    );

    $this->app->instance(CostResolver::class, new class implements CostResolver
    {
        public function resolve(PricingObservation $observation): CostQuote
        {
            throw new RuntimeException('Pricing must be captured before Laravel teardown.');
        }
    });

    $scorecards = ExecutionRecorder::flush();
    $measurements = $scorecards[0]->toArray()['trials'][0]['results'][0]['measurements'];

    expect($measurements)->toHaveCount(2)
        ->and($measurements[0]['mode'])->toBe('live')
        ->and($measurements[0]['retries'])->toBe(0)
        ->and($measurements[0]['effective_model'])->toBeNull()
        ->and($measurements[0]['pricing']['completeness'])->toBe('unavailable')
        ->and($measurements[1]['requested_model'])->toBe([
            'provider' => 'openrouter',
            'model' => 'router/fallback',
        ])
        ->and($measurements[1]['effective_model'])->toBe([
            'provider' => 'google',
            'model' => 'gemini-effective',
        ])
        ->and($measurements[1]['usage'])->toMatchArray([
            'input_tokens' => 120,
            'output_tokens' => 30,
        ])
        ->and($measurements[1]['retries'])->toBe(1)
        ->and($measurements[1]['pricing']['completeness'])->toBe('complete')
        ->and($measurements[1]['pricing']['snapshot']['cost'])->toBe([
            'amount' => '0.00123',
            'currency' => 'USD',
        ])
        ->and($resolved)->toHaveCount(1)
        ->and($resolved[0]->identity->toArray())->toBe([
            'provider' => 'google',
            'model' => 'gemini-effective',
        ]);
});

it('keeps fake-gateway observations simulated and unpriced', function (): void {
    $this->app->instance(CostResolver::class, new class implements CostResolver
    {
        public function resolve(PricingObservation $observation): CostQuote
        {
            throw new RuntimeException('Simulated evidence must not be priced.');
        }
    });

    ExecutionRecorder::record(
        benchmark: 'simulated Laravel AI evidence',
        caseId: 'receipt-fake',
        configurationName: 'candidate',
        configuration: Configuration::model('openrouter', 'router/requested'),
        identity: new ModelIdentityEvidence('openrouter', 'router/requested', 'openrouter', 'router/requested'),
        latencyMs: 12.5,
        passed: true,
        output: ['merchant' => 'Acme'],
        context: null,
        targetIdentity: [
            'file' => 'tests/Evals/Receipt.php',
            'start_line' => 10,
            'end_line' => 20,
            'source_sha256' => str_repeat('b', 64),
        ],
        observations: [
            new AgentObservation(
                requestedProvider: 'openrouter',
                requestedModel: 'router/requested',
                effectiveProvider: 'openrouter',
                effectiveModel: 'router/requested',
                usage: new NormalizedUsage(inputTokens: 10, outputTokens: 2),
                latencyMs: 12.5,
                succeeded: true,
                mode: ExecutionMode::Simulated,
            ),
        ],
    );

    $measurement = ExecutionRecorder::flush()[0]->toArray()['trials'][0]['results'][0]['measurements'][0];

    expect($measurement['mode'])->toBe('simulated')
        ->and($measurement['pricing']['completeness'])->toBe('unavailable');
});

it('binds trial fingerprints to effective runtime identity', function (): void {
    foreach (['effective-a', 'effective-b'] as $effectiveModel) {
        ExecutionRecorder::record(
            benchmark: 'runtime identity fingerprint',
            caseId: 'receipt-identity',
            configurationName: 'candidate',
            configuration: Configuration::model('openrouter', 'router/requested'),
            identity: new ModelIdentityEvidence('openrouter', 'router/requested', 'openrouter', 'router/requested'),
            latencyMs: 10,
            passed: true,
            output: ['merchant' => 'Acme'],
            context: null,
            targetIdentity: [
                'file' => 'tests/Evals/Receipt.php',
                'start_line' => 10,
                'end_line' => 20,
                'source_sha256' => str_repeat('c', 64),
            ],
            observations: [
                new AgentObservation(
                    requestedProvider: 'openrouter',
                    requestedModel: 'router/requested',
                    effectiveProvider: 'provider',
                    effectiveModel: $effectiveModel,
                    usage: new NormalizedUsage(inputTokens: 10, outputTokens: 2),
                    latencyMs: 10,
                    succeeded: true,
                ),
            ],
        );
    }

    $trials = ExecutionRecorder::flush()[0]->toArray()['trials'];

    expect($trials)->toHaveCount(2)
        ->and($trials[0]['fingerprint'])->not->toBe($trials[1]['fingerprint']);
});
