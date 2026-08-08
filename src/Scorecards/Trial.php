<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;

/** @internal */
final readonly class Trial
{
    /** @param array<int, Result> $results */
    public function __construct(
        public EvidenceId $id,
        public string $caseId,
        public string $configuration,
        public int $repeat,
        public string $fingerprint,
        public array $results,
    ) {
        EvidenceId::from($this->id->value, 'trial');

        if (trim($this->caseId) === '' || trim($this->configuration) === '' || trim($this->fingerprint) === '') {
            throw new InvalidArgumentException('Trial case ID, configuration, and fingerprint must not be empty.');
        }

        if ($this->repeat < 1) {
            throw new InvalidArgumentException('Trial repeat must be at least 1.');
        }

        if ($this->results === []) {
            throw new InvalidArgumentException('A trial must contain at least one result.');
        }

    }

    /** @return array<string, mixed> */
    public function toArray(EvidenceId $scorecardId, EvidenceId $executionId): array
    {
        return [
            'trial_id' => $this->id->value,
            'source' => [
                'scorecard_id' => $scorecardId->value,
                'execution_id' => $executionId->value,
            ],
            'case_id' => $this->caseId,
            'configuration' => $this->configuration,
            'repeat' => $this->repeat,
            'fingerprint' => $this->fingerprint,
            'results' => array_map(
                fn (Result $result): array => $result->toArray($scorecardId, $executionId, $this->id),
                $this->results,
            ),
        ];
    }
}
