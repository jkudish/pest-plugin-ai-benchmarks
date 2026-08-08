# Pest AI Benchmarks

Comparative AI benchmarks for Laravel applications, built on Pest 5 and Pest Evals. The plugin preserves Pest's datasets, expectations, repetitions, filtering, and failure behavior while adding named model/application configurations and durable benchmark evidence.

> This package is under private pre-release validation and is not yet published.

## Requirements

- PHP 8.4 or newer
- Laravel 13 for the standard Pest Laravel integration
- Pest 5
- Pest Evals 5

The package keeps Illuminate 12-compatible contracts so applications with a custom test bootstrap are not needlessly excluded. However, `pestphp/pest-plugin-laravel` 5 currently requires Laravel 13.23 or newer, so the conventional Laravel 12 + Pest Laravel plugin combination cannot be supported or tested until that upstream constraint changes.

## Example

```php
use Jkudish\PestAiBenchmarks\Configuration;

benchmark('extracts receipts', function (array $case): void {
    $result = app(ReceiptOcrService::class)->extract($case['file']);

    expect($result)
        ->merchant_name->toBe($case['expected']['merchant_name'])
        ->total_amount->toBe($case['expected']['total_amount']);
})
    ->with('receipt corpus')
    ->configurations([
        'production' => Configuration::production(),
        'gemini-flash' => Configuration::model(
            provider: 'openrouter',
            model: 'google/gemini-3-flash',
        ),
        'new-prompt' => Configuration::settings([
            'receipt_ocr.prompt' => 'receipt-ocr-v2',
        ]),
    ])
    ->repeat(3);
```

Benchmarks are skipped during ordinary Pest runs before their test bodies execute. Run all benchmarks explicitly through Pest Evals, or select one by name:

```bash
./vendor/bin/pest --evals
./vendor/bin/pest --evals --benchmark='extracts receipts'
```

Benchmark eval runs are deliberately serial in version 0.1. Combining `--evals` with `--parallel` or `-p` fails before execution because partial worker scorecards cannot be truthfully aggregated yet.

`benchmark()` is the only benchmark declaration form. The package does not provide competing scorer, judge, sampling, case, target, candidate, or variant APIs.

### Laravel configuration scope

The package cannot safely guess which application config keys drive a production service. Bind one scope in your test bootstrap so named configurations change the same keys your production path reads:

```php
use Illuminate\Contracts\Config\Repository;
use Jkudish\PestAiBenchmarks\Laravel\LaravelConfigurationScope;

beforeEach(function (): void {
    app()->singleton(
        LaravelConfigurationScope::class,
        fn (): LaravelConfigurationScope => new LaravelConfigurationScope(
            repository: app(Repository::class),
            providerKey: 'receipt_ocr.provider',
            modelKey: 'receipt_ocr.model',
            optionsKey: 'receipt_ocr.options',
            supportedSettings: ['receipt_ocr.prompt'],
        ),
    );
});
```

Production configurations can run without a scope. Model or application-setting overrides fail before the benchmark body when no scope is bound, preventing a requested model from being reported when it never affected execution.

Successful eval-mode runs write a sanitized `scorecard.json` and private, redacted `replay.private.json` beneath `storage/app/ai-evals/runs/`. Requested and effective model identities are recorded independently. Runtime evidence is conservatively marked `simulated` until a truthful live/fake integration signal exists, so these early artifacts cannot be promoted as baselines.

## Current foundation

- Pest-native benchmark and configuration expansion
- Explicit `--evals` safety gating and benchmark-name filtering
- Scoped Laravel model and application configuration
- Requested/effective model evidence
- Monotonic latency and normalized usage seams
- Shared `jkudish/laravel-ai-pricing` integration
- Versioned JSON Schema 2020-12 scorecards
- Stable scorecard, execution, trial, and result identities
- Bounded opaque correlation context
- Durable run bundles for successful and failed benchmark bodies
- Explicit rejection of unsupported parallel benchmark execution

Replay, resume, baseline, and regression primitives are present but are not yet wired to their final CLI lifecycle. Complete native Pest Evals scorer capture remains gated on the upstream result-event hook.

## Development

```bash
composer test
composer analyse
composer validate --strict
composer audit
```

## License

MIT. See [LICENSE.md](LICENSE.md).
