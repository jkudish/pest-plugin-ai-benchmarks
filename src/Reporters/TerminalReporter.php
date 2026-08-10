<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Jkudish\PestAiBenchmarks\Comparisons\RegressionEvaluation;
use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;

/** @internal */
final readonly class TerminalReporter
{
    public function render(Scorecard $scorecard): string
    {
        $data = $scorecard->toArray();
        $trials = is_array($data['trials'] ?? null) ? $data['trials'] : [];
        $results = 0;
        $passed = 0;
        $failed = 0;
        $latencyMs = 0.0;

        foreach ($trials as $trial) {
            if (! is_array($trial) || ! is_array($trial['results'] ?? null)) {
                continue;
            }

            $measured = [];

            foreach ($trial['results'] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $results++;
                $passed += ($result['passed'] ?? null) === true ? 1 : 0;
                $failed += ($result['passed'] ?? null) === false ? 1 : 0;

                foreach (is_array($result['measurements'] ?? null) ? $result['measurements'] : [] as $measurement) {
                    $fingerprint = is_array($measurement) ? ($measurement['fingerprint'] ?? null) : null;

                    if (is_array($measurement)
                        && is_string($fingerprint)
                        && ! isset($measured[$fingerprint])
                        && is_numeric($measurement['latency_ms'] ?? null)) {
                        $latencyMs += (float) $measurement['latency_ms'];
                        $measured[$fingerprint] = true;
                    }
                }
            }
        }

        return implode(PHP_EOL, [
            "Benchmark: {$scorecard->benchmark}",
            sprintf('Trials: %d | Results: %d | Passed: %d | Failed: %d', count($trials), $results, $passed, $failed),
            sprintf('Measured latency: %.2f ms', $latencyMs),
        ]).PHP_EOL;
    }

    public function renderComparison(RegressionEvaluation $evaluation, ?string $reference, ?string $baseline): string
    {
        $lines = [];

        if ($reference !== null) {
            $lines[] = "Reference: {$reference} (same-run evidence only)";
        }

        if ($baseline !== null) {
            $lines[] = "Baseline: {$baseline} | Gate status: {$evaluation->status->value}";
        }

        foreach ($evaluation->failures as $failure) {
            $lines[] = "Gate: {$failure}";
        }

        return $lines === [] ? '' : implode(PHP_EOL, $lines).PHP_EOL;
    }

    /** @param array<string, RegressionEvaluation> $evaluations */
    public function renderReferenceComparisons(array $evaluations, ?string $reference): string
    {
        if ($reference === null || $evaluations === []) {
            return '';
        }

        $lines = [];

        foreach ($evaluations as $configuration => $evaluation) {
            $changes = $evaluation->observedChanges === []
                ? ''
                : ' | Changes: '.json_encode($evaluation->observedChanges, JSON_THROW_ON_ERROR);
            $lines[] = "Candidate: {$configuration} vs {$reference} | Comparative status: {$evaluation->status->value} (evidence only){$changes}";
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
