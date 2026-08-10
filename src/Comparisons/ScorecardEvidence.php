<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Jkudish\LaravelAiPricing\ValueObjects\Money;
use RuntimeException;

/** @internal */
final class ScorecardEvidence
{
    /** @param array<string, mixed> $scorecard */
    public function aggregate(array $scorecard, ?string $configuration = null): AggregateEvidence
    {
        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials)) {
            throw new RuntimeException('Scorecard has an unsupported trial structure.');
        }

        $compatibility = [];
        $passes = [];
        $latencies = [];
        $costs = [];
        $costCurrency = null;
        $costComplete = true;

        foreach ($trials as $trial) {
            if (! is_array($trial) || ($configuration !== null && ($trial['configuration'] ?? null) !== $configuration)) {
                continue;
            }

            if (! is_string($trial['case_id'] ?? null)
                || ! is_string($trial['configuration'] ?? null)
                || ! is_int($trial['repeat'] ?? null)
                || ! is_array($trial['results'] ?? null)) {
                throw new RuntimeException('Scorecard contains invalid aggregate trial evidence.');
            }

            $identity = $trial['case_id']."\0".$trial['repeat'];
            if ($configuration === null) {
                $identity .= "\0".$trial['configuration'];
            }

            $scoredResults = array_values(array_filter(
                $trial['results'],
                fn (mixed $result): bool => is_array($result) && ($result['scorer'] ?? null) !== 'pest:test',
            ));
            $evaluatedResults = $scoredResults !== [] ? $scoredResults : $trial['results'];

            foreach ($evaluatedResults as $result) {
                if (! is_array($result) || ! is_string($result['scorer'] ?? null)) {
                    throw new RuntimeException('Scorecard contains invalid aggregate result evidence.');
                }

                $compatibility[] = $identity."\0".$result['scorer'];

                if (is_bool($result['passed'] ?? null)) {
                    $passes[] = $result['passed'] ? 1.0 : 0.0;
                }
            }

            $measurements = is_array($trial['results'][0] ?? null)
                ? ($trial['results'][0]['measurements'] ?? null)
                : null;

            if (! is_array($measurements) || $measurements === []) {
                throw new RuntimeException('Scorecard contains no aggregate target measurements.');
            }

            $trialLatency = 0.0;
            $trialCost = BigDecimal::zero();
            $trialHasCost = false;

            foreach ($measurements as $measurement) {
                if (! is_array($measurement)
                    || ($measurement['component'] ?? null) !== 'target'
                    || ! is_float($measurement['latency_ms'] ?? null) && ! is_int($measurement['latency_ms'] ?? null)) {
                    continue;
                }

                $trialLatency += (float) $measurement['latency_ms'];
                $pricing = $measurement['pricing'] ?? null;
                $snapshot = is_array($pricing) ? ($pricing['snapshot'] ?? null) : null;
                $cost = is_array($snapshot) ? ($snapshot['cost'] ?? null) : null;

                if (! is_array($pricing)
                    || ($pricing['completeness'] ?? null) !== 'complete'
                    || ! is_array($cost)
                    || ! is_string($cost['amount'] ?? null)
                    || ! is_string($cost['currency'] ?? null)) {
                    $costComplete = false;

                    continue;
                }

                $currency = strtoupper($cost['currency']);
                if ($costCurrency !== null && $costCurrency !== $currency) {
                    $costComplete = false;

                    continue;
                }

                $costCurrency = $currency;
                $trialCost = $trialCost->plus(BigDecimal::of($cost['amount']));
                $trialHasCost = true;
            }

            $latencies[] = $trialLatency;

            if ($trialHasCost) {
                $costs[] = $trialCost;
            }
        }

        if ($compatibility === []) {
            throw new RuntimeException('Scorecard contains no trials for the requested comparison.');
        }

        sort($compatibility);
        sort($latencies, SORT_NUMERIC);

        return new AggregateEvidence(
            compatibilityFingerprint: 'sha256:'.hash('sha256', json_encode($compatibility, JSON_THROW_ON_ERROR)),
            passRate: $passes === [] ? null : array_sum($passes) / count($passes),
            medianLatency: $this->median($latencies),
            averageCost: $costComplete && count($costs) === count($latencies) && $costCurrency !== null
                ? new Money($this->average($costs), $costCurrency)
                : null,
        );
    }

    /** @param list<float> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** @param list<BigDecimal> $values */
    private function average(array $values): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total->dividedBy(count($values), 18, RoundingMode::HalfEven);
    }
}
