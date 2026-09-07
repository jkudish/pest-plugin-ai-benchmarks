<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Plugin;
use Jkudish\PestAiBenchmarks\Runs\BaselineStore;
use Jkudish\PestAiBenchmarks\Runs\RunPaths;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Plugin::reset();
});

afterEach(function (): void {
    Plugin::reset();
});

it('removes the benchmark selector before PHPUnit and scopes execution to benchmarks', function (array $arguments, array $expected): void {
    $plugin = new Plugin;
    $plugin->handleOriginalArguments($arguments);

    expect($plugin->handleArguments($arguments))->toBe($expected);
})->with([
    'equals form' => [
        ['pest', '--benchmark=receipt OCR'],
        ['pest', '--group='.BenchmarkCall::BENCHMARK_GROUP],
    ],
    'separate form' => [
        ['pest', '--benchmark', 'receipt OCR'],
        ['pest', '--group='.BenchmarkCall::BENCHMARK_GROUP],
    ],
]);

it('removes benchmark lifecycle options before PHPUnit', function (): void {
    $arguments = [
        'pest',
        '--evals',
        '--benchmark-replay=run-one',
        '--benchmark-baseline',
        'production',
    ];
    $plugin = new Plugin;
    $plugin->handleOriginalArguments($arguments);

    expect($plugin->handleArguments($arguments))->toBe(['pest', '--evals'])
        ->and(Plugin::replayRunId()?->value)->toBe('run-one')
        ->and(Plugin::baselineName())->toBe('production');
});

it('validates benchmark lifecycle options before enabling them', function (): void {
    expect(fn () => (new Plugin)->handleOriginalArguments(['pest', '--benchmark-replay=run-one']))
        ->toThrow(InvalidArgumentException::class, 'require explicit [--evals] mode')
        ->and(fn () => (new Plugin)->handleOriginalArguments([
            'pest',
            '--evals',
            '--benchmark-replay=run-one',
            '--benchmark-resume=run-one',
        ]))->toThrow(InvalidArgumentException::class, 'mutually exclusive')
        ->and(fn () => (new Plugin)->handleOriginalArguments(['pest', '--evals', '--benchmark-baseline=../unsafe']))
        ->toThrow(InvalidArgumentException::class, 'Run IDs must be safe');
});

it('rejects an invalid benchmark selector', function (array $arguments, string $message): void {
    expect(fn () => (new Plugin)->handleOriginalArguments($arguments))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    [['pest', '--benchmark'], 'requires a non-empty benchmark name'],
    [['pest', '--benchmark='], 'requires a non-empty benchmark name'],
    [['pest', '--benchmark', '--evals'], 'requires a non-empty benchmark name'],
    [['pest', '--benchmark= receipt OCR'], 'requires a non-empty benchmark name'],
    [['pest', '--benchmark=receipt OCR', '--benchmark=classification'], 'may only be supplied once'],
]);

it('matches benchmark names case-insensitively by substring', function (): void {
    $plugin = new Plugin;
    $plugin->handleOriginalArguments(['pest', '--benchmark=receipt OCR']);

    expect(Plugin::matches('Extracts a Receipt OCR corpus'))->toBeTrue()
        ->and(Plugin::matches('classifies expenses'))->toBeFalse();

    $plugin->handleOriginalArguments(['pest']);

    expect(Plugin::matches('classifies expenses'))->toBeTrue();
});

it('executes benchmark compatibility coverage only in explicit eval mode', function (): void {
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/vendor/bin/pest',
        __DIR__.'/BenchmarkCallTest.php',
        '--evals',
        '--ci',
    ], dirname(__DIR__, 2));

    $process->mustRun();

    expect($process->getOutput())->toContain('16 passed');
});

it('does not leak a serial benchmark filter into child Pest processes', function (): void {
    (new Plugin)->handleOriginalArguments(['pest', '--benchmark=does-not-match']);
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/vendor/bin/pest',
        __DIR__.'/BenchmarkCallTest.php',
        '--evals',
        '--ci',
    ], dirname(__DIR__, 2));

    $process->mustRun();

    expect($process->getOutput())->toContain('16 passed');
});

it('applies named configurations and emits a durable run bundle in eval mode', function (): void {
    $root = dirname(__DIR__, 2);
    $before = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/ExecutionBenchmark.php',
        '--evals',
        '--ci',
    ], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('Benchmark: executes scoped configurations and records evidence')
        ->and($process->getOutput())->toContain('Trials: 2 | Results: 2 | Passed: 2 | Failed: 0');

    $after = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $created = array_values(array_diff($after, $before));

    expect($created)->toHaveCount(1);

    $scorecard = json_decode((string) file_get_contents($created[0]), true, flags: JSON_THROW_ON_ERROR);
    $trials = $scorecard['trials'] ?? [];

    expect($scorecard['benchmark'] ?? null)->toBe('executes scoped configurations and records evidence')
        ->and($trials)->toHaveCount(2)
        ->and(array_column($trials, 'configuration'))->toEqualCanonicalizing(['production', 'candidate'])
        ->and(array_column($trials, 'fingerprint'))->each->toStartWith('sha256:')
        ->and(array_unique(array_column($trials, 'fingerprint')))->toHaveCount(2);

    $candidate = collect($trials)->firstWhere('configuration', 'candidate');
    $measurement = $candidate['results'][0]['measurements'][0] ?? null;

    expect($measurement['mode'] ?? null)->toBe('simulated')
        ->and($measurement['requested_model'] ?? null)->toBe([
            'provider' => 'openrouter',
            'model' => 'candidate/model',
        ])->and($measurement['effective_model'] ?? null)->toBe([
            'provider' => 'openrouter',
            'model' => 'candidate/model',
        ]);

    expect(fn () => (new BaselineStore(RunPaths::forProject($root)))->assertPromotable($scorecard))
        ->toThrow(RuntimeException::class, 'Simulated evidence cannot be promoted as a baseline.');

    $replayPath = dirname($created[0]).'/replay.private.json';
    $replay = json_decode((string) file_get_contents($replayPath), true, flags: JSON_THROW_ON_ERROR);
    $candidateReplay = collect($replay['trials'] ?? [])->first(
        fn (array $trial): bool => ($trial['output']['model'] ?? null) === 'candidate/model',
    );

    expect($candidateReplay['output'] ?? null)->toBe([
        'provider' => 'openrouter',
        'model' => 'candidate/model',
        'prompt' => 'v2',
    ]);
});

it('records Pest repetitions under stable case identities', function (): void {
    $root = dirname(__DIR__, 2);
    $before = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/RepeatedExecutionBenchmark.php',
        '--evals',
        '--ci',
    ], $root);

    $process->mustRun();

    $after = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $created = array_values(array_diff($after, $before));

    expect($created)->toHaveCount(1);

    $scorecard = json_decode((string) file_get_contents($created[0]), true, flags: JSON_THROW_ON_ERROR);
    $trials = $scorecard['trials'] ?? [];
    $grouped = collect($trials)->groupBy(
        fn (array $trial): string => $trial['case_id']."\0".$trial['configuration'],
    );

    expect($trials)->toHaveCount(12)
        ->and(array_unique(array_column($trials, 'case_id')))->toHaveCount(2)
        ->and($grouped)->toHaveCount(4)
        ->and(array_unique(array_column($trials, 'fingerprint')))->toHaveCount(4);

    foreach ($grouped as $repetitions) {
        expect($repetitions->pluck('repeat')->sort()->values()->all())->toBe([1, 2, 3]);
    }
});

it('captures native Pest scorer results through the benchmark expectation', function (): void {
    $root = dirname(__DIR__, 2);
    $before = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/ScoredExecutionBenchmark.php',
        '--evals',
        '--ci',
    ], $root);

    $process->mustRun();

    $after = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $created = array_values(array_diff($after, $before));

    expect($created)->toHaveCount(1);

    $scorecard = json_decode((string) file_get_contents($created[0]), true, flags: JSON_THROW_ON_ERROR);
    $results = $scorecard['trials'][0]['results'] ?? [];
    $scored = collect($results)->firstWhere('scorer', 'receipt-fields');

    expect($results)->toHaveCount(2)
        ->and($scored)->toBeArray()
        ->and($scored['score'] ?? null)->toBe(0.96)
        ->and($scored['reasoning'] ?? null)->toBe('The expected merchant and account matched.')
        ->and($scored['threshold'] ?? null)->toBe(0.9)
        ->and($scored['passed'] ?? null)->toBeTrue()
        ->and($scored['sample'] ?? null)->toBe(1)
        ->and($scored['samples'] ?? null)->toBe(1)
        ->and($scored['measurements'][0]['fingerprint'] ?? null)->toStartWith('sha256:');
});

it('retains failed scorer output privately while sanitizing stable evidence', function (): void {
    $root = dirname(__DIR__, 2);
    $before = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/FailedScoredExecutionBenchmark.php',
        '--evals',
        '--ci',
    ], $root);

    $process->run();

    expect($process->isSuccessful())->toBeFalse();

    $after = glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $created = array_values(array_diff($after, $before));

    expect($created)->toHaveCount(1);

    $scorecardJson = (string) file_get_contents($created[0]);
    $scorecard = json_decode($scorecardJson, true, flags: JSON_THROW_ON_ERROR);
    $scored = collect($scorecard['trials'][0]['results'] ?? [])->firstWhere('scorer', 'receipt-fields');
    $replayJson = (string) file_get_contents(dirname($created[0]).'/replay.private.json');
    $replay = json_decode($replayJson, true, flags: JSON_THROW_ON_ERROR);

    expect($scored)->toBeArray()
        ->and($scored['score'] ?? null)->toBe(0.2)
        ->and($scored['threshold'] ?? null)->toBe(0.9)
        ->and($scored['passed'] ?? null)->toBeFalse()
        ->and($scored['reasoning'] ?? null)->not->toContain('private-token')
        ->and($scorecardJson)->not->toContain('Private Merchant')
        ->and($scorecardJson)->not->toContain('Expected Merchant')
        ->and($replay['trials'][0]['output'] ?? null)->toBe('{"merchant":"Private Merchant","account":"000"}');
});

it('rejects parallel benchmark execution', function (string $parallel): void {
    expect(fn () => (new Plugin)->handleOriginalArguments(['pest', '--evals', $parallel]))
        ->toThrow(InvalidArgumentException::class, 'AI benchmarks do not support parallel execution');
})->with(['--parallel', '-p']);

it('does not enable eval mode when argument validation fails', function (): void {
    $plugin = new Plugin;
    $plugin->handleOriginalArguments(['pest']);

    expect(fn () => $plugin->handleOriginalArguments(['pest', '--evals', '--parallel']))
        ->toThrow(InvalidArgumentException::class, 'AI benchmarks do not support parallel execution')
        ->and(Plugin::isEvalMode())->toBeFalse();
});

it('fails closed when distinct benchmarks share a description', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/DuplicateDescriptionBenchmark.php',
        __DIR__.'/Fixtures/DuplicateDescriptionSecondBenchmark.php',
        '--evals',
        '--ci',
    ], $root);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())
        ->toContain('benchmark descriptions must be unique');
});
