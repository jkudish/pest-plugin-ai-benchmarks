<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

/**
 * @return array{process: Process, scorecard: array<string, mixed>, replay: array<string, mixed>, raw: string}
 */
function runFailureEvidenceScenario(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $runs = fn (): array => glob($root.'/storage/app/ai-evals/runs/*/scorecard.json') ?: [];
    $before = $runs();
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/pest',
        __DIR__.'/Fixtures/FailureEvidenceBenchmark.php',
        '--evals',
        '--ci',
    ], $root, ['FAILURE_EVIDENCE_SCENARIO' => $scenario]);
    $process->run();

    $created = array_values(array_diff($runs(), $before));

    expect($created)->toHaveCount(1);

    $raw = (string) file_get_contents($created[0]);

    return [
        'process' => $process,
        'scorecard' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR),
        'replay' => json_decode((string) file_get_contents(dirname($created[0]).'/replay.private.json'), true, flags: JSON_THROW_ON_ERROR),
        'raw' => $raw,
    ];
}

/**
 * @param  array<string, mixed>  $scorecard
 * @return array<string, array<string, mixed>>
 */
function failureEvidenceResults(array $scorecard): array
{
    return collect($scorecard['trials'][0]['results'])->keyBy('scorer')->all();
}

it('records every scorer before failing on the ones below their threshold', function (): void {
    $run = runFailureEvidenceScenario('scorers');
    $results = failureEvidenceResults($run['scorecard']);

    expect($run['process']->isSuccessful())->toBeFalse()
        ->and(array_keys($results))->toBe(['pest:test', 'safety', 'quality', 'format'])
        ->and($results['safety']['passed'])->toBeFalse()
        ->and($results['quality']['passed'])->toBeTrue()
        ->and($results['format']['passed'])->toBeFalse()
        ->and($results['pest:test']['reasoning'])->toStartWith('The evaluation failed (')
        ->and($run['process']->getOutput())->toContain('safety scored 0')
        ->and($run['process']->getOutput())->toContain('format scored 0.1');
});

it('records why the target failed, keeping the message out of the stable scorecard', function (): void {
    $run = runFailureEvidenceScenario('target');
    $results = failureEvidenceResults($run['scorecard']);

    expect($run['process']->isSuccessful())->toBeFalse()
        ->and($run['scorecard']['package']['version'])->toBe(InstalledVersions::getPrettyVersion('jkudish/pest-plugin-ai-benchmarks'))
        ->and($run['scorecard']['package']['version'])->not->toBe('0.1.0-dev')
        ->and($results['pest:test']['reasoning'])->toBe('The target failed (RuntimeException).')
        ->and($run['raw'])->not->toContain('account 4417')
        ->and($run['replay']['trials'][0]['failure'])->toBe([
            'stage' => 'target',
            'class' => RuntimeException::class,
            'message' => 'provider said: account 4417 is rate-limited',
        ]);
});

it('fails a trial whose application swallowed the unfaithful-agent exception', function (): void {
    $run = runFailureEvidenceScenario('swallowed');
    $results = failureEvidenceResults($run['scorecard']);

    expect($run['process']->isSuccessful())->toBeFalse()
        ->and($results['pest:test']['passed'])->toBeFalse()
        ->and($results['pest:test']['reasoning'])->toBe('The target failed (Jkudish\PestAiBenchmarks\LaravelAi\UnfaithfulInstrumentation).');
});

it('writes a run that says why when configuration cannot be applied', function (): void {
    $run = runFailureEvidenceScenario('configuration');
    $results = failureEvidenceResults($run['scorecard']);
    $measurement = $results['pest:test']['measurements'][0];

    expect($run['process']->isSuccessful())->toBeFalse()
        ->and($results['pest:test']['reasoning'])->toBe('The configuration could not be applied (RuntimeException).')
        ->and($measurement['effective_model'])->toBeNull()
        ->and($run['replay']['trials'][0]['failure']['message'])->toContain('benchmark.unset_model');
});
