<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\PestAiBenchmarks\Comparisons\BenchmarkDeclaration;
use Jkudish\PestAiBenchmarks\Comparisons\GateEvaluator;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionEvaluation;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionEvaluator;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionPolicy;
use Jkudish\PestAiBenchmarks\Comparisons\RegressionStatus;
use Jkudish\PestAiBenchmarks\Comparisons\ScorecardEvidence;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Evidence\PestEvalObservation;
use Jkudish\PestAiBenchmarks\LaravelAi\AgentObservation;
use Jkudish\PestAiBenchmarks\Measurements\LaravelAiPricingAdapter;
use Jkudish\PestAiBenchmarks\Measurements\PricingInput;
use Jkudish\PestAiBenchmarks\ModelIdentityEvidence;
use Jkudish\PestAiBenchmarks\Plugin;
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
use RuntimeException;
use Throwable;

/** @internal */
final class ExecutionRecorder
{
    /** @var list<RecordedTrial> */
    private static array $trials = [];

    /** @var array<string, int> */
    private static array $repeats = [];

    /** @var array<string, OpaqueContext|null> */
    private static array $contexts = [];

    /** @var array<string, BenchmarkDeclaration> */
    private static array $declarations = [];

    /** @var array<string, RegressionEvaluation> */
    private static array $evaluations = [];

    /** @var array<string, string> */
    private static array $references = [];

    /** @var array<string, array<string, RegressionEvaluation>> */
    private static array $referenceEvaluations = [];

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

    /**
     * @param  array{file: string, start_line: int, end_line: int, source_sha256: string}  $targetIdentity
     * @param  array{file: string, start_line: int, end_line: int, source_sha256: string}|null  $evaluationIdentity
     */
    public static function trialFingerprint(
        string $benchmark,
        string $caseId,
        string $configurationName,
        Configuration $configuration,
        array $targetIdentity,
        ?array $evaluationIdentity,
    ): string {
        return 'sha256:'.hash('sha256', self::encoded([
            'benchmark' => $benchmark,
            'target' => $targetIdentity,
            'evaluation' => $evaluationIdentity,
            'case_id' => $caseId,
            'configuration' => [
                'name' => $configurationName,
                'provider' => $configuration->provider,
                'model' => $configuration->model,
                'options' => $configuration->options,
                'settings' => $configuration->settings,
            ],
            'schema_version' => Scorecard::SCHEMA_VERSION,
            'package' => [
                'name' => Scorecard::PACKAGE_NAME,
                'version' => '0.1.0-dev',
            ],
        ]));
    }

    /**
     * @param  array{file: string, start_line: int, end_line: int, source_sha256: string}  $targetIdentity
     * @param  list<AgentObservation>  $observations
     * @param  list<PestEvalObservation>  $scorerObservations
     * @param  array{file: string, start_line: int, end_line: int, source_sha256: string}|null  $evaluationIdentity
     */
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
        array $observations = [],
        array $scorerObservations = [],
        ?int $repeat = null,
        ?string $fingerprint = null,
        ?BenchmarkDeclaration $declaration = null,
        ?array $evaluationIdentity = null,
    ): void {
        $fingerprint ??= self::trialFingerprint(
            $benchmark,
            $caseId,
            $configurationName,
            $configuration,
            $targetIdentity,
            $evaluationIdentity,
        );
        if ($repeat === null) {
            $repeatKey = $benchmark."\0".$caseId."\0".$configurationName;
            $repeat = (self::$repeats[$repeatKey] ?? 0) + 1;
            self::$repeats[$repeatKey] = $repeat;
        }

        if ($repeat < 1) {
            throw new InvalidArgumentException('Trial repeat must be at least 1.');
        }
        self::$contexts[$benchmark] = $context;
        if ($declaration instanceof BenchmarkDeclaration) {
            self::$declarations[$benchmark] = $declaration;
        }
        $pricingQuotes = array_map(self::quote(...), $observations);

        self::$trials[] = new RecordedTrial(
            benchmark: $benchmark,
            caseId: $caseId,
            configuration: $configurationName,
            repeat: $repeat,
            fingerprint: $fingerprint,
            identity: $identity,
            latencyMs: $latencyMs,
            passed: $passed,
            output: self::jsonSafe($output ?? self::scorerOutput($scorerObservations)),
            observations: $observations,
            pricingQuotes: $pricingQuotes,
            scorerObservations: $scorerObservations,
        );
    }

    /**
     * @param  array<string, mixed>  $trial
     */
    public static function reuse(
        string $benchmark,
        array $trial,
        mixed $output,
        ?BenchmarkDeclaration $declaration = null,
    ): void {
        $caseId = $trial['case_id'] ?? null;
        $configuration = $trial['configuration'] ?? null;
        $repeat = $trial['repeat'] ?? null;
        $fingerprint = $trial['fingerprint'] ?? null;
        $results = $trial['results'] ?? null;

        if (! is_string($caseId)
            || ! is_string($configuration)
            || ! is_int($repeat)
            || ! is_string($fingerprint)
            || ! is_array($results)) {
            throw new RuntimeException('Completed trial contains invalid reusable evidence.');
        }

        self::$contexts[$benchmark] = $declaration?->context;
        if ($declaration instanceof BenchmarkDeclaration) {
            self::$declarations[$benchmark] = $declaration;
        }

        self::$trials[] = new RecordedTrial(
            benchmark: $benchmark,
            caseId: $caseId,
            configuration: $configuration,
            repeat: $repeat,
            fingerprint: $fingerprint,
            identity: new ModelIdentityEvidence(null, null, null, null),
            latencyMs: 0.0,
            passed: true,
            output: self::jsonSafe($output),
            stableResults: self::stableRecords($results, 'Completed trial contains invalid result evidence.'),
        );
    }

    /**
     * @param  array<string, mixed>  $sourceTrial
     * @param  list<PestEvalObservation>  $scorerObservations
     */
    public static function recordReplay(
        string $benchmark,
        string $caseId,
        string $configuration,
        int $repeat,
        string $fingerprint,
        mixed $output,
        array $sourceTrial,
        array $scorerObservations,
        BenchmarkDeclaration $declaration,
    ): void {
        $results = $sourceTrial['results'] ?? null;

        if (! is_array($results) || ! is_array($results[0] ?? null) || ! is_array($results[0]['measurements'] ?? null)) {
            throw new RuntimeException('Replay trial contains invalid target measurements.');
        }

        self::$contexts[$benchmark] = $declaration->context;
        self::$declarations[$benchmark] = $declaration;
        self::$trials[] = new RecordedTrial(
            benchmark: $benchmark,
            caseId: $caseId,
            configuration: $configuration,
            repeat: $repeat,
            fingerprint: $fingerprint,
            identity: new ModelIdentityEvidence(null, null, null, null),
            latencyMs: 0.0,
            passed: true,
            output: self::jsonSafe($output),
            scorerObservations: $scorerObservations,
            sourceMeasurements: self::stableRecords($results[0]['measurements'], 'Replay trial contains invalid target measurements.'),
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

        self::resetActive();

        return $scorecards;
    }

    public static function reset(): void
    {
        self::resetActive();
        self::$evaluations = [];
        self::$references = [];
        self::$referenceEvaluations = [];
    }

    public static function evaluation(Scorecard $scorecard): ?RegressionEvaluation
    {
        return self::$evaluations[$scorecard->id->value] ?? null;
    }

    public static function reference(Scorecard $scorecard): ?string
    {
        return self::$references[$scorecard->id->value] ?? null;
    }

    /** @return array<string, RegressionEvaluation> */
    public static function referenceEvaluations(Scorecard $scorecard): array
    {
        return self::$referenceEvaluations[$scorecard->id->value] ?? [];
    }

    private static function resetActive(): void
    {
        self::$trials = [];
        self::$repeats = [];
        self::$contexts = [];
        self::$declarations = [];
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
            $trials[] = new Trial(
                id: $trialId,
                caseId: $record->caseId,
                configuration: $record->configuration,
                repeat: $record->repeat,
                fingerprint: $record->fingerprint,
                results: self::results($record),
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

        $declaration = self::$declarations[$benchmark] ?? new BenchmarkDeclaration;
        self::$evaluations[$scorecard->id->value] = (new GateEvaluator)->evaluate(
            current: $scorecard,
            declaration: $declaration,
            baselineName: Plugin::baselineName(),
            paths: $paths,
        );

        if ($declaration->reference !== null) {
            self::$references[$scorecard->id->value] = $declaration->reference;
            self::$referenceEvaluations[$scorecard->id->value] = self::evaluateReferences($scorecard, $declaration);
        }

        return $scorecard;
    }

    /** @return array<string, RegressionEvaluation> */
    private static function evaluateReferences(Scorecard $scorecard, BenchmarkDeclaration $declaration): array
    {
        if ($declaration->reference === null) {
            return [];
        }

        $aggregator = new ScorecardEvidence;
        $policy = $declaration->regressionPolicy ?? RegressionPolicy::evidenceOnly();
        $scorecardData = $scorecard->toArray();
        $evaluations = [];

        try {
            $reference = $aggregator->aggregate($scorecardData, $declaration->reference);
        } catch (Throwable $exception) {
            return ['reference' => new RegressionEvaluation(
                status: RegressionStatus::NotEvaluable,
                failures: [$exception->getMessage()],
            )];
        }

        foreach ($declaration->configurations as $configuration) {
            if ($configuration === $declaration->reference) {
                continue;
            }

            try {
                $evaluations[$configuration] = (new RegressionEvaluator)->evaluate(
                    current: $aggregator->aggregate($scorecardData, $configuration),
                    historical: $reference,
                    policy: $policy,
                );
            } catch (Throwable $exception) {
                $evaluations[$configuration] = new RegressionEvaluation(
                    status: RegressionStatus::NotEvaluable,
                    failures: [$exception->getMessage()],
                );
            }
        }

        return $evaluations;
    }

    /** @return list<Result> */
    private static function results(RecordedTrial $record): array
    {
        if ($record->stableResults !== null) {
            return self::resultsFromStable($record->stableResults);
        }

        $measurements = self::measurements($record);
        $results = [new Result(
            id: EvidenceId::generate('res'),
            scorer: 'pest:test',
            score: $record->passed ? 1.0 : 0.0,
            reasoning: null,
            passed: $record->passed,
            measurements: $measurements,
            threshold: null,
            sample: null,
            samples: null,
        )];

        foreach ($record->scorerObservations as $observation) {
            $results[] = new Result(
                id: EvidenceId::generate('res'),
                scorer: $observation->scorer,
                score: $observation->score,
                reasoning: $observation->reasoning,
                passed: $observation->passed,
                measurements: $measurements,
                threshold: $observation->threshold,
                sample: $observation->sample,
                samples: $observation->samples,
            );
        }

        return $results;
    }

    /** @return list<Measurement> */
    private static function measurements(RecordedTrial $record): array
    {
        if ($record->sourceMeasurements !== null) {
            return self::measurementsFromStable($record->sourceMeasurements, $record->fingerprint, true);
        }

        if ($record->observations === []) {
            return [new Measurement(
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
                fingerprint: self::measurementFingerprint($record, 0, null),
            )];
        }

        $measurements = [];

        foreach ($record->observations as $index => $observation) {
            $quote = $record->pricingQuotes[$index] ?? CostQuote::unavailable();

            $measurements[] = new Measurement(
                component: Component::Target,
                mode: $observation->mode,
                requestedProvider: $observation->requestedProvider,
                requestedModel: $observation->requestedModel,
                effectiveProvider: $observation->effectiveProvider,
                effectiveModel: $observation->effectiveModel,
                latencyMs: $observation->latencyMs,
                usage: $observation->usage->toArray(),
                retries: $index,
                pricingCompleteness: PricingCompleteness::from($quote->completeness->value),
                pricingSnapshot: $quote->toArray(),
                fingerprint: self::measurementFingerprint($record, $index, $observation),
            );
        }

        return $measurements;
    }

    /**
     * @param  list<array<string, mixed>>  $stableResults
     * @return list<Result>
     */
    private static function resultsFromStable(array $stableResults): array
    {
        $results = [];

        foreach ($stableResults as $result) {
            if (! is_string($result['scorer'] ?? null)
                || ! is_array($result['measurements'] ?? null)) {
                throw new RuntimeException('Completed trial contains invalid result evidence.');
            }

            $results[] = new Result(
                id: EvidenceId::generate('res'),
                scorer: $result['scorer'],
                score: is_float($result['score'] ?? null) || is_int($result['score'] ?? null) ? (float) $result['score'] : null,
                reasoning: is_string($result['reasoning'] ?? null) ? $result['reasoning'] : null,
                passed: is_bool($result['passed'] ?? null) ? $result['passed'] : null,
                measurements: self::measurementsFromStable(
                    self::stableRecords($result['measurements'], 'Completed trial contains invalid measurement evidence.'),
                    '',
                    false,
                ),
                threshold: is_float($result['threshold'] ?? null) || is_int($result['threshold'] ?? null) ? (float) $result['threshold'] : null,
                sample: is_int($result['sample'] ?? null) ? $result['sample'] : null,
                samples: is_int($result['samples'] ?? null) ? $result['samples'] : null,
            );
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $stableMeasurements
     * @return list<Measurement>
     */
    private static function measurementsFromStable(array $stableMeasurements, string $trialFingerprint, bool $recorded): array
    {
        $measurements = [];

        foreach ($stableMeasurements as $index => $measurement) {
            $requested = $measurement['requested_model'] ?? null;
            $effective = $measurement['effective_model'] ?? null;
            $pricing = $measurement['pricing'] ?? null;

            if (! is_string($measurement['component'] ?? null)
                || ! is_string($measurement['mode'] ?? null)
                || ! is_float($measurement['latency_ms'] ?? null) && ! is_int($measurement['latency_ms'] ?? null)
                || ! is_array($measurement['usage'] ?? null)
                || ! is_int($measurement['retries'] ?? null)
                || ! is_array($pricing)
                || ! is_string($pricing['completeness'] ?? null)
                || ! is_array($pricing['snapshot'] ?? null)
                || ! is_string($measurement['fingerprint'] ?? null)) {
                throw new RuntimeException('Saved trial contains invalid measurement evidence.');
            }

            $mode = $recorded ? ExecutionMode::Recorded : ExecutionMode::from($measurement['mode']);
            $fingerprint = $recorded
                ? 'sha256:'.hash('sha256', self::encoded([
                    'trial' => $trialFingerprint,
                    'component' => $measurement['component'],
                    'attempt' => $index,
                    'mode' => $mode->value,
                    'requested_model' => $requested,
                    'effective_model' => $effective,
                ]))
                : $measurement['fingerprint'];

            $measurements[] = new Measurement(
                component: Component::from($measurement['component']),
                mode: $mode,
                requestedProvider: is_array($requested) && is_string($requested['provider'] ?? null) ? $requested['provider'] : null,
                requestedModel: is_array($requested) && is_string($requested['model'] ?? null) ? $requested['model'] : null,
                effectiveProvider: is_array($effective) && is_string($effective['provider'] ?? null) ? $effective['provider'] : null,
                effectiveModel: is_array($effective) && is_string($effective['model'] ?? null) ? $effective['model'] : null,
                latencyMs: (float) $measurement['latency_ms'],
                usage: $measurement['usage'],
                retries: $measurement['retries'],
                pricingCompleteness: PricingCompleteness::from($pricing['completeness']),
                pricingSnapshot: $pricing['snapshot'],
                fingerprint: $fingerprint,
            );
        }

        if ($measurements === []) {
            throw new RuntimeException('Saved trial contains no target measurements.');
        }

        return $measurements;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<array<string, mixed>>
     */
    private static function stableRecords(array $values, string $message): array
    {
        $records = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                throw new RuntimeException($message);
            }

            $record = [];

            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $record[$key] = $item;
                }
            }

            $records[] = $record;
        }

        return $records;
    }

    private static function quote(AgentObservation $observation): CostQuote
    {
        if ($observation->mode !== ExecutionMode::Live
            || $observation->effectiveProvider === null
            || $observation->effectiveModel === null) {
            return CostQuote::unavailable();
        }

        try {
            $container = Container::getInstance();

            if (! $container->bound(CostResolver::class)) {
                return CostQuote::unavailable();
            }

            $resolver = $container->make(CostResolver::class);

            return (new LaravelAiPricingAdapter($resolver))->price(new PricingInput(
                model: new ModelIdentityEvidence(
                    requestedProvider: $observation->requestedProvider,
                    requestedModel: $observation->requestedModel,
                    effectiveProvider: $observation->effectiveProvider,
                    effectiveModel: $observation->effectiveModel,
                ),
                usage: $observation->usage,
            ));
        } catch (Throwable) {
            return CostQuote::unavailable();
        }
    }

    private static function measurementFingerprint(
        RecordedTrial $record,
        int $index,
        ?AgentObservation $observation,
    ): string {
        $requestedProvider = $record->identity->requestedProvider;
        $requestedModel = $record->identity->requestedModel;
        $effectiveProvider = $record->identity->effectiveProvider;
        $effectiveModel = $record->identity->effectiveModel;
        $succeeded = $record->passed;

        if ($observation instanceof AgentObservation) {
            $requestedProvider = $observation->requestedProvider;
            $requestedModel = $observation->requestedModel;
            $effectiveProvider = $observation->effectiveProvider;
            $effectiveModel = $observation->effectiveModel;
            $succeeded = $observation->succeeded;
        }

        return 'sha256:'.hash('sha256', self::encoded([
            'trial' => $record->fingerprint,
            'component' => Component::Target->value,
            'attempt' => $index,
            'mode' => $observation?->mode->value ?? ExecutionMode::Simulated->value,
            'requested_provider' => $requestedProvider,
            'requested_model' => $requestedModel,
            'effective_provider' => $effectiveProvider,
            'effective_model' => $effectiveModel,
            'succeeded' => $succeeded,
        ]));
    }

    private static function encoded(mixed $value): string
    {
        return json_encode(self::jsonSafe($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param list<PestEvalObservation> $observations */
    private static function scorerOutput(array $observations): ?string
    {
        $observation = end($observations);

        return $observation instanceof PestEvalObservation ? $observation->output : null;
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
