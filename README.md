# Pest AI Benchmarks

Comparative AI benchmarks built on Pest 5 and Pest Evals. The plugin preserves Pest's datasets, expectations, repetitions, filtering, and failure behavior while adding named model/application configurations and durable benchmark evidence.

> This package is under private pre-release validation and is not yet published.

## Requirements

- PHP 8.4 or newer
- Laravel 13 for the standard Pest Laravel integration
- Pest 5
- Pest Evals 5 with the scorer-result callback proposed in `pestphp/pest-plugin-evals#4`

The package keeps Illuminate 12-compatible contracts so applications with a custom test bootstrap are not needlessly excluded. However, `pestphp/pest-plugin-laravel` 5 currently requires Laravel 13.23 or newer, so the conventional Laravel 12 + Pest Laravel plugin combination cannot be supported or tested until that upstream constraint changes.

## Example

```php
use Jkudish\PestAiBenchmarks\Configuration;

benchmark('extracts receipts', function (array $case): array {
    $result = app(ReceiptOcrService::class)->extract($case['file']);

    return $result->toArray();
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
    ->evaluate(function (array $output, array $case): void {
        expect($output['merchant_name'])->toBe($case['expected']['merchant_name'])
            ->and($output['total_amount'])->toBe($case['expected']['total_amount']);
    })
    ->dependsOn([
        ReceiptOcrService::class,
        'config/receipt-ocr.php',
    ])
    ->repeat(3);
```

Benchmarks are skipped during ordinary Pest runs before their test bodies execute. Run all benchmarks explicitly through Pest Evals, or select one by name:

```bash
./vendor/bin/pest --evals
./vendor/bin/pest --evals --benchmark='extracts receipts'
```

The target callback returns a JSON-safe output. `evaluate()` is the reusable expectation boundary: it receives that output followed by the original dataset arguments, and may use ordinary Pest expectations or Pest Evals expectations such as `toPassScorer()`. It is not a competing scorer API. The same callback runs after a live target and when a private saved output is replayed.

### Replay, resume, and historical gates

Every completed eval writes a run ID in `storage/app/ai-evals/runs/`. Replay reruns `evaluate()` against private saved outputs without entering application configuration scope or invoking the target/provider path:

```bash
./vendor/bin/pest --evals --benchmark-replay='20260809T120000Z-abc123'
```

Resume reuses target output only from compatible trials whose primary and evaluation evidence passed. It always reruns `evaluate()` against reused output, and invokes the target again for missing, failed, or incomplete saved trials:

```bash
./vendor/bin/pest --evals --benchmark-resume='20260809T120000Z-abc123'
```

Target and `evaluate()` source identities, JSON-safe closure captures, cases, declared configurations, resolved production application dependencies, and package/schema identity are fingerprinted before execution. Delegated production code is intentionally not discovered through a broad workspace scan: list every class or file used behind the target with `dependsOn([...])` so its source-content hash participates in safe replay and resume. Missing, unreadable, duplicated, or ambiguous dependencies fail closed. Replay and resume fail closed before target execution if a matching saved trial has a different fingerprint. Non-JSON-safe closure captures are rejected. Runtime provider/model identity remains in measurement fingerprints.

Promote a completed run through the public API. Promotion copies only strict metric and identity evidence, removes scorecard context and scorer reasoning, and accepts only directly observed `live` measurements. Simulated and `recorded` measurements are rejected because the unchanged v0.1 schema cannot prove their full ancestry:

```php
benchmarks()->promote(
    run: '20260809T120000Z-abc123',
    baseline: 'production',
);
```

Select that baseline for a historical comparison:

```bash
./vendor/bin/pest --evals --benchmark-baseline=production
```

`reference('production')` identifies same-run comparative evidence. `failWhen()` gates are enforced only when `--benchmark-baseline` explicitly selects a compatible historical scorecard; without that flag, results remain evidence-only. A failed or not-evaluable explicit gate returns a nonzero process exit.

Benchmark eval runs are deliberately serial in version 0.1. Combining `--evals` with `--parallel` or `-p` fails before execution because partial worker scorecards cannot be truthfully aggregated yet.

`benchmark()` is the only benchmark declaration form. The package does not provide competing scorer, judge, sampling, case, target, candidate, or variant APIs.

### Application configuration

The package cannot safely guess which application config keys drive a production service. Configure those keys after the test application boots so named configurations change the same keys your production path reads:

```php
beforeEach(function (): void {
    benchmarks()->configure(
        provider: 'receipt_ocr.provider',
        model: 'receipt_ocr.model',
        options: 'receipt_ocr.options',
        settings: ['receipt_ocr.prompt'],
    );
});
```

The `options` key is optional when candidates do not override provider options:

```php
benchmarks()->configure(
    provider: 'receipt_ocr.provider',
    model: 'receipt_ocr.model',
    settings: ['receipt_ocr.prompt'],
);
```

Production configurations can run without setup. Model or application-setting overrides fail before the benchmark body when `benchmarks()->configure(...)` has not been called, preventing a requested model from being reported when it never affected execution.

### Laravel AI observations

Add the benchmark middleware to an eval-only subclass of your production Laravel AI agent. The production agent remains unchanged, while benchmark calls record the resolved request, provider response, token usage, latency, and failed fallback attempts:

```php
use App\Ai\Agents\ReceiptOcrAgent;
use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Laravel\Ai\Contracts\HasMiddleware;

final class BenchmarkReceiptOcrAgent extends ReceiptOcrAgent implements HasMiddleware
{
    public function middleware(): array
    {
        return [new BenchmarkAgentMiddleware];
    }
}
```

The middleware is inert outside an active `benchmark()` body. Real calls made through an instrumented agent are recorded as `live`; Laravel AI fake-gateway calls and uninstrumented benchmark bodies remain `simulated` and cannot be promoted as baselines. Runtime response metadata is authoritative for the effective provider and model, even when it differs from the requested configuration. Pricing is calculated from normalized usage through `jkudish/laravel-ai-pricing`; Laravel AI does not currently expose provider-reported OpenRouter cost.

Completed and failed scorer eval runs write a recursively sanitized, bounded `scorecard.json` and private, redacted `replay.private.json` beneath `storage/app/ai-evals/runs/`. Requested and effective model identities are recorded independently. Stable scorecards exclude scorer inputs, expected values, prompts, and outputs; private replay retains the output needed to rerun scorers.

## Current foundation

- Pest-native benchmark and configuration expansion
- Explicit `--evals` safety gating and benchmark-name filtering
- Scoped Laravel model and application configuration
- Requested/effective model evidence
- Live Laravel AI identity, latency, usage, failure, and fallback observations
- Shared `jkudish/laravel-ai-pricing` integration
- Versioned JSON Schema 2020-12 scorecards
- Stable scorecard, execution, trial, and result identities
- Deterministic pre-execution trial fingerprints with runtime measurement identity
- Bounded opaque correlation context
- Durable run bundles for successful and failed benchmark bodies
- Private-output replay, compatible resume, baseline promotion, and explicit historical gates
- Explicit rejection of unsupported parallel benchmark execution

This private release candidate uses the existing `dev-add-scorer-result-callbacks` fork branch for native Pest Evals scorer evidence. A stable release remains gated on that callback landing upstream and being available in a compatible Pest Evals release; the plugin does not duplicate Pest's scoring API.

## Development

```bash
composer test
composer analyse
composer validate --strict
composer audit
```

## License

MIT. See [LICENSE.md](LICENSE.md).
