<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Laravel\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;
use Jkudish\PestAiBenchmarks\Runs\ReplayPayload;
use Jkudish\PestAiBenchmarks\Runs\RunBundle;
use Jkudish\PestAiBenchmarks\Runs\RunId;
use Jkudish\PestAiBenchmarks\Runs\RunPaths;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;
use Jkudish\PestAiBenchmarks\Scorecards\Measurement;
use Jkudish\PestAiBenchmarks\Scorecards\PricingCompleteness;
use Jkudish\PestAiBenchmarks\Scorecards\Result;
use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use Jkudish\PestAiBenchmarks\Scorecards\Trial;
use Pest\TestSuite;
use ReflectionFunction;

/** @internal */
final class ExecutionRecorder
{
    /** @var list<RecordedTrial> */
    private static array $trials = [];

    /** @var array<string, int> */
    private static array $repeats = [];

    /** @var array<string, OpaqueContext|null> */
    private static array $contexts = [];

    /** @param list<mixed> $arguments */
    public static function caseId(array $arguments): string
    {
        return 'case_'.substr(hash('sha256', self::encoded(self::stableCaseValue($arguments))), 0, 24);
    }

    /** @return array{file: string, start_line: int, end_line: int, source_sha256: string} */
    public static function targetIdentity(Closure $target): array
    {
        $reflection = new ReflectionFunction($target);
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (! is_string($file) || $file === '' || $startLine < 1 || $endLine < $startLine) {
            throw new InvalidArgumentException('Benchmark targets must have a stable source-file identity.');
        }

        $lines = file($file);

        if ($lines === false) {
            throw new InvalidArgumentException(sprintf('Benchmark target source [%s] could not be read.', $file));
        }

        $root = rtrim(TestSuite::getInstance()->rootPath, '/\\').DIRECTORY_SEPARATOR;
        $normalizedFile = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        $source = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        return [
            'file' => str_replace('\\', '/', $normalizedFile),
            'start_line' => $startLine,
            'end_line' => $endLine,
            'source_sha256' => hash('sha256', $source),
        ];
    }

    /** @param array{file: string, start_line: int, end_line: int, source_sha256: string} $targetIdentity */
    public static function record(
        string $benchmark,
        string $caseId,
        string $configurationName,
        Configuration $configuration,
        ModelIdentityEvidence $identity,
        float $latencyMs,
        bool $passed,
        mixed $output,
        ?OpaqueContext $context,
        array $targetIdentity,
    ): void {
        $mode = ExecutionMode::Simulated;
        $fingerprint = 'sha256:'.hash('sha256', self::encoded([
            'benchmark' => $benchmark,
            'target' => $targetIdentity,
            'case_id' => $caseId,
            'configuration' => [
                'name' => $configurationName,
                'provider' => $configuration->provider,
                'model' => $configuration->model,
                'options' => $configuration->options,
                'settings' => $configuration->settings,
            ],
            'execution_mode' => $mode->value,
            'schema_version' => Scorecard::SCHEMA_VERSION,
            'package' => [
                'name' => Scorecard::PACKAGE_NAME,
                'version' => '0.1.0-dev',
            ],
        ]));
        $repeatKey = $benchmark."\0".$caseId."\0".$configurationName;
        $repeat = (self::$repeats[$repeatKey] ?? 0) + 1;
        self::$repeats[$repeatKey] = $repeat;
        self::$contexts[$benchmark] = $context;

        self::$trials[] = new RecordedTrial(
            benchmark: $benchmark,
            caseId: $caseId,
            configuration: $configurationName,
            repeat: $repeat,
            fingerprint: $fingerprint,
            identity: $identity,
            latencyMs: $latencyMs,
            passed: $passed,
            output: self::jsonSafe($output),
        );
    }

    /** @return list<Scorecard> */
    public static function flush(): array
    {
        if (self::$trials === []) {
            return [];
        }

        $root = TestSuite::getInstance()->rootPath;
        $paths = RunPaths::forProject($root);
        $groups = [];
        $scorecards = [];

        foreach (self::$trials as $trial) {
            $groups[$trial->benchmark][] = $trial;
        }

        foreach ($groups as $benchmark => $trials) {
            $scorecards[] = self::writeBenchmark($paths, $benchmark, $trials);
        }

        self::reset();

        return $scorecards;
    }

    public static function reset(): void
    {
        self::$trials = [];
        self::$repeats = [];
        self::$contexts = [];
    }

    /** @param list<RecordedTrial> $recorded */
    private static function writeBenchmark(RunPaths $paths, string $benchmark, array $recorded): Scorecard
    {
        $scorecardId = EvidenceId::generate('sc');
        $executionId = EvidenceId::generate('exec');
        $trials = [];
        $replay = [];

        foreach ($recorded as $record) {
            $trialId = EvidenceId::generate('trial');
            $measurementFingerprint = 'sha256:'.hash('sha256', $record->fingerprint.'|target');
            $trials[] = new Trial(
                id: $trialId,
                caseId: $record->caseId,
                configuration: $record->configuration,
                repeat: $record->repeat,
                fingerprint: $record->fingerprint,
                results: [
                    new Result(
                        id: EvidenceId::generate('res'),
                        scorer: 'pest:test',
                        score: $record->passed ? 1.0 : 0.0,
                        reasoning: null,
                        passed: $record->passed,
                        measurements: [
                            new Measurement(
                                component: Component::Target,
                                mode: ExecutionMode::Simulated,
                                requestedProvider: $record->identity->requestedProvider,
                                requestedModel: $record->identity->requestedModel,
                                effectiveProvider: $record->identity->effectiveProvider,
                                effectiveModel: $record->identity->effectiveModel,
                                latencyMs: $record->latencyMs,
                                usage: [],
                                retries: 0,
                                pricingCompleteness: PricingCompleteness::Unavailable,
                                pricingSnapshot: [],
                                fingerprint: $measurementFingerprint,
                            ),
                        ],
                    ),
                ],
            );
            $replay[] = [
                'trial_id' => $trialId->value,
                'fingerprint' => $record->fingerprint,
                'output' => $record->output,
            ];
        }

        $scorecard = new Scorecard(
            id: $scorecardId,
            executionId: $executionId,
            benchmark: $benchmark,
            createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            trials: $trials,
            context: self::$contexts[$benchmark] ?? null,
        );
        $runId = new RunId(gmdate('Ymd\THis\Z').'-'.substr($executionId->value, 5, 12));

        (new RunBundle($paths, $runId))->write($scorecard, new ReplayPayload($replay));

        return $scorecard;
    }

    private static function encoded(mixed $value): string
    {
        return json_encode(self::jsonSafe($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function jsonSafe(mixed $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            return (string) $value;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $safe = [];

            foreach ($value as $key => $nested) {
                $safe[$key] = self::jsonSafe($nested);
            }

            return $safe;
        }

        if ($value instanceof \JsonSerializable) {
            return self::jsonSafe($value->jsonSerialize());
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return self::jsonSafe($value->toArray());
        }

        return ['type' => get_debug_type($value)];
    }

    private static function stableCaseValue(mixed $value): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Benchmark case arguments must contain finite numeric values.');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $stable = [];

            foreach ($value as $key => $nested) {
                $stable[$key] = self::stableCaseValue($nested);
            }

            return $stable;
        }

        if ($value instanceof \JsonSerializable) {
            return self::stableCaseValue($value->jsonSerialize());
        }

        throw new InvalidArgumentException(sprintf(
            'Benchmark case arguments must be stable JSON values; [%s] is unsupported.',
            get_debug_type($value),
        ));
    }
}
