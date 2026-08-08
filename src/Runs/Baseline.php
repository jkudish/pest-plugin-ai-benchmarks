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

        if ($this->identities($this->scorecard) !== $this->identities($candidateScorecard)) {
            throw new RuntimeException('Baseline comparison requires identical case IDs and configuration identities.');
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
    private function identities(array $scorecard): array
    {
        $identities = [];
        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials)) {
            throw new RuntimeException('Baseline scorecard has an unsupported trial structure.');
        }

        foreach ($trials as $trial) {
            if (! is_array($trial)
                || ! is_string($trial['case_id'] ?? null)
                || ! is_string($trial['configuration'] ?? null)) {
                throw new RuntimeException('Baseline scorecard contains an invalid trial identity.');
            }

            $identities[] = $trial['case_id']."\0".$trial['configuration'];
        }

        sort($identities);

        return $identities;
    }
}
