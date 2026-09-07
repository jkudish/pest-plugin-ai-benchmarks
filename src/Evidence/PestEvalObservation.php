<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Evidence;

final readonly class PestEvalObservation
{
    public function __construct(
        public string $scorer,
        public float $score,
        public string $reasoning,
        public float $threshold,
        public bool $passed,
        public int $sample,
        public int $samples,
        public string $input,
        public string $output,
        public ?string $expected,
    ) {}

    public static function fromEvidence(ScorerEvidence $evidence, int $samples = 1): self
    {
        $score = self::normalizedScore($evidence->result->score);
        $threshold = self::normalizedScore($evidence->threshold);
        $scorer = trim($evidence->result->scorer);

        return new self(
            scorer: $scorer !== '' ? $scorer : 'unnamed-scorer',
            score: $score,
            reasoning: $evidence->result->reasoning,
            threshold: $threshold,
            passed: $score >= $threshold,
            sample: $evidence->sampleOrder,
            samples: $samples,
            input: $evidence->input,
            output: $evidence->output,
            expected: $evidence->expected,
        );
    }

    private static function normalizedScore(float $score): float
    {
        return is_finite($score) ? max(0.0, min(1.0, $score)) : 0.0;
    }
}
