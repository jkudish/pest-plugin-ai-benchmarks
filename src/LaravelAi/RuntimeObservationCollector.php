<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use Brick\Math\BigDecimal;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use Jkudish\PestAiBenchmarks\Measurements\NormalizedUsage;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
use LogicException;

final class RuntimeObservationCollector
{
    private static bool $active = false;

    private static ?Component $component = null;

    /** @var list<AgentObservation> */
    private static array $observations = [];

    /** @var array<int, array{invocationId: ?string, observation: AgentObservation, startedAt: int}> */
    private static array $openBuckets = [];

    private static ?string $violation = null;

    public static function begin(Component $component = Component::Target): void
    {
        if (self::$active) {
            throw new LogicException('A benchmark observation span is already active.');
        }

        self::$active = true;
        self::$component = $component;
        self::$observations = [];
        self::$openBuckets = [];
        self::$violation = null;
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function component(): ?Component
    {
        return self::$component;
    }

    /**
     * Mark the active span unfaithful: an instrumented agent would have sent
     * a different request than production. The application under test may
     * catch the exception the middleware throws, so the benchmark reads this
     * flag after the target and fails the trial itself.
     */
    public static function flagUnfaithful(string $violation): void
    {
        if (self::$active) {
            self::$violation ??= $violation;
        }
    }

    /**
     * The first fidelity violation flagged in the active span, if any. Read it
     * before finish(), which clears it.
     */
    public static function violation(): ?string
    {
        return self::$violation;
    }

    /**
     * Record an observation that stands on its own, outside a step-aggregated invocation.
     */
    public static function record(AgentObservation $observation): void
    {
        if (self::$active) {
            self::$observations[] = $observation;
        }
    }

    /**
     * Record one generation step of an agent invocation.
     *
     * laravel/ai 1.0 middleware wraps each generation step, while a measurement
     * row describes a whole invocation attempt. Steps are therefore folded back
     * into a single observation per attempt: usage and provider-reported cost
     * are summed, latency becomes the span from the attempt's first step to its
     * last, and any failed step marks the whole attempt failed — the attempt
     * itself stays open, and a later step of the same invocation still folds
     * into it. A first step always opens a new attempt, so retried or resumed
     * invocations stay separate measurements.
     */
    public static function recordStep(?string $invocationId, bool $isFirstStep, int $startedAt, int $endedAt, AgentObservation $observation): void
    {
        if (! self::$active) {
            return;
        }

        $index = null;

        if ($invocationId !== null) {
            foreach (self::$openBuckets as $bucketIndex => $bucket) {
                if ($bucket['invocationId'] === $invocationId) {
                    $index = $bucketIndex;
                }
            }
        } else {
            // An unidentified step may only continue an unidentified attempt;
            // merging it into an identified bucket would attribute another
            // invocation's usage to it.
            $last = array_key_last(self::$openBuckets);
            if ($last !== null && self::$openBuckets[$last]['invocationId'] === null) {
                $index = $last;
            }
        }

        if ($index === null || $isFirstStep) {
            self::$openBuckets[] = [
                'invocationId' => $invocationId,
                'observation' => $observation,
                'startedAt' => $startedAt,
            ];

            return;
        }

        $bucket = self::$openBuckets[$index];
        $bucket['observation'] = self::merged($bucket['observation'], $observation, $endedAt - $bucket['startedAt']);
        self::$openBuckets[$index] = $bucket;
    }

    /** @return list<AgentObservation> */
    public static function finish(): array
    {
        foreach (self::$openBuckets as $bucket) {
            self::$observations[] = $bucket['observation'];
        }

        $observations = self::$observations;

        self::reset();

        return $observations;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$component = null;
        self::$observations = [];
        self::$openBuckets = [];
        self::$violation = null;
    }

    private static function merged(AgentObservation $into, AgentObservation $step, int $spanNanoseconds): AgentObservation
    {
        return new AgentObservation(
            requestedProvider: $into->requestedProvider,
            requestedModel: $into->requestedModel,
            effectiveProvider: $step->effectiveProvider ?? $into->effectiveProvider,
            effectiveModel: $step->effectiveModel ?? $into->effectiveModel,
            usage: self::mergedUsage($into->usage, $step->usage),
            latencyMs: $spanNanoseconds / 1_000_000,
            succeeded: $into->succeeded && $step->succeeded,
            mode: $into->mode,
            providerReportedCost: self::mergedCost($into->providerReportedCost, $step->providerReportedCost),
            component: $into->component,
        );
    }

    private static function mergedUsage(NormalizedUsage $into, NormalizedUsage $step): NormalizedUsage
    {
        $additional = [];

        foreach ($into->toArray() as $unit => $quantity) {
            $additional[$unit] = $quantity;
        }

        foreach ($step->toArray() as $unit => $quantity) {
            $additional[$unit] = self::summedQuantity($additional[$unit] ?? 0, $quantity);
        }

        return new NormalizedUsage(
            // The four canonical units are always integers in a NormalizedUsage...
            inputTokens: (int) ($additional['input_tokens'] ?? 0),
            outputTokens: (int) ($additional['output_tokens'] ?? 0),
            cachedInputTokens: (int) ($additional['cached_input_tokens'] ?? 0),
            reasoningTokens: (int) ($additional['reasoning_tokens'] ?? 0),
            additionalUnits: array_diff_key($additional, [
                'input_tokens' => true,
                'output_tokens' => true,
                'cached_input_tokens' => true,
                'reasoning_tokens' => true,
            ]),
        );
    }

    private static function summedQuantity(string|int $a, string|int $b): string|int
    {
        if (is_int($a) && is_int($b)) {
            return $a + $b;
        }

        return (string) BigDecimal::of($a)->plus(BigDecimal::of($b));
    }

    /**
     * Provider-reported cost is all-or-nothing across an attempt's steps: a
     * single summed total only when every step reports one, otherwise null. A
     * partial sum would understate the authoritative provider figure, so the
     * observation records no cost rather than an incomplete one.
     */
    private static function mergedCost(?Money $into, ?Money $step): ?Money
    {
        if ($into === null || $step === null) {
            return null;
        }

        return $into->plus($step);
    }
}
