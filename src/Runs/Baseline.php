<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use RuntimeException;

/** @internal */
final readonly class Baseline
{
    /** @param array<string, mixed> $scorecard */
    public function __construct(private array $scorecard) {}

    public function assertCompatible(Scorecard $candidate): void
    {
        $candidateScorecard = $candidate->toArray();

        if (($this->scorecard['schema_version'] ?? null) !== ($candidateScorecard['schema_version'] ?? null)) {
            throw new RuntimeException('Baseline and candidate scorecard schemas are incompatible.');
        }

        if ($this->compatibilityFingerprints($this->scorecard) !== $this->compatibilityFingerprints($candidateScorecard)) {
            throw new RuntimeException('Baseline comparison requires identical case IDs, configurations, repeats, scorers, and compatibility fingerprints.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->scorecard;
    }

    /**
     * @param  array<string, mixed>  $scorecard
     * @return array<int, string>
     */
    private function compatibilityFingerprints(array $scorecard): array
    {
        $fingerprints = [];
        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials)) {
            throw new RuntimeException('Baseline scorecard has an unsupported trial structure.');
        }

        foreach ($trials as $trial) {
            if (! is_array($trial)
                || ! is_string($trial['case_id'] ?? null)
                || ! is_string($trial['configuration'] ?? null)
                || ! is_int($trial['repeat'] ?? null)
                || ! is_string($trial['fingerprint'] ?? null)
                || ! is_array($trial['results'] ?? null)) {
                throw new RuntimeException('Baseline scorecard contains invalid trial compatibility evidence.');
            }

            $identity = $trial['case_id']."\0".$trial['configuration']."\0".$trial['repeat'];
            $fingerprints[] = $identity."\0trial\0".$trial['fingerprint'];

            foreach ($trial['results'] as $result) {
                if (! is_array($result)
                    || ! is_string($result['scorer'] ?? null)
                    || ! is_array($result['measurements'] ?? null)) {
                    throw new RuntimeException('Baseline scorecard contains invalid result compatibility evidence.');
                }

                foreach ($result['measurements'] as $measurement) {
                    if (! is_array($measurement)
                        || ! is_string($measurement['component'] ?? null)
                        || ! is_string($measurement['fingerprint'] ?? null)) {
                        throw new RuntimeException('Baseline scorecard contains invalid measurement compatibility evidence.');
                    }

                    $fingerprints[] = $identity."\0result\0".$result['scorer']."\0".$measurement['component']."\0".$measurement['fingerprint'];
                }
            }
        }

        sort($fingerprints);

        return $fingerprints;
    }
}
