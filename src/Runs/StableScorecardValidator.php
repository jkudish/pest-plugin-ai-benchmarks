<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Runs;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;
use Jkudish\PestAiBenchmarks\Scorecards\Measurement;
use Jkudish\PestAiBenchmarks\Scorecards\NormalizedObject;
use Jkudish\PestAiBenchmarks\Scorecards\Result;
use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use Jkudish\PestAiBenchmarks\Scorecards\Trial;
use JsonException;
use RuntimeException;

/** @internal */
final class StableScorecardValidator
{
    /** @param array<string, mixed> $scorecard */
    public static function assert(array $scorecard): void
    {
        self::keys(
            $scorecard,
            ['schema_url', 'schema_version', 'package', 'scorecard_id', 'execution_id', 'benchmark', 'created_at', 'trials'],
            ['context'],
            'scorecard',
        );

        if (($scorecard['schema_url'] ?? null) !== Scorecard::SCHEMA_URL
            || ($scorecard['schema_version'] ?? null) !== Scorecard::SCHEMA_VERSION) {
            throw new RuntimeException('Only the current stable scorecard schema may be promoted as a baseline.');
        }

        $package = $scorecard['package'] ?? null;

        if (! is_array($package)) {
            throw new RuntimeException('Stable scorecard package identity is invalid.');
        }

        self::keys($package, ['name', 'version'], [], 'scorecard package');

        if (($package['name'] ?? null) !== Scorecard::PACKAGE_NAME) {
            throw new RuntimeException('Stable scorecard package identity is invalid.');
        }

        self::string($package['version'] ?? null, 'scorecard package version', 1, Scorecard::MAX_PACKAGE_VERSION_BYTES);
        $scorecardId = self::id($scorecard['scorecard_id'] ?? null, 'sc', 'scorecard ID');
        $executionId = self::id($scorecard['execution_id'] ?? null, 'exec', 'execution ID');
        self::string($scorecard['benchmark'] ?? null, 'benchmark', 1, Scorecard::MAX_BENCHMARK_BYTES);
        self::createdAt($scorecard['created_at'] ?? null);

        $trials = $scorecard['trials'] ?? null;

        if (! is_array($trials) || ! array_is_list($trials) || $trials === [] || count($trials) > Scorecard::MAX_TRIALS) {
            throw new RuntimeException('Stable scorecard trials must be a non-empty bounded list.');
        }

        $trialIds = [];
        $resultIds = [];
        $trialIdentities = [];

        foreach ($trials as $trial) {
            if (! is_array($trial)) {
                throw new RuntimeException('Stable scorecard contains invalid trial evidence.');
            }

            $trialId = self::trial($trial, $scorecardId, $executionId, $resultIds);

            if (isset($trialIds[$trialId])) {
                throw new RuntimeException('Stable scorecard contains duplicate trial identities.');
            }

            $trialIds[$trialId] = true;
            $caseId = self::string($trial['case_id'] ?? null, 'trial case ID');
            $configuration = self::string($trial['configuration'] ?? null, 'trial configuration');
            $repeat = $trial['repeat'] ?? null;

            if (! is_int($repeat)) {
                throw new RuntimeException('Stable scorecard trial repeat must be a positive integer.');
            }

            $identity = $caseId."\0".$configuration."\0".$repeat;

            if (isset($trialIdentities[$identity])) {
                throw new RuntimeException('Stable scorecard contains duplicate trial identities.');
            }

            $trialIdentities[$identity] = true;
        }

        if (array_key_exists('context', $scorecard)) {
            $context = $scorecard['context'];

            if (! is_array($context)) {
                throw new RuntimeException('Stable scorecard context must be a JSON object.');
            }

            self::jsonObject(
                $context,
                'scorecard context',
                OpaqueContext::MAX_DEPTH,
                OpaqueContext::MAX_KEYS,
                OpaqueContext::MAX_STRING_BYTES,
                OpaqueContext::MAX_BYTES,
            );

            foreach ($context as $key => $_) {
                if (! is_string($key) || $key === '') {
                    throw new RuntimeException('Stable scorecard context keys must be non-empty strings.');
                }
            }
        }

        try {
            $encoded = json_encode($scorecard, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stable scorecard is not JSON-safe.', previous: $exception);
        }

        if (strlen($encoded) > Scorecard::MAX_JSON_BYTES) {
            throw new RuntimeException('Stable scorecard exceeds the maximum encoded size.');
        }
    }

    /**
     * @param  array<mixed, mixed>  $trial
     * @param  array<string, true>  $resultIds
     */
    private static function trial(array $trial, mixed $scorecardId, mixed $executionId, array &$resultIds): string
    {
        self::keys($trial, ['trial_id', 'source', 'case_id', 'configuration', 'repeat', 'fingerprint', 'results'], [], 'trial');
        $trialId = self::id($trial['trial_id'] ?? null, 'trial', 'trial ID');
        self::source($trial['source'] ?? null, ['scorecard_id' => $scorecardId, 'execution_id' => $executionId], 'trial source');
        self::string($trial['case_id'] ?? null, 'trial case ID', 1, Trial::MAX_IDENTITY_BYTES);
        self::string($trial['configuration'] ?? null, 'trial configuration', 1, Trial::MAX_IDENTITY_BYTES);

        if (! is_int($trial['repeat'] ?? null) || $trial['repeat'] < 1) {
            throw new RuntimeException('Stable scorecard trial repeat must be a positive integer.');
        }

        self::string($trial['fingerprint'] ?? null, 'trial fingerprint');
        $results = $trial['results'] ?? null;

        if (! is_array($results)
            || ! array_is_list($results)
            || $results === []
            || count($results) > Trial::MAX_RESULTS) {
            throw new RuntimeException('Stable scorecard trial results must be a non-empty list.');
        }

        foreach ($results as $result) {
            if (! is_array($result)) {
                throw new RuntimeException('Stable scorecard contains invalid result evidence.');
            }

            $resultId = self::result($result, $scorecardId, $executionId, $trialId);

            if (isset($resultIds[$resultId])) {
                throw new RuntimeException('Stable scorecard contains duplicate result identities.');
            }

            $resultIds[$resultId] = true;
        }

        $primary = $results[0];
        $primaryMeasurements = $primary['measurements'] ?? null;

        if (($primary['scorer'] ?? null) !== 'pest:test'
            || ! is_array($primaryMeasurements)
            || ! array_any(
                $primaryMeasurements,
                static fn (mixed $measurement): bool => is_array($measurement)
                    && ($measurement['component'] ?? null) === 'target',
            )) {
            throw new RuntimeException('Stable scorecard trial must begin with target evidence from the Pest test result.');
        }

        return $trialId;
    }

    /** @param array<mixed, mixed> $result */
    private static function result(array $result, mixed $scorecardId, mixed $executionId, string $trialId): string
    {
        self::keys(
            $result,
            ['result_id', 'source', 'scorer', 'score', 'reasoning', 'threshold', 'passed', 'sample', 'samples', 'measurements'],
            [],
            'result',
        );
        $resultId = self::id($result['result_id'] ?? null, 'res', 'result ID');
        self::source(
            $result['source'] ?? null,
            ['scorecard_id' => $scorecardId, 'execution_id' => $executionId, 'trial_id' => $trialId],
            'result source',
        );
        self::string($result['scorer'] ?? null, 'result scorer', 1, Result::MAX_SCORER_BYTES);
        self::boundedNumber($result['score'] ?? null, 'result score', 0, 1, true);
        self::nullableString($result['reasoning'] ?? null, 'result reasoning', Result::MAX_REASONING_BYTES);
        self::boundedNumber($result['threshold'] ?? null, 'result threshold', 0, 1, true);

        if (! is_bool($result['passed'] ?? null) && $result['passed'] !== null) {
            throw new RuntimeException('Stable scorecard result passed must be a boolean or null.');
        }

        $sample = $result['sample'] ?? null;
        $samples = $result['samples'] ?? null;

        if (($sample !== null && (! is_int($sample) || $sample < 1))
            || ($samples !== null && (! is_int($samples) || $samples < 1))
            || (($sample === null) !== ($samples === null))
            || ($sample !== null && $sample > $samples)) {
            throw new RuntimeException('Stable scorecard result sample position and count are invalid.');
        }

        $measurements = $result['measurements'] ?? null;

        if (! is_array($measurements) || ! array_is_list($measurements) || $measurements === []) {
            throw new RuntimeException('Stable scorecard result measurements must be a non-empty list.');
        }

        foreach ($measurements as $measurement) {
            if (! is_array($measurement)) {
                throw new RuntimeException('Stable scorecard contains invalid measurement evidence.');
            }

            self::measurement($measurement);
        }

        return $resultId;
    }

    /** @param array<mixed, mixed> $measurement */
    private static function measurement(array $measurement): void
    {
        self::keys(
            $measurement,
            ['component', 'mode', 'requested_model', 'effective_model', 'latency_ms', 'usage', 'retries', 'pricing', 'fingerprint'],
            [],
            'measurement',
        );

        if (! in_array($measurement['component'] ?? null, ['target', 'judge'], true)) {
            throw new RuntimeException('Stable scorecard measurement component is invalid.');
        }

        if (! in_array($measurement['mode'] ?? null, ['live', 'recorded', 'simulated'], true)) {
            throw new RuntimeException('Stable scorecard measurement mode is invalid.');
        }

        self::model($measurement['requested_model'] ?? null, 'requested model');
        self::model($measurement['effective_model'] ?? null, 'effective model');
        self::boundedNumber($measurement['latency_ms'] ?? null, 'measurement latency', 0, null);

        if (! is_array($measurement['usage'] ?? null)) {
            throw new RuntimeException('Stable scorecard measurement usage must be a JSON object.');
        }

        self::jsonObject(
            $measurement['usage'],
            'measurement usage',
            NormalizedObject::MAX_DEPTH,
            NormalizedObject::MAX_ENTRIES,
            NormalizedObject::MAX_STRING_BYTES,
            NormalizedObject::MAX_BYTES,
        );

        if (! is_int($measurement['retries'] ?? null) || $measurement['retries'] < 0) {
            throw new RuntimeException('Stable scorecard measurement retries must be a non-negative integer.');
        }

        $pricing = $measurement['pricing'] ?? null;

        if (! is_array($pricing)) {
            throw new RuntimeException('Stable scorecard measurement pricing is invalid.');
        }

        self::keys($pricing, ['completeness', 'snapshot'], [], 'measurement pricing');

        if (! in_array($pricing['completeness'] ?? null, ['complete', 'partial', 'unavailable'], true)
            || ! is_array($pricing['snapshot'])) {
            throw new RuntimeException('Stable scorecard measurement pricing is invalid.');
        }

        self::jsonObject(
            $pricing['snapshot'],
            'measurement pricing snapshot',
            NormalizedObject::MAX_DEPTH,
            NormalizedObject::MAX_ENTRIES,
            NormalizedObject::MAX_STRING_BYTES,
            NormalizedObject::MAX_BYTES,
        );
        self::string($measurement['fingerprint'] ?? null, 'measurement fingerprint');
    }

    /** @param array<string, mixed> $expected */
    private static function source(mixed $source, array $expected, string $label): void
    {
        if (! is_array($source)) {
            throw new RuntimeException("Stable scorecard {$label} is invalid.");
        }

        self::keys($source, array_keys($expected), [], $label);

        foreach ($expected as $key => $value) {
            if (($source[$key] ?? null) !== $value) {
                throw new RuntimeException("Stable scorecard {$label} does not match its parent identity.");
            }
        }
    }

    private static function model(mixed $model, string $label): void
    {
        if ($model === null) {
            return;
        }

        if (! is_array($model)) {
            throw new RuntimeException("Stable scorecard {$label} identity is invalid.");
        }

        self::keys($model, ['provider', 'model'], [], $label);
        self::string($model['provider'] ?? null, "{$label} provider", 1, Measurement::MAX_IDENTITY_BYTES);
        self::string($model['model'] ?? null, "{$label} model", 1, Measurement::MAX_IDENTITY_BYTES);
    }

    private static function id(mixed $value, string $prefix, string $label): string
    {
        if (! is_string($value)) {
            throw new RuntimeException("Stable scorecard {$label} is invalid.");
        }

        try {
            return EvidenceId::from($value, $prefix)->value;
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("Stable scorecard {$label} is invalid.", previous: $exception);
        }
    }

    private static function createdAt(mixed $value): void
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            throw new RuntimeException('Stable scorecard created_at is invalid.');
        }

        $createdAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($createdAt === false
            || is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            || $createdAt->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('Stable scorecard created_at is invalid.');
        }
    }

    private static function string(mixed $value, string $label, int $minimum = 1, ?int $maximum = null): string
    {
        if (! is_string($value) || strlen($value) < $minimum || $maximum !== null && strlen($value) > $maximum || trim($value) === '') {
            throw new RuntimeException("Stable scorecard {$label} is invalid.");
        }

        return $value;
    }

    private static function nullableString(mixed $value, string $label, int $maximum): void
    {
        if ($value !== null) {
            if (! is_string($value) || strlen($value) > $maximum) {
                throw new RuntimeException("Stable scorecard {$label} is invalid.");
            }
        }
    }

    private static function boundedNumber(mixed $value, string $label, ?float $minimum, ?float $maximum, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }

        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
            || $minimum !== null && $value < $minimum
            || $maximum !== null && $value > $maximum) {
            throw new RuntimeException("Stable scorecard {$label} is invalid.");
        }
    }

    /** @param array<mixed> $value */
    private static function jsonObject(
        array $value,
        string $label,
        int $maxDepth,
        int $maxEntries,
        int $maxStringBytes,
        int $maxBytes,
    ): void {
        if ($value !== [] && array_is_list($value)) {
            throw new RuntimeException("Stable scorecard {$label} must be a JSON object.");
        }

        $entries = 0;
        self::jsonValue($value, $label, 1, $entries, $maxDepth, $maxEntries, $maxStringBytes);

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException("Stable scorecard {$label} is not JSON-safe.", previous: $exception);
        }

        if (strlen($encoded) > $maxBytes) {
            throw new RuntimeException("Stable scorecard {$label} exceeds its maximum encoded size.");
        }
    }

    private static function jsonValue(
        mixed $value,
        string $label,
        int $depth,
        int &$entries,
        int $maxDepth,
        int $maxEntries,
        int $maxStringBytes,
    ): void {
        if (is_float($value) && ! is_finite($value)) {
            throw new RuntimeException("Stable scorecard {$label} contains a non-finite number.");
        }

        if (is_string($value)) {
            if (strlen($value) > $maxStringBytes) {
                throw new RuntimeException("Stable scorecard {$label} contains an oversized string.");
            }

            return;
        }

        if (is_scalar($value) || $value === null) {
            return;
        }

        if (! is_array($value) || $depth > $maxDepth) {
            throw new RuntimeException("Stable scorecard {$label} contains an invalid JSON value.");
        }

        $list = array_is_list($value);

        foreach ($value as $key => $item) {
            if (! $list && (! is_string($key) || $key === '' || strlen($key) > $maxStringBytes)) {
                throw new RuntimeException("Stable scorecard {$label} contains an invalid object key.");
            }

            $entries++;

            if ($entries > $maxEntries) {
                throw new RuntimeException("Stable scorecard {$label} contains too many entries.");
            }

            self::jsonValue($item, $label, $depth + 1, $entries, $maxDepth, $maxEntries, $maxStringBytes);
        }
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private static function keys(array $value, array $required, array $optional, string $label): void
    {
        $allowed = array_merge($required, $optional);

        foreach ($value as $key => $_) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new RuntimeException("Stable scorecard {$label} has an invalid field set.");
            }
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                throw new RuntimeException("Stable scorecard {$label} has an invalid field set.");
            }
        }
    }
}
