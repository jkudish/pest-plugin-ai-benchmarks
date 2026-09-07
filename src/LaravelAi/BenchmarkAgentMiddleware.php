<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use Closure;
use Illuminate\Container\Container;
use Jkudish\LaravelAiPricing\Adapters\LaravelAiObservationAdapter;
use Jkudish\LaravelAiPricing\Adapters\LaravelAiProviderCostExtractor;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
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
            $driver = self::driver($prompt, $response);

            RuntimeObservationCollector::record(new AgentObservation(
                requestedProvider: $prompt->provider()->name(),
                requestedModel: $prompt->model,
                effectiveProvider: $effectiveProvider,
                effectiveModel: $effectiveModel,
                usage: self::usage($response, $driver),
                latencyMs: self::elapsedMilliseconds($startedAt),
                succeeded: true,
                mode: $mode,
                providerReportedCost: self::providerReportedCost($response, $driver, $mode),
                component: RuntimeObservationCollector::component() ?? Component::Target,
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
                component: RuntimeObservationCollector::component() ?? Component::Target,
            ));

            throw $exception;
        }
    }

    private static function usage(AgentResponse $response, string $driver): NormalizedUsage
    {
        try {
            $provider = self::identityPart($response->meta->provider);
            $adapter = new LaravelAiObservationAdapter(
                providerDrivers: $provider !== null ? [$provider => $driver] : [],
            );
            $units = $adapter->adapt([
                'provider' => $provider ?? $driver,
                'model' => self::identityPart($response->meta->model) ?? 'unknown',
                'driver' => $driver,
                'usage' => $response->usage,
            ])->usage->toArray();
            $known = ['input_tokens', 'output_tokens', 'cached_input_tokens', 'reasoning_tokens'];
            $additional = array_map(
                static fn (string $quantity): string|int => ctype_digit($quantity) ? (int) $quantity : $quantity,
                array_filter(
                    array_diff_key($units, array_flip($known)),
                    static fn (string $quantity): bool => $quantity !== '0',
                ),
            );

            return new NormalizedUsage(
                inputTokens: (int) ($units['input_tokens'] ?? 0),
                outputTokens: (int) ($units['output_tokens'] ?? 0),
                cachedInputTokens: (int) ($units['cached_input_tokens'] ?? 0),
                reasoningTokens: (int) ($units['reasoning_tokens'] ?? 0),
                additionalUnits: $additional,
            );
        } catch (Throwable) {
            return self::directUsage($response);
        }
    }

    private static function directUsage(AgentResponse $response): NormalizedUsage
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

    private static function driver(AgentPrompt $prompt, AgentResponse $response): string
    {
        try {
            $driver = trim($prompt->provider()->driver());

            if ($driver !== '') {
                return $driver;
            }
        } catch (Throwable) {
            // Pricing instrumentation must not replace a successful provider response.
        }

        return self::identityPart($response->meta->provider) ?? 'unknown';
    }

    private static function providerReportedCost(AgentResponse $response, string $driver, ExecutionMode $mode): ?Money
    {
        if ($mode !== ExecutionMode::Live) {
            return null;
        }

        try {
            $container = Container::getInstance();
            $extractor = $container->bound(LaravelAiProviderCostExtractor::class)
                ? $container->make(LaravelAiProviderCostExtractor::class)
                : new LaravelAiProviderCostExtractor;

            return $extractor->extract($response, $driver);
        } catch (Throwable) {
            return null;
        }
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
