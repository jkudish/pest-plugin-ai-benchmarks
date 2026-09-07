<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return list<string> */
function lifecycleWiringRuns(string $root): array
{
    return glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
}

/**
 * @param  array<string, string>  $environment
 * @return array{process: Process, scorecard: string, run_id: string}
 */
function runLifecycleFixture(string $fixture, array $arguments = [], array $environment = []): array
{
    $root = dirname(__DIR__, 2);
    $before = lifecycleWiringRuns($root);
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/'.$fixture,
        '--evals',
        '--ci',
        ...$arguments,
    ], $root, $environment);
    $process->run();

    $created = array_values(array_diff(lifecycleWiringRuns($root), $before));

    expect($created)->toHaveCount(1);

    return [
        'process' => $process,
        'scorecard' => $created[0],
        'run_id' => basename(dirname($created[0])),
    ];
}

function lifecycleCounter(string $path): int
{
    $contents = file_get_contents($path);

    return is_string($contents) ? count(array_filter(explode("\n", $contents))) : 0;
}

function lifecyclePrimaryPassed(string $scorecard): ?bool
{
    $data = json_decode((string) file_get_contents($scorecard), true, flags: JSON_THROW_ON_ERROR);
    $results = $data['trials'][0]['results'] ?? [];

    foreach ($results as $result) {
        if (is_array($result) && ($result['scorer'] ?? null) === 'pest:test') {
            return is_bool($result['passed'] ?? null) ? $result['passed'] : null;
        }
    }

    return null;
}

it('replays private output through evaluate without invoking the target', function (): void {
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');
    $evaluationCounter = tempnam(sys_get_temp_dir(), 'pest-ai-evaluate-');

    expect($targetCounter)->toBeString()
        ->and($evaluationCounter)->toBeString();

    $environment = [
        'BENCHMARK_INCLUDE_SECOND' => '1',
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_EVALUATION_COUNTER' => $evaluationCounter,
    ];
    $source = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($source['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(2)
        ->and(lifecycleCounter($evaluationCounter))->toBe(2);

    file_put_contents($targetCounter, '');
    file_put_contents($evaluationCounter, '');

    $replay = runLifecycleFixture(
        'LifecycleExecutionBenchmark.php',
        ["--benchmark-replay={$source['run_id']}"],
        $environment,
    );
    $scorecard = json_decode((string) file_get_contents($replay['scorecard']), true, flags: JSON_THROW_ON_ERROR);

    expect($replay['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and(lifecycleCounter($evaluationCounter))->toBe(2)
        ->and($scorecard['trials'])->toHaveCount(2)
        ->and($scorecard['trials'][0]['results'][0]['measurements'][0]['mode'])->toBe('simulated')
        ->and(fn () => benchmarks()->promote($replay['run_id'], 'replayed-simulated-'.bin2hex(random_bytes(8))))
        ->toThrow(RuntimeException::class, 'Simulated evidence cannot be promoted');
});

it('invokes static targets and evaluations consistently in live, replay, and resume', function (): void {
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');
    $evaluationCounter = tempnam(sys_get_temp_dir(), 'pest-ai-evaluate-');

    expect($targetCounter)->toBeString()
        ->and($evaluationCounter)->toBeString();

    $environment = [
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_EVALUATION_COUNTER' => $evaluationCounter,
    ];
    $source = runLifecycleFixture('StaticClosureExecutionBenchmark.php', environment: $environment);

    expect($source['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(1)
        ->and(lifecycleCounter($evaluationCounter))->toBe(1);

    file_put_contents($targetCounter, '');
    file_put_contents($evaluationCounter, '');
    $replay = runLifecycleFixture(
        'StaticClosureExecutionBenchmark.php',
        ["--benchmark-replay={$source['run_id']}"],
        $environment,
    );

    expect($replay['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and(lifecycleCounter($evaluationCounter))->toBe(1);

    file_put_contents($evaluationCounter, '');
    $resume = runLifecycleFixture(
        'StaticClosureExecutionBenchmark.php',
        ["--benchmark-resume={$source['run_id']}"],
        $environment,
    );

    expect($resume['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and(lifecycleCounter($evaluationCounter))->toBe(1);
});

it('preserves isolated target and judge retry evidence through live replay and resume', function (): void {
    $source = runLifecycleFixture('ObservedLifecycleBenchmark.php');
    $sourceReplay = (string) file_get_contents(dirname($source['scorecard']).'/replay.private.json');

    expect($source['process']->isSuccessful())->toBeTrue()
        ->and($sourceReplay)->toContain('"score": 1.0');

    $runs = [
        'live' => $source,
        'replay' => runLifecycleFixture(
            'ObservedLifecycleBenchmark.php',
            ["--benchmark-replay={$source['run_id']}"],
        ),
        'resume' => runLifecycleFixture(
            'ObservedLifecycleBenchmark.php',
            ["--benchmark-resume={$source['run_id']}"],
        ),
    ];

    foreach ($runs as $mode => $run) {
        $scorecard = json_decode((string) file_get_contents($run['scorecard']), true, flags: JSON_THROW_ON_ERROR);
        $measurements = $scorecard['trials'][0]['results'][0]['measurements'];

        expect($run['process']->isSuccessful())->toBeTrue()
            ->and(array_column($measurements, 'component'))->toBe(['target', 'target', 'judge', 'judge'], $mode)
            ->and(array_column($measurements, 'retries'))->toBe([0, 1, 0, 1], $mode)
            ->and(array_unique(array_column($measurements, 'fingerprint')))->toHaveCount(4, $mode);

        if ($mode !== 'live') {
            expect(array_column(array_slice($measurements, 0, 2), 'mode'))->toBe(['recorded', 'recorded'], $mode)
                ->and(array_column(array_slice($measurements, 2), 'mode'))->toBe(['live', 'live'], $mode);
        }
    }
});

it('preserves successful null output despite scorer evidence through live replay and resume', function (): void {
    $source = runLifecycleFixture('NullableOutputBenchmark.php');
    $runs = [
        'live' => $source,
        'replay' => runLifecycleFixture(
            'NullableOutputBenchmark.php',
            ["--benchmark-replay={$source['run_id']}"],
        ),
        'resume' => runLifecycleFixture(
            'NullableOutputBenchmark.php',
            ["--benchmark-resume={$source['run_id']}"],
        ),
    ];

    foreach ($runs as $mode => $run) {
        $replay = json_decode(
            (string) file_get_contents(dirname($run['scorecard']).'/replay.private.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $scorecard = json_decode((string) file_get_contents($run['scorecard']), true, flags: JSON_THROW_ON_ERROR);

        expect($run['process']->isSuccessful())->toBeTrue()
            ->and($replay['trials'][0])->toHaveKey('output')
            ->and($replay['trials'][0]['output'])->toBeNull($mode)
            ->and(array_column($scorecard['trials'][0]['results'], 'scorer'))
            ->toContain('nullable-derived-status');
    }
});

it('cleans observation state after a failing evaluation before another benchmark', function (): void {
    $root = dirname(__DIR__, 2);
    $before = lifecycleWiringRuns($root);
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/CollectorIsolationBenchmark.php',
        '--evals',
        '--ci',
        '--order-by=default',
    ], $root);
    $process->run();

    $created = array_values(array_diff(lifecycleWiringRuns($root), $before));
    $scorecards = [];

    foreach ($created as $path) {
        $scorecard = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $scorecards[$scorecard['benchmark']] = $scorecard;
    }

    $failedMeasurements = $scorecards['fails after collecting target and judge evidence']['trials'][0]['results'][0]['measurements'];
    $cleanMeasurements = $scorecards['starts with an isolated observation collector']['trials'][0]['results'][0]['measurements'];

    expect($process->isSuccessful())->toBeFalse()
        ->and($created)->toHaveCount(2)
        ->and(array_column($failedMeasurements, 'component'))->toBe(['target', 'judge'])
        ->and(array_column($failedMeasurements, 'requested_model'))->toBe([
            ['provider' => 'fixture', 'model' => 'failed-target'],
            ['provider' => 'fixture', 'model' => 'failed-judge'],
        ])
        ->and($scorecards['fails after collecting target and judge evidence']['trials'][0]['results'][0]['passed'])->toBeFalse()
        ->and(array_column($cleanMeasurements, 'component'))->toBe(['target'])
        ->and(array_column($cleanMeasurements, 'requested_model'))->toBe([
            ['provider' => 'fixture', 'model' => 'clean-target'],
        ])
        ->and($scorecards['starts with an isolated observation collector']['trials'][0]['results'][0]['passed'])->toBeTrue();
});

it('records ordinary evaluate expectation failures truthfully in live and replay evidence', function (): void {
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');

    expect($targetCounter)->toBeString();

    $environment = [
        'BENCHMARK_INCLUDE_SECOND' => '0',
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_ORDINARY_FAILURE' => '0',
    ];
    $source = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($source['process']->isSuccessful())->toBeTrue()
        ->and(lifecyclePrimaryPassed($source['scorecard']))->toBeTrue();

    file_put_contents($targetCounter, '');
    $environment['BENCHMARK_ORDINARY_FAILURE'] = '1';
    $replay = runLifecycleFixture(
        'LifecycleExecutionBenchmark.php',
        ["--benchmark-replay={$source['run_id']}"],
        $environment,
    );

    expect($replay['process']->isSuccessful())->toBeFalse()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and(lifecyclePrimaryPassed($replay['scorecard']))->toBeFalse();

    $resume = runLifecycleFixture(
        'LifecycleExecutionBenchmark.php',
        ["--benchmark-resume={$source['run_id']}"],
        $environment,
    );

    expect($resume['process']->isSuccessful())->toBeFalse()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and(lifecyclePrimaryPassed($resume['scorecard']))->toBeFalse();

    $live = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($live['process']->isSuccessful())->toBeFalse()
        ->and(lifecycleCounter($targetCounter))->toBe(1)
        ->and(lifecyclePrimaryPassed($live['scorecard']))->toBeFalse();
});

it('resumes only trials missing from a compatible saved run', function (): void {
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');
    $evaluationCounter = tempnam(sys_get_temp_dir(), 'pest-ai-evaluate-');

    expect($targetCounter)->toBeString()
        ->and($evaluationCounter)->toBeString();

    $environment = [
        'BENCHMARK_INCLUDE_SECOND' => '0',
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_EVALUATION_COUNTER' => $evaluationCounter,
    ];
    $source = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($source['process']->isSuccessful())->toBeTrue();

    file_put_contents($targetCounter, '');
    file_put_contents($evaluationCounter, '');
    $environment['BENCHMARK_INCLUDE_SECOND'] = '1';

    $resumed = runLifecycleFixture(
        'LifecycleExecutionBenchmark.php',
        ["--benchmark-resume={$source['run_id']}"],
        $environment,
    );
    $scorecard = json_decode((string) file_get_contents($resumed['scorecard']), true, flags: JSON_THROW_ON_ERROR);

    expect($resumed['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(1)
        ->and(lifecycleCounter($evaluationCounter))->toBe(2)
        ->and($scorecard['trials'])->toHaveCount(2);
});

it('fails closed before resume when resolved production configuration changes', function (): void {
    $root = dirname(__DIR__, 2);
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');

    expect($targetCounter)->toBeString();

    $environment = [
        'BENCHMARK_INCLUDE_SECOND' => '0',
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_PRODUCTION_MODEL' => 'production/model-a',
    ];
    $source = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($source['process']->isSuccessful())->toBeTrue();

    file_put_contents($targetCounter, '');
    $environment['BENCHMARK_PRODUCTION_MODEL'] = 'production/model-b';
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/LifecycleExecutionBenchmark.php',
        '--evals',
        '--ci',
        "--benchmark-resume={$source['run_id']}",
    ], $root, $environment);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('fingerprint');
});

it('fails closed before resume when an explicit source dependency changes', function (): void {
    $root = dirname(__DIR__, 2);
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');
    $dependency = tempnam(sys_get_temp_dir(), 'pest-ai-dependency-');

    expect($targetCounter)->toBeString()
        ->and($dependency)->toBeString();

    file_put_contents($dependency, "version one\n");
    $environment = [
        'BENCHMARK_INCLUDE_SECOND' => '0',
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_SOURCE_DEPENDENCY' => $dependency,
    ];
    $source = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($source['process']->isSuccessful())->toBeTrue();

    file_put_contents($targetCounter, '');
    file_put_contents($dependency, "version two\n");
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/LifecycleExecutionBenchmark.php',
        '--evals',
        '--ci',
        "--benchmark-resume={$source['run_id']}",
    ], $root, $environment);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and(lifecycleCounter($targetCounter))->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('fingerprint');
});

it('reruns a failed saved trial instead of reusing it as passing evidence', function (): void {
    $targetCounter = tempnam(sys_get_temp_dir(), 'pest-ai-target-');
    $evaluationCounter = tempnam(sys_get_temp_dir(), 'pest-ai-evaluate-');

    expect($targetCounter)->toBeString()
        ->and($evaluationCounter)->toBeString();

    $environment = [
        'BENCHMARK_INCLUDE_SECOND' => '0',
        'BENCHMARK_TARGET_COUNTER' => $targetCounter,
        'BENCHMARK_EVALUATION_COUNTER' => $evaluationCounter,
        'BENCHMARK_ORDINARY_FAILURE' => '1',
    ];
    $failed = runLifecycleFixture('LifecycleExecutionBenchmark.php', environment: $environment);

    expect($failed['process']->isSuccessful())->toBeFalse()
        ->and(lifecyclePrimaryPassed($failed['scorecard']))->toBeFalse();

    file_put_contents($targetCounter, '');
    file_put_contents($evaluationCounter, '');
    $environment['BENCHMARK_ORDINARY_FAILURE'] = '0';
    $resumed = runLifecycleFixture(
        'LifecycleExecutionBenchmark.php',
        ["--benchmark-resume={$failed['run_id']}"],
        $environment,
    );

    expect($resumed['process']->isSuccessful())->toBeTrue()
        ->and(lifecycleCounter($targetCounter))->toBe(1)
        ->and(lifecycleCounter($evaluationCounter))->toBe(1)
        ->and(lifecyclePrimaryPassed($resumed['scorecard']))->toBeTrue();
});

it('rejects simulated saved runs through the public promotion API', function (): void {
    $source = runLifecycleFixture('GatedExecutionBenchmark.php', environment: [
        'BENCHMARK_GATE_THRESHOLD' => '0.1',
        'BENCHMARK_TARGET_DELAY_US' => '0',
    ]);
    $baseline = 'simulated-'.bin2hex(random_bytes(8));

    expect($source['process']->isSuccessful())->toBeTrue()
        ->and(fn () => benchmarks()->promote($source['run_id'], $baseline))
        ->toThrow(RuntimeException::class, 'Simulated evidence cannot be promoted')
        ->and(is_file(dirname(__DIR__, 2).'/tests/Evals/Baselines/'.$baseline.'.json'))
        ->toBeFalse();
});

it('excludes evaluation duration from fallback target latency', function (): void {
    $run = runLifecycleFixture('GatedExecutionBenchmark.php', environment: [
        'BENCHMARK_GATE_THRESHOLD' => '0.1',
        'BENCHMARK_TARGET_DELAY_US' => '0',
        'BENCHMARK_EVALUATION_DELAY_US' => '500000',
    ]);
    $scorecard = json_decode((string) file_get_contents($run['scorecard']), true, flags: JSON_THROW_ON_ERROR);
    $measurements = $scorecard['trials'][0]['results'][0]['measurements'];
    $targetLatency = array_sum(array_map(
        static fn (array $measurement): float => $measurement['component'] === 'target'
            ? (float) $measurement['latency_ms']
            : 0.0,
        $measurements,
    ));

    expect($run['process']->isSuccessful())->toBeTrue()
        ->and($targetLatency)->toBeLessThan(250.0);
});

it('returns a nonzero exit for failed and not-evaluable explicit historical gates', function (): void {
    $root = dirname(__DIR__, 2);
    $baselineName = 'lifecycle-'.bin2hex(random_bytes(8));
    $baselineDirectory = $root.'/tests/Evals/Baselines';
    $baselinePath = $baselineDirectory.'/'.$baselineName.'.json';
    $source = runLifecycleFixture('GatedExecutionBenchmark.php', environment: [
        'BENCHMARK_GATE_THRESHOLD' => '0.1',
        'BENCHMARK_TARGET_DELAY_US' => '0',
    ]);

    expect($source['process']->isSuccessful())->toBeTrue();

    if (! is_dir($baselineDirectory)) {
        mkdir($baselineDirectory, 0755, true);
    }

    copy($source['scorecard'], $baselinePath);

    try {
        $failed = runLifecycleFixture(
            'GatedExecutionBenchmark.php',
            ["--benchmark-baseline={$baselineName}"],
            [
                'BENCHMARK_GATE_THRESHOLD' => '0.1',
                'BENCHMARK_TARGET_DELAY_US' => '50000',
            ],
        );

        expect($failed['process']->isSuccessful())->toBeFalse()
            ->and($failed['process']->getOutput())->toContain('Gate status: failed');

        $incompatible = json_decode((string) file_get_contents($source['scorecard']), true, flags: JSON_THROW_ON_ERROR);
        $incompatible['trials'][0]['fingerprint'] = 'sha256:incompatible';
        file_put_contents($baselinePath, json_encode($incompatible, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $notEvaluable = runLifecycleFixture(
            'GatedExecutionBenchmark.php',
            ["--benchmark-baseline={$baselineName}"],
            [
                'BENCHMARK_GATE_THRESHOLD' => '0.1',
                'BENCHMARK_TARGET_DELAY_US' => '0',
            ],
        );

        expect($notEvaluable['process']->isSuccessful())->toBeFalse()
            ->and($notEvaluable['process']->getOutput())->toContain('Gate status: not_evaluable')
            ->and($notEvaluable['process']->getOutput())->toContain('compatibility fingerprints');
    } finally {
        if (is_file($baselinePath)) {
            unlink($baselinePath);
        }

        if (is_dir($baselineDirectory) && (glob($baselineDirectory.'/*') ?: []) === []) {
            rmdir($baselineDirectory);
        }
    }
});
