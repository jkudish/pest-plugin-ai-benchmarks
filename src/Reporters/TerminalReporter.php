<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

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
}
