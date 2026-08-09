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
        return new self(
            scorer: $event->result->scorer,
            score: $event->result->score,
            reasoning: $event->result->reasoning,
            threshold: $event->threshold,
            passed: $event->passed,
            sample: $event->sample,
            samples: $event->samples,
            input: $event->input,
            output: $event->output,
            expected: $event->expected,
        );
    }
}
