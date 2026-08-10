<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Results\EvidenceId;
use Jkudish\PestAiBenchmarks\Runs\BaselineStore;
use Jkudish\PestAiBenchmarks\Runs\ReplayPayload;
use Jkudish\PestAiBenchmarks\Runs\ReplayReader;
use Jkudish\PestAiBenchmarks\Runs\ResumeReader;
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

function lifecyclePaths(): RunPaths
{
    return RunPaths::forProject(sys_get_temp_dir().'/pest-ai-runs-'.bin2hex(random_bytes(8)));
}

function lifecycleScorecard(
    string $caseId = 'case-1',
    string $configuration = 'production',
    string $fingerprint = 'sha256:trial-one',
    string $measurementFingerprint = 'sha256:measurement-one',
    ExecutionMode $mode = ExecutionMode::Live,
): Scorecard {
    return new Scorecard(
        id: EvidenceId::from('sc_01JRUNSCORECARD', 'sc'),
        executionId: EvidenceId::from('exec_01JRUNEXECUTION', 'exec'),
        benchmark: 'run lifecycle',
        createdAt: new DateTimeImmutable('2026-08-07T20:00:00Z'),
        trials: [
            new Trial(
                id: EvidenceId::from('trial_01JRUNTRIAL', 'trial'),
                caseId: $caseId,
                configuration: $configuration,
                repeat: 1,
                fingerprint: $fingerprint,
                results: [
                    new Result(
                        id: EvidenceId::from('res_01JRUNRESULT', 'res'),
                        scorer: 'pest:test',
                        score: 0.9,
                        reasoning: 'Relevant.',
                        passed: true,
                        measurements: [
                            new Measurement(
                                component: Component::Target,
                                mode: $mode,
                                requestedProvider: 'openrouter',
                                requestedModel: 'model/requested',
                                effectiveProvider: 'provider',
                                effectiveModel: 'model-effective',
                                latencyMs: 125.5,
                                usage: ['input_tokens' => 10, 'output_tokens' => 2],
                                retries: 0,
                                pricingCompleteness: PricingCompleteness::Complete,
                                pricingSnapshot: ['currency' => 'USD', 'cost' => '0.001'],
                                fingerprint: $measurementFingerprint,
                            ),
                        ],
                    ),
                ],
            ),
        ],
    );
}

function lifecycleReplay(): ReplayPayload
{
    return new ReplayPayload([
        [
            'trial_id' => 'trial_01JRUNTRIAL',
            'fingerprint' => 'sha256:trial-one',
            'output' => [
                'text' => 'private model output',
                'authorization' => 'Bearer secret',
            ],
        ],
    ]);
}

it('uses conventional local run and baseline paths', function (): void {
    $paths = RunPaths::forProject('/projects/example');

    expect($paths->runs)->toBe('/projects/example/storage/app/ai-evals/runs')
        ->and($paths->baselines)->toBe('/projects/example/tests/Evals/Baselines');
});

it('supports Windows drive and UNC project paths', function (): void {
    expect(RunPaths::forProject('C:\\projects\\example')->runs)
        ->toBe('C:\\projects\\example\\storage\\app\\ai-evals\\runs')
        ->and(RunPaths::forProject('C:\\projects/example')->runs)
        ->toBe('C:\\projects\\example\\storage\\app\\ai-evals\\runs')
        ->and(RunPaths::forProject('\\\\server\\share\\example')->baselines)
        ->toBe('\\\\server\\share\\example\\tests\\Evals\\Baselines');
});

it('rejects unsafe explicit run IDs', function (string $id): void {
    expect(fn (): RunId => new RunId($id))->toThrow(InvalidArgumentException::class);
})->with(['../escape', '/absolute', 'nested/path', '', '..']);

it('publishes a sanitized scorecard and private replay as one run bundle', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-001');

    (new RunBundle($paths, $runId))->write(lifecycleScorecard(), lifecycleReplay());

    $run = $paths->run($runId);
    $stable = file_get_contents($run.'/'.RunBundle::SCORECARD_FILE);
    $private = file_get_contents($run.'/'.RunBundle::REPLAY_FILE);

    expect($stable)->toBeString()
        ->and($private)->toBeString()
        ->and(str_contains((string) $stable, 'private model output'))->toBeFalse()
        ->and(str_contains((string) $private, 'private model output'))->toBeTrue()
        ->and(str_contains((string) $private, 'Bearer secret'))->toBeFalse()
        ->and(str_contains((string) $private, '[REDACTED]'))->toBeTrue();

    if (DIRECTORY_SEPARATOR === '/') {
        expect(fileperms($run.'/'.RunBundle::REPLAY_FILE) & 0777)->toBe(0600);
    }
});

it('exposes stored outputs only through the replay callback', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-002');
    $received = [];

    (new RunBundle($paths, $runId))->write(lifecycleScorecard(), lifecycleReplay());

    (new ReplayReader($paths))->replay($runId, function (string $trialId, mixed $output, string $fingerprint) use (&$received): void {
        $received[] = compact('trialId', 'output', 'fingerprint');
    });

    expect($received)->toBe([[
        'trialId' => 'trial_01JRUNTRIAL',
        'output' => ['text' => 'private model output', 'authorization' => '[REDACTED]'],
        'fingerprint' => 'sha256:trial-one',
    ]]);
});

it('resumes completed trials only when fingerprints match', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-003');
    $resume = new ResumeReader($paths);
    $reused = [];

    (new RunBundle($paths, $runId))->write(lifecycleScorecard(), lifecycleReplay());

    $matched = $resume->reuseCompletedTrial(
        $runId,
        'run lifecycle',
        'case-1',
        'production',
        1,
        'sha256:trial-one',
        function (array $trial, mixed $output) use (&$reused): void {
            $reused = compact('trial', 'output');
        },
    );

    expect($matched)->toBeTrue()
        ->and($reused['trial'])->toMatchArray(['case_id' => 'case-1', 'fingerprint' => 'sha256:trial-one'])
        ->and($reused['output'])->toBe(['text' => 'private model output', 'authorization' => '[REDACTED]'])
        ->and($resume->reuseCompletedTrial($runId, 'run lifecycle', 'missing', 'production', 1, 'sha256:trial-one', fn (): null => null))->toBeFalse()
        ->and(fn () => $resume->reuseCompletedTrial($runId, 'run lifecycle', 'case-1', 'production', 1, 'sha256:changed', fn (): null => null))
        ->toThrow(RuntimeException::class, 'fingerprint');
});

it('rejects replay reuse when private and stable fingerprints disagree', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-004');
    $mismatchedReplay = new ReplayPayload([
        [
            'trial_id' => 'trial_01JRUNTRIAL',
            'fingerprint' => 'sha256:other',
            'output' => 'stale output',
        ],
    ]);

    (new RunBundle($paths, $runId))->write(lifecycleScorecard(), $mismatchedReplay);

    expect(fn () => (new ResumeReader($paths))->reuseCompletedTrial(
        $runId,
        'run lifecycle',
        'case-1',
        'production',
        1,
        'sha256:trial-one',
        fn (): null => null,
    ))->toThrow(RuntimeException::class, 'Private replay fingerprint');
});

it('stores only stable scorecards as compatible baselines', function (): void {
    $paths = lifecyclePaths();
    $store = new BaselineStore($paths);
    $scorecard = lifecycleScorecard();

    $store->save('production', $scorecard);
    $baseline = $store->load('production');
    $baseline->assertCompatible($scorecard);

    $files = glob($paths->baselines.DIRECTORY_SEPARATOR.'*');
    $contents = file_get_contents($paths->baseline('production'));

    expect($files)->toBe([$paths->baseline('production')])
        ->and(str_contains((string) $contents, 'private model output'))->toBeFalse()
        ->and(fn () => $baseline->assertCompatible(lifecycleScorecard(caseId: 'different')))
        ->toThrow(RuntimeException::class, 'identical case IDs');
});

it('requires trial and measurement fingerprints to match before baseline comparison', function (): void {
    $paths = lifecyclePaths();
    $store = new BaselineStore($paths);

    $store->save('production', lifecycleScorecard());
    $baseline = $store->load('production');

    expect(fn () => $baseline->assertCompatible(lifecycleScorecard(fingerprint: 'sha256:changed-trial')))
        ->toThrow(RuntimeException::class, 'compatibility fingerprints')
        ->and(fn () => $baseline->assertCompatible(lifecycleScorecard(measurementFingerprint: 'sha256:changed-measurement')))
        ->toThrow(RuntimeException::class, 'compatibility fingerprints');
});

it('refuses to promote simulated evidence as a baseline', function (): void {
    $paths = lifecyclePaths();

    expect(fn () => (new BaselineStore($paths))->save(
        'simulated',
        lifecycleScorecard(mode: ExecutionMode::Simulated),
    ))->toThrow(RuntimeException::class, 'Simulated evidence');

    expect(is_dir($paths->baselines))->toBeFalse();
});

it('promotes a saved live run without copying private replay output', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-promote-live');
    $store = new BaselineStore($paths);

    (new RunBundle($paths, $runId))->write(lifecycleScorecard(), lifecycleReplay());
    $scorecardPath = $paths->run($runId).'/'.RunBundle::SCORECARD_FILE;
    $scorecard = json_decode((string) file_get_contents($scorecardPath), true, flags: JSON_THROW_ON_ERROR);
    $scorecard['context'] = ['private_input' => 'do-not-promote'];
    $scorecard['trials'][0]['results'][0]['reasoning'] = 'private scorer reasoning';
    file_put_contents($scorecardPath, json_encode($scorecard, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    $store->promote($runId, 'production');

    $baseline = (string) file_get_contents($paths->baseline('production'));
    $baselineData = json_decode($baseline, true, flags: JSON_THROW_ON_ERROR);

    expect($baseline)->toContain('run lifecycle')
        ->not->toContain('private model output')
        ->not->toContain('do-not-promote')
        ->not->toContain('private scorer reasoning')
        ->and($baselineData)->not->toHaveKey('context')
        ->and($baselineData['trials'][0]['results'][0]['reasoning'])->toBeNull();
});

it('rejects promotion of a saved simulated run', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-promote-simulated');
    $store = new BaselineStore($paths);

    (new RunBundle($paths, $runId))->write(
        lifecycleScorecard(mode: ExecutionMode::Simulated),
        lifecycleReplay(),
    );

    expect(fn () => $store->promote($runId, 'simulated'))
        ->toThrow(RuntimeException::class, 'Simulated evidence')
        ->and(is_dir($paths->baselines))->toBeFalse();
});

it('rejects promotion of recorded evidence without explicit lineage', function (): void {
    $paths = lifecyclePaths();
    $runId = new RunId('run-promote-recorded');
    $store = new BaselineStore($paths);

    (new RunBundle($paths, $runId))->write(
        lifecycleScorecard(mode: ExecutionMode::Recorded),
        lifecycleReplay(),
    );

    expect(fn () => $store->promote($runId, 'recorded'))
        ->toThrow(RuntimeException::class, 'Only directly observed live evidence')
        ->and(is_dir($paths->baselines))->toBeFalse();
});
