<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Results\StableEvidenceSanitizer;

/** @internal */
final readonly class Result
{
    public const int MAX_SCORER_BYTES = 512;

    public const int MAX_REASONING_BYTES = 8_192;

    public ?string $reasoning;

    /** @param array<int, Measurement> $measurements */
    public function __construct(
        public EvidenceId $id,
        public string $scorer,
        public ?float $score,
        ?string $reasoning,
        public ?bool $passed,
        public array $measurements,
        public ?float $threshold = null,
        public ?int $sample = null,
        public ?int $samples = null,
    ) {
        EvidenceId::from($this->id->value, 'res');

        if (trim($this->scorer) === '') {
            throw new InvalidArgumentException('Result scorer must not be empty.');
        }

        if ($this->score !== null && (! is_finite($this->score) || $this->score < 0 || $this->score > 1)) {
            throw new InvalidArgumentException('Result score must be between 0.0 and 1.0.');
        }

        if ($this->threshold !== null && (! is_finite($this->threshold) || $this->threshold < 0 || $this->threshold > 1)) {
            throw new InvalidArgumentException('Result threshold must be between 0.0 and 1.0.');
        }

        if (($this->sample === null) !== ($this->samples === null)
            || $this->sample !== null && ($this->sample < 1 || $this->samples < $this->sample)) {
            throw new InvalidArgumentException('Result sample position and count must be valid and supplied together.');
        }

        if ($this->measurements === []) {
            throw new InvalidArgumentException('A result must contain at least one measurement.');
        }

        $this->reasoning = StableEvidenceSanitizer::text($reasoning, self::MAX_REASONING_BYTES);
    }

    /** @return array<string, mixed> */
    public function toArray(EvidenceId $scorecardId, EvidenceId $executionId, EvidenceId $trialId): array
    {
        return [
            'result_id' => $this->id->value,
            'source' => [
                'scorecard_id' => $scorecardId->value,
                'execution_id' => $executionId->value,
                'trial_id' => $trialId->value,
            ],
            'scorer' => StableEvidenceSanitizer::text($this->scorer, self::MAX_SCORER_BYTES),
            'score' => $this->score,
            'reasoning' => $this->reasoning,
            'threshold' => $this->threshold,
            'passed' => $this->passed,
            'sample' => $this->sample,
            'samples' => $this->samples,
            'measurements' => array_map(
                fn (Measurement $measurement): array => $measurement->toArray(),
                $this->measurements,
            ),
        ];
    }
}
