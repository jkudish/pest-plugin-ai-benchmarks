<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Reporters\ExecutionRecorder;
use Jkudish\PestAiBenchmarks\Reporters\JsonReporter;
use Jkudish\PestAiBenchmarks\Reporters\TerminalReporter;
use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Scorecards\Component;
use Jkudish\PestAiBenchmarks\Scorecards\ExecutionMode;
use Jkudish\PestAiBenchmarks\Scorecards\Measurement;
use Jkudish\PestAiBenchmarks\Scorecards\PricingCompleteness;
use Jkudish\PestAiBenchmarks\Scorecards\Result;
use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use Jkudish\PestAiBenchmarks\Scorecards\Trial;

function reporterScorecard(): Scorecard
{
    return new Scorecard(
        id: EvidenceId::from('sc_01JREPORTCARD', 'sc'),
        executionId: EvidenceId::from('exec_01JREPORTEXEC', 'exec'),
        benchmark: 'reporter benchmark',
        createdAt: new DateTimeImmutable('2026-08-07T20:00:00Z'),
        trials: [
            new Trial(
                id: EvidenceId::from('trial_01JREPORTTRIAL', 'trial'),
                caseId: 'case-1',
                configuration: 'production',
                repeat: 1,
                fingerprint: 'sha256:trial',
                results: [
                    new Result(
                        id: EvidenceId::from('res_01JREPORTRESULT', 'res'),
                        scorer: 'relevance',
                        score: 0.9,
                        reasoning: null,
                        passed: true,
                        measurements: [
                            new Measurement(
                                component: Component::Target,
                                mode: ExecutionMode::Simulated,
                                requestedProvider: null,
                                requestedModel: null,
                                effectiveProvider: null,
                                effectiveModel: null,
                                latencyMs: 25,
                                usage: [],
                                retries: 0,
                                pricingCompleteness: PricingCompleteness::Unavailable,
                                pricingSnapshot: [],
                                fingerprint: 'sha256:measurement',
                            ),
                        ],
                    ),
                ],
            ),
        ],
    );
}

it('renders JSON through the stable scorecard serializer', function (): void {
    $scorecard = reporterScorecard();

    expect((new JsonReporter)->render($scorecard))->toBe($scorecard->toJson());
});

it('renders a terminal summary without mutating execution evidence', function (): void {
    $scorecard = reporterScorecard();
    $before = $scorecard->toJson();
    $report = (new TerminalReporter)->render($scorecard);

    expect($report)->toContain('Benchmark: reporter benchmark')
        ->and($report)->toContain('Trials: 1 | Results: 1 | Passed: 1 | Failed: 0')
        ->and($report)->toContain('Measured latency: 25.00 ms')
        ->and($scorecard->toJson())->toBe($before);
});

it('counts repeated trial latency while deduplicating scorer measurements within each trial', function (): void {
    $first = reporterScorecard()->trials[0];
    $scorecard = new Scorecard(
        id: EvidenceId::from('sc_01JREPEATEDREPORT', 'sc'),
        executionId: EvidenceId::from('exec_01JREPEATEDEXEC', 'exec'),
        benchmark: 'repeated reporter benchmark',
        createdAt: new DateTimeImmutable('2026-08-09T00:00:00Z'),
        trials: [
            $first,
            new Trial(
                id: EvidenceId::from('trial_01JREPEATEDTRIAL', 'trial'),
                caseId: $first->caseId,
                configuration: $first->configuration,
                repeat: 2,
                fingerprint: $first->fingerprint,
                results: $first->results,
            ),
        ],
    );

    expect((new TerminalReporter)->render($scorecard))->toContain('Measured latency: 50.00 ms');
});

it('rejects case arguments without a stable serializable identity', function (): void {
    expect(fn (): string => ExecutionRecorder::caseId([new stdClass]))
        ->toThrow(InvalidArgumentException::class, 'must be stable JSON values; [stdClass] is unsupported');
});

it('fingerprints the benchmark target source identity', function (): void {
    $first = ExecutionRecorder::targetIdentity(fn (): string => 'first');
    $second = ExecutionRecorder::targetIdentity(fn (): string => 'second');

    expect($first)->toHaveKeys(['file', 'start_line', 'end_line', 'source_sha256'])
        ->and($first['file'])->toEndWith('tests/Reporters/ReporterTest.php')
        ->and($first['source_sha256'])->not->toBe($second['source_sha256']);
});
