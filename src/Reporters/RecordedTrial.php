<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;

/** @internal */
final readonly class RecordedTrial
{
    public function __construct(
        public string $benchmark,
        public string $caseId,
        public string $configuration,
        public int $repeat,
        public string $fingerprint,
        public ModelIdentityEvidence $identity,
        public float $latencyMs,
        public bool $passed,
        public mixed $output,
    ) {}
}
