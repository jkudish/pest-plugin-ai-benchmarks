<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Evidence;

use Pest\Evals\Events\Scored;

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

    public static function fromEvent(Scored $event): self
    {
        $score = self::normalizedScore($event->result->score);
        $threshold = self::normalizedScore($event->threshold);
        $scorer = trim($event->result->scorer);

        return new self(
            scorer: $scorer !== '' ? $scorer : 'unnamed-scorer',
            score: $score,
            reasoning: $event->result->reasoning,
            threshold: $threshold,
            passed: $score >= $threshold,
            sample: $event->sample,
            samples: $event->samples,
            input: $event->input,
            output: $event->output,
            expected: $event->expected,
        );
    }

    private static function normalizedScore(float $score): float
    {
        return is_finite($score) ? max(0.0, min(1.0, $score)) : 0.0;
    }
}
