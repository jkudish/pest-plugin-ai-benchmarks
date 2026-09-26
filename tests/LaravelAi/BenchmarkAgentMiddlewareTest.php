<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;
use Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures\BenchmarkedAgent;
use Laravel\Ai\AiManager;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

afterEach(function (): void {
    RuntimeObservationCollector::reset();
});

function benchmarkStep(?Agent $agent = null, int $number = 0, string $provider = 'openrouter', string $model = 'router/requested-model', ?string $invocationId = 'invocation-1'): PendingStep
{
    return new PendingStep(
        number: $number,
        isFinalStep: true,
        provider: $provider,
        model: $model,
        instructions: null,
        messages: [],
        tools: [],
        schema: null,
        options: $agent === null ? null : new TextGenerationOptions(agent: $agent),
        invocationId: $invocationId,
    );
}

/**
 * @template T of StepResponse
 *
 * @param  T  $response
 * @return T
 */
function settle(StepResult $result): StepResponse
{
    return $result->response();
}

it('captures truthful Laravel AI identity usage and latency during an active benchmark', function (): void {
    $step = benchmarkStep();
    $response = new StepResponse(
        text: '{"merchant":"Acme"}',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(
            inputTokens: 120,
            outputTokens: 30,
            cacheReadInputTokens: 20,
            cacheWriteInputTokens: 15,
            reasoningTokens: 10,
        ),
        meta: new Meta(provider: 'google', model: 'gemini-effective'),
    );

    RuntimeObservationCollector::begin();

    $actual = settle((new BenchmarkAgentMiddleware)->handle($step, function (PendingStep $handled) use ($step, $response): StepResult {
        expect($handled)->toBe($step);

        return new StepResult($response);
    }));

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

it('folds the steps of one invocation into a single observation', function (): void {
    $middleware = new BenchmarkAgentMiddleware;

    $first = new StepResponse(
        text: 'searching',
        toolCalls: [],
        finishReason: FinishReason::ToolCalls,
        usage: new TextUsage(inputTokens: 100, outputTokens: 10, cacheReadInputTokens: 30, cacheWriteInputTokens: 10),
        meta: new Meta(provider: 'google', model: 'gemini-effective'),
    );
    $second = new StepResponse(
        text: '{"merchant":"Acme"}',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 200, outputTokens: 20),
        meta: new Meta(provider: 'google', model: 'gemini-effective'),
    );

    RuntimeObservationCollector::begin();

    settle($middleware->handle(benchmarkStep(number: 0), fn (): StepResult => new StepResult($first)));
    usleep(1500);
    settle($middleware->handle(benchmarkStep(number: 1), fn (): StepResult => new StepResult($second)));

    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->usage->toArray())->toBe([
            // Both steps report inclusive input totals, so each contributes its
            // uncached remainder (100 - 30 - 10 and 200 - 0 - 0)...
            'input_tokens' => 260,
            'output_tokens' => 30,
            'cached_input_tokens' => 30,
            'reasoning_tokens' => 0,
            'cache_write_input_tokens' => 10,
        ])
        ->and($observations[0]->succeeded)->toBeTrue()
        // The latency spans the whole invocation, including time between steps.
        ->and($observations[0]->latencyMs)->toBeGreaterThanOrEqual(1.5);
});

it('keeps separate invocations and interleaved sub-agents apart', function (): void {
    $middleware = new BenchmarkAgentMiddleware;
    $response = new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 5, outputTokens: 1),
        meta: new Meta(provider: 'google', model: 'gemini-effective'),
    );

    RuntimeObservationCollector::begin();

    settle($middleware->handle(benchmarkStep(number: 0, invocationId: 'parent'), fn (): StepResult => new StepResult($response)));
    settle($middleware->handle(benchmarkStep(number: 0, provider: 'openai', model: 'child/model', invocationId: 'child'), fn (): StepResult => new StepResult($response)));
    settle($middleware->handle(benchmarkStep(number: 1, invocationId: 'parent'), fn (): StepResult => new StepResult($response)));

    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(2)
        ->and($observations[0]->requestedProvider)->toBe('openrouter')
        ->and($observations[0]->requestedModel)->toBe('router/requested-model')
        ->and($observations[0]->usage->inputTokens)->toBe(10)
        ->and($observations[1]->requestedProvider)->toBe('openai')
        ->and($observations[1]->requestedModel)->toBe('child/model')
        ->and($observations[1]->usage->inputTokens)->toBe(5);
});

it('records observations under the active scorecard component', function (): void {
    $step = benchmarkStep(model: 'judge/model');
    $response = new StepResponse(
        text: 'pass',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 10, outputTokens: 2),
        meta: new Meta(provider: 'openrouter', model: 'judge/model'),
    );

    RuntimeObservationCollector::begin(Component::Judge);
    settle((new BenchmarkAgentMiddleware)->handle($step, fn (): StepResult => new StepResult($response)));

    expect(RuntimeObservationCollector::finish()[0]->component)->toBe(Component::Judge);
});

it('records failed attempts and rethrows the original exception', function (): void {
    $step = benchmarkStep(model: 'primary-model');
    $failure = new RuntimeException('Provider timed out.');

    RuntimeObservationCollector::begin();

    try {
        (new BenchmarkAgentMiddleware)->handle($step, fn (): never => throw $failure);
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

it('records a retried invocation as a separate failed and successful attempt', function (): void {
    $middleware = new BenchmarkAgentMiddleware;
    $response = new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 12, outputTokens: 4),
        meta: new Meta(provider: 'fail-backup', model: 'backup/model-b'),
    );
    $failure = new RuntimeException('Provider timed out.');

    RuntimeObservationCollector::begin();

    try {
        $middleware->handle(benchmarkStep(provider: 'fail-primary', model: 'primary/model-a', invocationId: 'shared'), fn (): never => throw $failure);
    } catch (RuntimeException) {
        // Retried below.
    }

    settle($middleware->handle(benchmarkStep(provider: 'fail-backup', model: 'backup/model-b', invocationId: 'shared'), fn (): StepResult => new StepResult($response)));

    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(2)
        ->and($observations[0]->requestedProvider)->toBe('fail-primary')
        ->and($observations[0]->succeeded)->toBeFalse()
        ->and($observations[1]->requestedProvider)->toBe('fail-backup')
        ->and($observations[1]->succeeded)->toBeTrue()
        ->and($observations[1]->usage->inputTokens)->toBe(12);
});

it('is inert outside an active benchmark and tolerates incomplete response metadata', function (): void {
    $step = benchmarkStep(model: 'requested-model');
    $response = new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage,
        meta: new Meta(provider: 'google'),
    );

    expect(settle((new BenchmarkAgentMiddleware)->handle($step, fn (): StepResult => new StepResult($response))))->toBe($response)
        ->and(RuntimeObservationCollector::finish())->toBe([]);

    RuntimeObservationCollector::begin();
    settle((new BenchmarkAgentMiddleware)->handle($step, fn (): StepResult => new StepResult($response)));
    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->effectiveProvider)->toBeNull()
        ->and($observations[0]->effectiveModel)->toBeNull();
});

it('marks Laravel AI fake gateway observations as simulated', function (): void {
    $agent = Mockery::mock(Agent::class);
    $step = benchmarkStep(agent: $agent);
    $response = new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 10, outputTokens: 2),
        meta: new Meta(provider: 'openrouter', model: 'effective-model'),
    );

    $manager = Mockery::mock(AiManager::class);
    $manager->shouldReceive('hasFakeGatewayFor')->once()->with($agent)->andReturnTrue();
    $this->app->instance(AiManager::class, $manager);

    RuntimeObservationCollector::begin();
    settle((new BenchmarkAgentMiddleware)->handle($step, fn (): StepResult => new StepResult($response)));
    $observations = RuntimeObservationCollector::finish();

    expect($observations)->toHaveCount(1)
        ->and($observations[0]->mode)->toBe(ExecutionMode::Simulated);
});

it('captures authoritative provider cost from a synchronous OpenRouter response', function (): void {
    $this->app->register(AiServiceProvider::class);

    config([
        'ai.providers.router-alias' => ['name' => 'router-alias', 'driver' => 'openrouter', 'key' => 'test-key'],
    ]);

    $step = benchmarkStep(provider: 'router-alias', model: 'openai/gpt-test');
    $response = (new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 10, outputTokens: 2),
        meta: new Meta(provider: 'router-alias', model: 'openai/gpt-test'),
    ))->withRawResponse(new HttpResponse(new Psr7Response(
        body: json_encode(['usage' => ['cost' => 0.0000042]], JSON_THROW_ON_ERROR),
        headers: ['Content-Type' => 'application/json'],
    )));

    RuntimeObservationCollector::begin();
    settle((new BenchmarkAgentMiddleware)->handle($step, fn (): StepResult => new StepResult($response)));
    $observation = RuntimeObservationCollector::finish()[0];

    expect($observation->providerReportedCost?->toArray())->toBe([
        'amount' => '0.0000042',
        'currency' => 'USD',
    ]);
});

it('sums the provider-reported cost across the steps of one invocation', function (): void {
    $this->app->register(AiServiceProvider::class);

    config([
        'ai.providers.router-alias' => ['name' => 'router-alias', 'driver' => 'openrouter', 'key' => 'test-key'],
    ]);

    $middleware = new BenchmarkAgentMiddleware;
    $costed = fn (float $cost): StepResponse => (new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 10, outputTokens: 2),
        meta: new Meta(provider: 'router-alias', model: 'openai/gpt-test'),
    ))->withRawResponse(new HttpResponse(new Psr7Response(
        body: json_encode(['usage' => ['cost' => $cost]], JSON_THROW_ON_ERROR),
        headers: ['Content-Type' => 'application/json'],
    )));

    RuntimeObservationCollector::begin();

    settle($middleware->handle(benchmarkStep(provider: 'router-alias'), fn (): StepResult => new StepResult($costed(0.1))));
    settle($middleware->handle(benchmarkStep(provider: 'router-alias', number: 1), fn (): StepResult => new StepResult($costed(0.2))));

    $observation = RuntimeObservationCollector::finish()[0];

    expect($observation->providerReportedCost?->toArray())->toBe([
        'amount' => '0.3',
        'currency' => 'USD',
    ]);
});

it('does not fail a successful benchmark when provider pricing metadata is malformed', function (): void {
    $step = benchmarkStep(provider: 'custom', model: 'model');
    $response = new StepResponse(
        text: 'ok',
        toolCalls: [],
        finishReason: FinishReason::Stop,
        usage: new TextUsage(inputTokens: 10, outputTokens: 2),
        meta: new Meta(provider: 'custom', model: 'model'),
    );

    $manager = Mockery::mock(AiManager::class);
    $manager->shouldReceive('textProvider')->andThrow(new RuntimeException('Malformed driver configuration.'));
    $this->app->instance(AiManager::class, $manager);

    RuntimeObservationCollector::begin();
    $actual = settle((new BenchmarkAgentMiddleware)->handle($step, fn (): StepResult => new StepResult($response)));
    $observation = RuntimeObservationCollector::finish()[0];

    expect($actual)->toBe($response)
        ->and($observation->succeeded)->toBeTrue()
        ->and($observation->providerReportedCost)->toBeNull()
        ->and($observation->usage->inputTokens)->toBe(10);
});

it('observes each attempt of a native Laravel AI failover prompt with stubbed transport', function (): void {
    // Boot the SDK container bindings (AiManager singleton, ai config) for this test only,
    // so the native agent failover path resolves real providers through the test app.
    $this->app->register(AiServiceProvider::class);

    config([
        'ai.providers.fail-primary' => ['driver' => 'openrouter', 'key' => 'test-key'],
        'ai.providers.fail-backup' => ['driver' => 'openrouter', 'key' => 'test-key'],
    ]);

    Http::preventStrayRequests();

    Http::fakeSequence()
        ->push(status: 429)
        ->pushResponse(Http::response([
            'id' => 'chatcmpl-fallback',
            'object' => 'chat.completion',
            'model' => 'google/gemini-effective',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => '{"merchant":"Acme"}'],
                'finish_reason' => 'stop',
            ],
            ],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]));

    Event::fake();

    RuntimeObservationCollector::begin();

    $response = (new BenchmarkedAgent)->prompt(
        'Extract this receipt.',
        provider: ['fail-primary' => 'primary/model-a', 'fail-backup' => 'backup/model-b'],
    );

    $observations = RuntimeObservationCollector::finish();

    expect($response->text)->toBe('{"merchant":"Acme"}')
        ->and($observations)->toHaveCount(2)
        // The 429-limited first provider attempt is recorded as a failed observation...
        ->and($observations[0]->succeeded)->toBeFalse()
        ->and($observations[0]->requestedProvider)->toBe('fail-primary')
        ->and($observations[0]->requestedModel)->toBe('primary/model-a')
        ->and($observations[0]->effectiveProvider)->toBeNull()
        ->and($observations[0]->effectiveModel)->toBeNull()
        // ...and the native fallback attempt is recorded as a successful observation...
        ->and($observations[1]->succeeded)->toBeTrue()
        ->and($observations[1]->requestedProvider)->toBe('fail-backup')
        ->and($observations[1]->requestedModel)->toBe('backup/model-b')
        ->and($observations[1]->effectiveProvider)->toBe('fail-backup')
        ->and($observations[1]->effectiveModel)->toBe('google/gemini-effective')
        ->and($observations[1]->usage->inputTokens)->toBe(12)
        ->and($observations[1]->usage->outputTokens)->toBe(4)
        // ...with the run's terminal failure absent because the fallback succeeded.
        ->and(Event::dispatched(AgentFailedOver::class))->toHaveCount(1)
        ->and(Event::assertDispatched(AgentFailedOver::class, fn (AgentFailedOver $event): bool => $event->invocationId === $response->invocationId))
        ->and(Event::assertNotDispatched(AgentFailed::class));

    // Exactly one transport call per attempt, no extras, and both attempts carried the same prompt.
    Http::assertSentCount(2);
    foreach (['primary/model-a', 'backup/model-b'] as $model) {
        Http::assertSent(fn (Request $request): bool => $request['model'] === $model
            && str_contains($request->body(), 'Extract this receipt.'));
    }
});

it('rejects nested observation spans', function (): void {
    RuntimeObservationCollector::begin();

    expect(fn () => RuntimeObservationCollector::begin())
        ->toThrow(LogicException::class, 'already active');
});
