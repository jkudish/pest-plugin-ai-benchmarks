<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Response as HttpResponse;
use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

afterEach(function (): void {
    RuntimeObservationCollector::reset();
});

it('captures truthful Laravel AI identity usage and latency during an active benchmark', function (): void {
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->once()->andReturn('openrouter');
    $provider->shouldReceive('driver')->once()->andReturn('openrouter');
    $prompt = new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: 'Extract this receipt.',
        attachments: [],
        provider: $provider,
        model: 'router/requested-model',
    );
    $response = new AgentResponse(
        invocationId: 'invocation-1',
        text: '{"merchant":"Acme"}',
        usage: new Usage(
            promptTokens: 120,
            completionTokens: 30,
            cacheWriteInputTokens: 15,
            cacheReadInputTokens: 20,
            reasoningTokens: 10,
        ),
        meta: new Meta(provider: 'google', model: 'gemini-effective'),
    );

    RuntimeObservationCollector::begin();

    $actual = (new BenchmarkAgentMiddleware)->handle($prompt, function (AgentPrompt $handled) use ($prompt, $response): AgentResponse {
        expect($handled)->toBe($prompt);

        return $response;
    });

    $observations = RuntimeObservationCollector::finish();

    expect($actual)->toBe($response)
        ->and($observations)->toHaveCount(1)
        ->and($observations[0]->requestedProvider)->toBe('openrouter')
        ->and($observations[0]->requestedModel)->toBe('router/requested-model')
        ->and($observations[0]->effectiveProvider)->toBe('google')
        ->and($observations[0]->effectiveModel)->toBe('gemini-effective')
        ->and($observations[0]->usage->toArray())->toBe([
            'input_tokens' => 85,
            'output_tokens' => 30,
            'cached_input_tokens' => 20,
            'reasoning_tokens' => 10,
            'cache_write_input_tokens' => 15,
        ])
        ->and($observations[0]->latencyMs)->toBeGreaterThanOrEqual(0.0)
        ->and($observations[0]->succeeded)->toBeTrue();
});

it('records failed attempts and rethrows the original exception', function (): void {
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->once()->andReturn('openrouter');
    $prompt = new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: 'Classify this receipt.',
        attachments: [],
        provider: $provider,
        model: 'primary-model',
    );
    $failure = new RuntimeException('Provider timed out.');

    RuntimeObservationCollector::begin();

    try {
        (new BenchmarkAgentMiddleware)->handle($prompt, fn (): never => throw $failure);
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }

    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->effectiveProvider)->toBeNull()
        ->and($observations[0]->effectiveModel)->toBeNull()
        ->and($observations[0]->usage->toArray())->toBe([
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cached_input_tokens' => 0,
            'reasoning_tokens' => 0,
        ])
        ->and($observations[0]->succeeded)->toBeFalse();
});

it('is inert outside an active benchmark and tolerates incomplete response metadata', function (): void {
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->once()->andReturn('openrouter');
    $provider->shouldReceive('driver')->once()->andReturn('openrouter');
    $prompt = new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: 'Extract this receipt.',
        attachments: [],
        provider: $provider,
        model: 'requested-model',
    );
    $response = new AgentResponse(
        invocationId: 'invocation-2',
        text: 'ok',
        usage: new Usage,
        meta: new Meta(provider: 'google'),
    );

    expect((new BenchmarkAgentMiddleware)->handle($prompt, fn (): AgentResponse => $response))->toBe($response)
        ->and(RuntimeObservationCollector::finish())->toBe([]);

    RuntimeObservationCollector::begin();
    (new BenchmarkAgentMiddleware)->handle($prompt, fn (): AgentResponse => $response);
    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->effectiveProvider)->toBeNull()
        ->and($observations[0]->effectiveModel)->toBeNull();
});

it('marks Laravel AI fake gateway observations as simulated', function (): void {
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->once()->andReturn('openrouter');
    $provider->shouldReceive('driver')->once()->andReturn('openrouter');
    $agent = Mockery::mock(Agent::class);
    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Extract this receipt.',
        attachments: [],
        provider: $provider,
        model: 'requested-model',
    );
    $response = new AgentResponse(
        invocationId: 'invocation-fake',
        text: 'ok',
        usage: new Usage(promptTokens: 10, completionTokens: 2),
        meta: new Meta(provider: 'openrouter', model: 'effective-model'),
    );

    $manager = Mockery::mock(AiManager::class);
    $manager->shouldReceive('hasFakeGatewayFor')->once()->with($agent)->andReturnTrue();
    $this->app->instance(AiManager::class, $manager);

    RuntimeObservationCollector::begin();
    (new BenchmarkAgentMiddleware)->handle($prompt, fn (): AgentResponse => $response);
    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->mode)->toBe(ExecutionMode::Simulated);
});

it('captures authoritative provider cost from a synchronous OpenRouter response', function (): void {
    $provider = new OpenRouterProvider(
        config: ['name' => 'router-alias', 'driver' => 'openrouter', 'key' => 'test-key'],
        events: $this->app->make(Dispatcher::class),
    );
    $prompt = new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: 'Extract this receipt.',
        attachments: [],
        provider: $provider,
        model: 'openai/gpt-test',
    );
    $response = (new AgentResponse(
        invocationId: 'invocation-cost',
        text: 'ok',
        usage: new Usage(promptTokens: 10, completionTokens: 2),
        meta: new Meta(provider: 'router-alias', model: 'openai/gpt-test'),
    ))->withRawResponse(new HttpResponse(new Psr7Response(
        body: json_encode(['usage' => ['cost' => 0.0000042]], JSON_THROW_ON_ERROR),
        headers: ['Content-Type' => 'application/json'],
    )));

    RuntimeObservationCollector::begin();
    (new BenchmarkAgentMiddleware)->handle($prompt, fn (): AgentResponse => $response);
    $observation = RuntimeObservationCollector::finish()[0];

    expect($observation->providerReportedCost?->toArray())->toBe([
        'amount' => '0.0000042',
        'currency' => 'USD',
    ]);
});

it('does not fail a successful benchmark when provider pricing metadata is malformed', function (): void {
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->once()->andReturn('custom');
    $provider->shouldReceive('driver')->once()->andThrow(new RuntimeException('Malformed driver configuration.'));
    $prompt = new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: 'Extract this receipt.',
        attachments: [],
        provider: $provider,
        model: 'model',
    );
    $response = new AgentResponse(
        invocationId: 'invocation-malformed-pricing',
        text: 'ok',
        usage: new Usage(promptTokens: 10, completionTokens: 2),
        meta: new Meta(provider: 'custom', model: 'model'),
    );

    RuntimeObservationCollector::begin();
    $actual = (new BenchmarkAgentMiddleware)->handle($prompt, fn (): AgentResponse => $response);
    $observation = RuntimeObservationCollector::finish()[0];

    expect($actual)->toBe($response)
        ->and($observation->succeeded)->toBeTrue()
        ->and($observation->providerReportedCost)->toBeNull()
        ->and($observation->usage->inputTokens)->toBe(10);
});

it('rejects nested observation spans', function (): void {
    RuntimeObservationCollector::begin();

    expect(fn () => RuntimeObservationCollector::begin())
        ->toThrow(LogicException::class, 'already active');
});
