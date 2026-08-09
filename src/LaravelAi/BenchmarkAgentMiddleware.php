<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use Closure;
use Illuminate\Container\Container;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;
use Laravel\Ai\AiManager;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

final class BenchmarkAgentMiddleware
{
    /** @param Closure(AgentPrompt): AgentResponse $next */
    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        if (! RuntimeObservationCollector::active()) {
            return $next($prompt);
        }

        $startedAt = hrtime(true);
        $mode = self::executionMode($prompt);

        try {
            $response = $next($prompt);
            [$effectiveProvider, $effectiveModel] = self::effectiveIdentity($response);

            RuntimeObservationCollector::record(new AgentObservation(
                requestedProvider: $prompt->provider()->name(),
                requestedModel: $prompt->model,
                effectiveProvider: $effectiveProvider,
                effectiveModel: $effectiveModel,
                usage: self::usage($response),
                latencyMs: self::elapsedMilliseconds($startedAt),
                succeeded: true,
                mode: $mode,
            ));

            return $response;
        } catch (Throwable $exception) {
            RuntimeObservationCollector::record(new AgentObservation(
                requestedProvider: $prompt->provider()->name(),
                requestedModel: $prompt->model,
                effectiveProvider: null,
                effectiveModel: null,
                usage: new NormalizedUsage,
                latencyMs: self::elapsedMilliseconds($startedAt),
                succeeded: false,
                mode: $mode,
            ));

            throw $exception;
        }
    }

    private static function usage(AgentResponse $response): NormalizedUsage
    {
        $cacheWriteInputTokens = $response->usage->cacheWriteInputTokens;

        return new NormalizedUsage(
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            cachedInputTokens: $response->usage->cacheReadInputTokens,
            reasoningTokens: $response->usage->reasoningTokens,
            additionalUnits: $cacheWriteInputTokens > 0
                ? ['cache_write_input_tokens' => $cacheWriteInputTokens]
                : [],
        );
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function effectiveIdentity(AgentResponse $response): array
    {
        $provider = self::identityPart($response->meta->provider);
        $model = self::identityPart($response->meta->model);

        return $provider !== null && $model !== null
            ? [$provider, $model]
            : [null, null];
    }

    private static function identityPart(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private static function executionMode(AgentPrompt $prompt): ExecutionMode
    {
        $container = Container::getInstance();

        if (! $container->bound(AiManager::class)) {
            return ExecutionMode::Live;
        }

        return $container->make(AiManager::class)->hasFakeGatewayFor($prompt->agent)
            ? ExecutionMode::Simulated
            : ExecutionMode::Live;
    }
}
