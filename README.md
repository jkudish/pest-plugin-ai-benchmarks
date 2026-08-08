# Pest AI Benchmarks

Comparative AI benchmarks for Laravel applications, built on Pest 5 and Pest Evals. The plugin preserves Pest's datasets, expectations, repetitions, filtering, and failure behavior while adding named model/application configurations and durable benchmark evidence.

> This package is under private pre-release validation and is not yet published.

## Requirements

- PHP 8.4 or newer
- Laravel 12 or 13
- Pest 5
- Pest Evals 5

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

`benchmark()` is the only benchmark declaration form. The package does not provide competing scorer, judge, sampling, case, target, candidate, or variant APIs.

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

Replay, resume, baselines, regression gates, and complete native scorer capture are still under development for version 0.1.

## Development

```bash
composer test
composer analyse
composer validate --strict
composer audit
```

## License

MIT. See [LICENSE.md](LICENSE.md).
