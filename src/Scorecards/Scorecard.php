<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;
use JsonException;

/** @internal */
final readonly class Scorecard
{
    public const string SCHEMA_VERSION = '0.1.0';

    public const string SCHEMA_URL = 'https://raw.githubusercontent.com/jkudish/pest-plugin-ai-benchmarks/v0.1.0/resources/schema/scorecard.schema.json';

    public const string PACKAGE_NAME = 'jkudish/pest-plugin-ai-benchmarks';

    /** @param array<int, Trial> $trials */
    public function __construct(
        public EvidenceId $id,
        public EvidenceId $executionId,
        public string $benchmark,
        public DateTimeImmutable $createdAt,
        public array $trials,
        public string $packageVersion = '0.1.0-dev',
        public ?OpaqueContext $context = null,
    ) {
        EvidenceId::from($this->id->value, 'sc');
        EvidenceId::from($this->executionId->value, 'exec');

        if (trim($this->benchmark) === '' || trim($this->packageVersion) === '') {
            throw new InvalidArgumentException('Scorecard benchmark and package version must not be empty.');
        }

        if ($this->trials === []) {
            throw new InvalidArgumentException('A scorecard must contain at least one trial.');
        }

    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $scorecard = [
            'schema_url' => self::SCHEMA_URL,
            'schema_version' => self::SCHEMA_VERSION,
            'package' => [
                'name' => self::PACKAGE_NAME,
                'version' => $this->packageVersion,
            ],
            'scorecard_id' => $this->id->value,
            'execution_id' => $this->executionId->value,
            'benchmark' => $this->benchmark,
            'created_at' => $this->createdAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'trials' => array_map(
                fn (Trial $trial): array => $trial->toArray($this->id, $this->executionId),
                $this->trials,
            ),
        ];

        if ($this->context instanceof OpaqueContext) {
            $scorecard['context'] = (new NormalizedObject($this->context->toArray()))->toJsonValue();
        }

        return $scorecard;
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }
}
