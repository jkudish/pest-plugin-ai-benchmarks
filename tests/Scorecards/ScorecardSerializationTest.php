<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;
use Jkudish\PestAiBenchmarks\Scorecards\Measurement;
use Jkudish\PestAiBenchmarks\Scorecards\PricingCompleteness;
use Jkudish\PestAiBenchmarks\Scorecards\Result;
use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use Jkudish\PestAiBenchmarks\Scorecards\Trial;

function scorecardFixture(?OpaqueContext $context = null): Scorecard
{
    return new Scorecard(
        id: EvidenceId::from('sc_01JTESTSCORECARD', 'sc'),
        executionId: EvidenceId::from('exec_01JTESTEXECUTION', 'exec'),
        benchmark: 'receipt OCR',
        createdAt: new DateTimeImmutable('2026-08-07T13:30:00-07:00'),
        trials: [
            new Trial(
                id: EvidenceId::from('trial_01JTESTTRIAL', 'trial'),
                caseId: 'uber-complex-001',
                configuration: 'gemini-flash',
                repeat: 1,
                fingerprint: 'sha256:trial-fixture',
                results: [
                    new Result(
                        id: EvidenceId::from('res_01JTESTRESULT', 'res'),
                        scorer: 'receipt-fields',
                        score: 0.95,
                        reasoning: 'All required fields matched.',
                        passed: true,
                        measurements: [
                            new Measurement(
                                component: Component::Target,
                                mode: ExecutionMode::Live,
                                requestedProvider: 'openrouter',
                                requestedModel: 'google/gemini-2.5-flash',
                                effectiveProvider: 'google',
                                effectiveModel: 'gemini-2.5-flash-001',
                                latencyMs: 812.4,
                                usage: ['output_tokens' => 200, 'input_tokens' => 1000],
                                retries: 1,
                                pricingCompleteness: PricingCompleteness::Complete,
                                pricingSnapshot: [
                                    'source' => 'provider_native',
                                    'currency' => 'USD',
                                    'cost' => '0.000800',
                                ],
                                fingerprint: 'sha256:target-fixture',
                            ),
                            new Measurement(
                                component: Component::Judge,
                                mode: ExecutionMode::Recorded,
                                requestedProvider: null,
                                requestedModel: null,
                                effectiveProvider: null,
                                effectiveModel: null,
                                latencyMs: 0,
                                usage: [],
                                retries: 0,
                                pricingCompleteness: PricingCompleteness::Unavailable,
                                pricingSnapshot: [],
                                fingerprint: 'sha256:judge-fixture',
                            ),
                        ],
                    ),
                ],
            ),
        ],
        packageVersion: '0.1.0',
        context: $context,
    );
}

it('serializes the stable scorecard contract exactly', function (): void {
    $scorecard = scorecardFixture(new OpaqueContext([
        'work_ref' => 'task-123',
        'trace_id' => 'trace-456',
    ]));

    $golden = file_get_contents(__DIR__.'/Fixtures/scorecard.v0.1.json');

    expect($golden)->not->toBeFalse()
        ->and($scorecard->toJson())->toBe($golden);
});

it('derives source relationships and omits optional context by default', function (): void {
    $scorecard = scorecardFixture();
    $serialized = $scorecard->toArray();
    $trial = $serialized['trials'][0];
    $result = $trial['results'][0];

    expect(array_key_exists('context', $serialized))->toBeFalse()
        ->and($trial['source'])->toBe([
            'scorecard_id' => $serialized['scorecard_id'],
            'execution_id' => $serialized['execution_id'],
        ])
        ->and($result['source'])->toBe([
            'scorecard_id' => $serialized['scorecard_id'],
            'execution_id' => $serialized['execution_id'],
            'trial_id' => $trial['trial_id'],
        ]);
});

it('keeps private raw evaluation material out of stable scorecards', function (): void {
    $json = scorecardFixture()->toJson();

    expect(str_contains($json, '"prompt":'))->toBeFalse()
        ->and(str_contains($json, '"input":'))->toBeFalse()
        ->and(str_contains($json, '"output":'))->toBeFalse()
        ->and(str_contains($json, '"expected":'))->toBeFalse();
});

it('bundles a JSON Schema 2020-12 contract matching the serializer version', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/resources/schema/scorecard.schema.json');

    expect($contents === false)->toBeFalse();

    $schema = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['$id'])->toBe(Scorecard::SCHEMA_URL)
        ->and($schema['properties']['schema_url']['const'])->toBe(Scorecard::SCHEMA_URL)
        ->and($schema['properties']['schema_version']['const'])->toBe(Scorecard::SCHEMA_VERSION)
        ->and($schema['properties']['context']['maxProperties'])->toBe(OpaqueContext::MAX_KEYS)
        ->and($schema['additionalProperties'])->toBeFalse();
});
