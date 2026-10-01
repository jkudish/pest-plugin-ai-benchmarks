<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Jkudish\PestAiBenchmarks\LaravelAi\InstrumentedAgent;
use Jkudish\PestAiBenchmarks\LaravelAi\RuntimeObservationCollector;
use Jkudish\PestAiBenchmarks\LaravelAi\UnfaithfulInstrumentation;
use Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures\BenchmarkedAgent;
use Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures\FaithfulReceiptAgent;
use Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures\UnfaithfulReceiptAgent;
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

function instrumentedStep(object $agent): PendingStep
{
    return new PendingStep(
        number: 0,
        isFinalStep: true,
        provider: 'openrouter',
        model: 'router/model',
        instructions: null,
        messages: [],
        tools: [],
        schema: null,
        options: new TextGenerationOptions(agent: $agent),
        invocationId: 'invocation-1',
    );
}

it('accepts an instrumented agent that repeats its production agent\'s Laravel AI attributes, in any order', function (): void {
    expect(InstrumentedAgent::violation(FaithfulReceiptAgent::class))->toBeNull()
        ->and(InstrumentedAgent::violation(BenchmarkedAgent::class))->toBeNull();

    InstrumentedAgent::assertFaithful(FaithfulReceiptAgent::class);
});

it('names the attributes an instrumented agent dropped or changed', function (): void {
    $violation = InstrumentedAgent::violation(UnfaithfulReceiptAgent::class);

    expect($violation)->toContain(UnfaithfulReceiptAgent::class)
        ->and($violation)->toContain('Missing: #[Strict], #[Timeout(30)]')
        ->and($violation)->toContain('Not on the production agent: #[Timeout(60)]');

    expect(fn () => InstrumentedAgent::assertFaithful(UnfaithfulReceiptAgent::class))
        ->toThrow(UnfaithfulInstrumentation::class);
});

it('refuses to send an unfaithful agent\'s request and flags the benchmark span', function (): void {
    RuntimeObservationCollector::begin();
    $sent = false;

    expect(fn () => (new BenchmarkAgentMiddleware)->handle(instrumentedStep(new UnfaithfulReceiptAgent), function () use (&$sent): StepResult {
        $sent = true;

        return new StepResult(new StepResponse('{}', [], FinishReason::Stop, new TextUsage, new Meta('openrouter', 'router/model')));
    }))->toThrow(UnfaithfulInstrumentation::class);

    expect($sent)->toBeFalse()
        ->and(RuntimeObservationCollector::violation())->toContain('Missing: #[Strict]');
});

it('lets a faithful agent\'s request through unchanged', function (): void {
    RuntimeObservationCollector::begin();
    $response = new StepResponse('{}', [], FinishReason::Stop, new TextUsage(inputTokens: 10, outputTokens: 2), new Meta('openrouter', 'router/model'));

    $result = (new BenchmarkAgentMiddleware)->handle(instrumentedStep(new FaithfulReceiptAgent), fn (): StepResult => new StepResult($response));

    expect($result->response())->toBe($response)
        ->and(RuntimeObservationCollector::violation())->toBeNull()
        ->and(RuntimeObservationCollector::finish())->toHaveCount(1);
});

it('does not check agents outside an active benchmark', function (): void {
    $response = new StepResponse('{}', [], FinishReason::Stop, new TextUsage, new Meta('openrouter', 'router/model'));

    $result = (new BenchmarkAgentMiddleware)->handle(instrumentedStep(new UnfaithfulReceiptAgent), fn (): StepResult => new StepResult($response));

    expect($result->response())->toBe($response);
});
