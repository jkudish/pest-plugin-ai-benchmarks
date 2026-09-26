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
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\Meta;
use Throwable;

final class BenchmarkAgentMiddleware
{
    /**
     * @param  Closure(PendingStep): StepResult  $next
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        if (! RuntimeObservationCollector::active()) {
            return $next($step);
        }

        $startedAt = hrtime(true);
        $mode = self::executionMode($step);

        try {
            $result = $next($step);
        } catch (Throwable $exception) {
            RuntimeObservationCollector::recordStep(
                invocationId: $step->invocationId,
                isFirstStep: $step->isFirstStep(),
                startedAt: $startedAt,
                endedAt: hrtime(true),
                observation: new AgentObservation(
                    requestedProvider: $step->provider,
                    requestedModel: $step->model,
                    effectiveProvider: null,
                    effectiveModel: null,
                    usage: new NormalizedUsage,
                    latencyMs: self::elapsedMilliseconds($startedAt),
                    succeeded: false,
                    mode: $mode,
                    component: RuntimeObservationCollector::component() ?? Component::Target,
                ),
            );

            throw $exception;
        }

        $result->then(function (StepResponse $response) use ($step, $startedAt, $mode): void {
            $endedAt = hrtime(true);
            [$effectiveProvider, $effectiveModel] = self::effectiveIdentity($response->meta);
            $driver = self::driver($step, $response->meta);

            RuntimeObservationCollector::recordStep(
                invocationId: $step->invocationId,
                isFirstStep: $step->isFirstStep(),
                startedAt: $startedAt,
                endedAt: $endedAt,
                observation: new AgentObservation(
                    requestedProvider: $step->provider,
                    requestedModel: $step->model,
                    effectiveProvider: $effectiveProvider,
                    effectiveModel: $effectiveModel,
                    usage: self::usage($response, $driver),
                    latencyMs: self::elapsedMilliseconds($startedAt, $endedAt),
                    succeeded: true,
                    mode: $mode,
                    providerReportedCost: self::providerReportedCost($response, $driver, $mode),
                    component: RuntimeObservationCollector::component() ?? Component::Target,
                ),
            );
        });

        return $result;
    }

    private static function usage(StepResponse $response, string $driver): NormalizedUsage
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

    private static function directUsage(StepResponse $response): NormalizedUsage
    {
        $usage = $response->usage;

        // laravel/ai 1.0 reports an input total that always includes cached and
        // cache-written tokens, so the uncached input is the remainder...
        $inputTokens = max(0, $usage->inputTokens
            - $usage->cacheReadInputTokens
            - $usage->cacheWriteInputTokens);

        return new NormalizedUsage(
            inputTokens: $inputTokens,
            outputTokens: $usage->outputTokens,
            cachedInputTokens: $usage->cacheReadInputTokens ?? 0,
            reasoningTokens: $usage->reasoningTokens ?? 0,
            additionalUnits: $usage->cacheWriteInputTokens > 0
                ? ['cache_write_input_tokens' => $usage->cacheWriteInputTokens]
                : [],
        );
    }

    private static function driver(PendingStep $step, Meta $meta): string
    {
        try {
            $container = Container::getInstance();

            if ($container->bound(AiManager::class)) {
                $driver = trim($container->make(AiManager::class)->textProvider($step->provider)->driver());

                if ($driver !== '') {
                    return $driver;
                }
            }
        } catch (Throwable) {
            // Pricing instrumentation must not replace a successful provider response.
        }

        return self::identityPart($meta->provider) ?? 'unknown';
    }

    private static function providerReportedCost(StepResponse $response, string $driver, ExecutionMode $mode): ?Money
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
    private static function effectiveIdentity(Meta $meta): array
    {
        $provider = self::identityPart($meta->provider);
        $model = self::identityPart($meta->model);

        return $provider !== null && $model !== null
            ? [$provider, $model]
            : [null, null];
    }

    private static function identityPart(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    private static function elapsedMilliseconds(int $startedAt, ?int $endedAt = null): float
    {
        return (($endedAt ?? hrtime(true)) - $startedAt) / 1_000_000;
    }

    private static function executionMode(PendingStep $step): ExecutionMode
    {
        $agent = $step->options?->agent;

        if (! $agent instanceof Agent) {
            return ExecutionMode::Live;
        }

        $container = Container::getInstance();

        if (! $container->bound(AiManager::class)) {
            return ExecutionMode::Live;
        }

        return $container->make(AiManager::class)->hasFakeGatewayFor($agent)
            ? ExecutionMode::Simulated
            : ExecutionMode::Live;
    }
}
