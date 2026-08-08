<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Plugin;
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
