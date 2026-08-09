<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Jkudish\LaravelAiPricing\ValueObjects\Money;

final class RegressionEvaluator
{
    public function evaluate(
        AggregateEvidence $current,
        AggregateEvidence $historical,
        RegressionPolicy $policy,
    ): RegressionEvaluation {
        if (! $policy->hasGates()) {
            return new RegressionEvaluation(RegressionStatus::EvidenceOnly);
        }

        if ($current->compatibilityFingerprint !== $historical->compatibilityFingerprint) {
            return new RegressionEvaluation(
                RegressionStatus::NotEvaluable,
                failures: ['Current and historical aggregate evidence are incompatible.'],
            );
        }

        $observed = [];
        $failures = [];
        $notEvaluable = [];

        $this->evaluateAbsoluteDrop(
            RegressionPolicy::PASS_RATE_DROP,
            $current->passRate,
            $historical->passRate,
            $policy,
            $observed,
            $failures,
            $notEvaluable,
        );

        $this->evaluateRelativeIncrease(
            RegressionPolicy::MEDIAN_LATENCY_INCREASE,
            $current->medianLatency,
            $historical->medianLatency,
            $policy,
            $observed,
            $failures,
            $notEvaluable,
        );

        $this->evaluateCostIncrease(
            RegressionPolicy::AVERAGE_COST_INCREASE,
            $current->averageCost,
            $historical->averageCost,
            $policy,
            $observed,
            $failures,
            $notEvaluable,
        );

        if ($notEvaluable !== []) {
            return new RegressionEvaluation(RegressionStatus::NotEvaluable, $observed, $notEvaluable);
        }

        if ($failures !== []) {
            return new RegressionEvaluation(RegressionStatus::Failed, $observed, $failures);
        }

        return new RegressionEvaluation(RegressionStatus::Passed, $observed);
    }

    /**
     * @param  array<string, float|string>  $observed
     * @param  list<string>  $failures
     * @param  list<string>  $notEvaluable
     */
    private function evaluateAbsoluteDrop(
        string $metric,
        ?float $current,
        ?float $historical,
        RegressionPolicy $policy,
        array &$observed,
        array &$failures,
        array &$notEvaluable,
    ): void {
        $threshold = $policy->threshold($metric);

        if ($threshold === null) {
            return;
        }

        if ($current === null || $historical === null) {
            $notEvaluable[] = sprintf('Regression gate [%s] is missing required evidence.', $metric);

            return;
        }

        $change = $historical - $current;
        $observed[$metric] = $change;

        if ($this->exceedsFloatBoundary($change, $threshold)) {
            $failures[] = sprintf('Regression gate [%s] exceeded its threshold.', $metric);
        }
    }

    /**
     * @param  array<string, float|string>  $observed
     * @param  list<string>  $failures
     * @param  list<string>  $notEvaluable
     */
    private function evaluateRelativeIncrease(
        string $metric,
        ?float $current,
        ?float $historical,
        RegressionPolicy $policy,
        array &$observed,
        array &$failures,
        array &$notEvaluable,
    ): void {
        $threshold = $policy->threshold($metric);

        if ($threshold === null) {
            return;
        }

        if ($current === null || $historical === null) {
            $notEvaluable[] = sprintf('Regression gate [%s] is missing required evidence.', $metric);

            return;
        }

        if ($historical === 0.0) {
            if ($current === 0.0) {
                $observed[$metric] = 0.0;

                return;
            }

            $notEvaluable[] = sprintf('Regression gate [%s] cannot calculate a relative change from a zero reference.', $metric);

            return;
        }

        $change = ($current - $historical) / $historical;
        $observed[$metric] = $change;

        if ($this->exceedsFloatBoundary($change, $threshold)) {
            $failures[] = sprintf('Regression gate [%s] exceeded its threshold.', $metric);
        }
    }

    /**
     * @param  array<string, float|string>  $observed
     * @param  list<string>  $failures
     * @param  list<string>  $notEvaluable
     */
    private function evaluateCostIncrease(
        string $metric,
        ?Money $current,
        ?Money $historical,
        RegressionPolicy $policy,
        array &$observed,
        array &$failures,
        array &$notEvaluable,
    ): void {
        $threshold = $policy->threshold($metric);

        if ($threshold === null) {
            return;
        }

        if ($current === null || $historical === null) {
            $notEvaluable[] = sprintf('Regression gate [%s] is missing required evidence.', $metric);

            return;
        }

        if ($current->currency !== $historical->currency) {
            $notEvaluable[] = sprintf(
                'Regression gate [%s] cannot compare mixed currencies [%s] and [%s].',
                $metric,
                $current->currency,
                $historical->currency,
            );

            return;
        }

        if ($historical->amount->isZero()) {
            if ($current->amount->isZero()) {
                $observed[$metric] = '0';

                return;
            }

            $notEvaluable[] = sprintf('Regression gate [%s] cannot calculate a relative change from a zero reference.', $metric);

            return;
        }

        $change = $current->amount
            ->minus($historical->amount)
            ->dividedBy($historical->amount, 18, RoundingMode::HalfEven);

        $observed[$metric] = $this->decimalString($change);

        if ($change->isGreaterThan(BigDecimal::of((string) $threshold))) {
            $failures[] = sprintf('Regression gate [%s] exceeded its threshold.', $metric);
        }
    }

    /**
     * Binary floating-point measurements can differ by a few ULPs at an exact
     * pass-rate or latency boundary. Authoritative monetary comparisons never
     * use this tolerance; they are evaluated with BigDecimal above.
     */
    private function exceedsFloatBoundary(float $change, float $threshold): bool
    {
        $tolerance = PHP_FLOAT_EPSILON * max(1.0, abs($change), abs($threshold)) * 4;

        return $change - $threshold > $tolerance;
    }

    private function decimalString(BigDecimal $value): string
    {
        $decimal = (string) $value;

        if (! str_contains($decimal, '.')) {
            return $decimal;
        }

        $decimal = rtrim(rtrim($decimal, '0'), '.');

        return $decimal === '-0' ? '0' : $decimal;
    }
}
