<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Plugin;
use Jkudish\PestAiBenchmarks\Runs\BaselineStore;
use Jkudish\PestAiBenchmarks\Runs\RunPaths;
use Symfony\Component\Process\Process;

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

it('applies named Laravel configurations and emits a durable run bundle in eval mode', function (): void {
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

it('rejects parallel benchmark execution', function (string $parallel): void {
    expect(fn () => (new Plugin)->handleOriginalArguments(['pest', '--evals', $parallel]))
        ->toThrow(InvalidArgumentException::class, 'AI benchmarks do not support parallel execution');
})->with(['--parallel', '-p']);
