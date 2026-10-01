<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Throwable;

/**
 * Why a trial failed. The stable scorecard records only the stage and the
 * exception class, which carry no application data; the message stays in
 * the private replay bundle with the rest of the trial's private evidence.
 *
 * @internal
 */
final readonly class TrialFailure
{
    public const string STAGE_CONFIGURATION = 'configuration';

    public const string STAGE_TARGET = 'target';

    public const string STAGE_EVALUATION = 'evaluation';

    private function __construct(
        public string $stage,
        public string $class,
        public string $message,
    ) {}

    public static function from(string $stage, Throwable $exception): self
    {
        return new self($stage, $exception::class, $exception->getMessage());
    }

    /** The scorecard reasoning for the Pest test result. */
    public function summary(): string
    {
        return match ($this->stage) {
            self::STAGE_CONFIGURATION => "The configuration could not be applied ({$this->class}).",
            self::STAGE_TARGET => "The target failed ({$this->class}).",
            default => "The evaluation failed ({$this->class}).",
        };
    }

    /** @return array{stage: string, class: string, message: string} */
    public function toArray(): array
    {
        return ['stage' => $this->stage, 'class' => $this->class, 'message' => $this->message];
    }
}
