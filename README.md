<h1 align="center">Pest AI Benchmarks</h1>

<p align="center">
  <strong>Compare models, prompts, and application configurations with Pest-native tests, durable evidence, replay, baselines, and CI regression gates.</strong>
</p>

<p align="center">
  <a href="https://github.com/jkudish/pest-plugin-ai-benchmarks/actions/workflows/run-tests.yml"><img src="https://github.com/jkudish/pest-plugin-ai-benchmarks/actions/workflows/run-tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/jkudish/pest-plugin-ai-benchmarks/actions/workflows/quality.yml"><img src="https://github.com/jkudish/pest-plugin-ai-benchmarks/actions/workflows/quality.yml/badge.svg" alt="Quality"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/github/license/jkudish/pest-plugin-ai-benchmarks" alt="License"></a>
</p>

Pest AI Benchmarks preserves Pest's datasets, expectations, repetitions, filtering, dependencies, and failure behavior while adding one comparative configuration axis and trustworthy evidence across runs.

It builds on [Pest Evals](https://github.com/pestphp/pest-plugin-evals) for scoring and [Laravel AI Pricing](https://github.com/jkudish/laravel-ai-pricing) for cost attribution. It does not introduce competing scorer, judge, case, candidate, or sampling APIs.

## Installation

Install the package as a development dependency:

```bash
composer require --dev jkudish/pest-plugin-ai-benchmarks
```

## Quick start

```php
use Jkudish\PestAiBenchmarks\Configuration;

benchmark('extracts receipts', function (array $case): array {
    return app(ReceiptOcrService::class)
        ->extract($case['file'])
        ->toArray();
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
        expect($output['merchant_name'])
            ->toBe($case['expected']['merchant_name'])
            ->and($output['total_amount'])
            ->toBe($case['expected']['total_amount']);
    })
    ->dependsOn([
        ReceiptOcrService::class,
        'config/receipt-ocr.php',
    ])
    ->repeat(3);
```

Ordinary Pest runs skip benchmark bodies before they execute. Opt in explicitly:

```bash
./vendor/bin/pest --evals
./vendor/bin/pest --evals --benchmark='extracts receipts'
```

## What it adds

| Capability | What it provides |
| --- | --- |
| Configurations | Compare production, model, options, and application-setting changes |
| Pest compatibility | Keep native datasets, expectations, repetitions, groups, dependencies, and filtering |
| Live observations | Record requested/effective model identity, usage, latency, failures, and fallbacks |
| Durable runs | Write versioned scorecards plus private replay data |
| Replay | Rerun evaluation against saved output without calling the target or provider |
| Resume | Reuse only compatible, completed output and rerun its evaluation |
| Baselines | Promote sanitized live evidence to a named historical reference |
| Regression gates | Fail CI on compatible pass-rate, latency, or cost regressions |
| Safety | Keep normal test runs offline and reject unsafe or ambiguous evidence reuse |

## Configure your application

The plugin cannot guess which application config keys drive your production service. Declare them after the test application boots:

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

The `options` key is optional when candidates do not override provider options. Production configurations can run without setup; requested overrides fail before the benchmark body if they cannot be applied truthfully.

## Evaluation and Pest Evals

The benchmark target returns a JSON-safe output. `evaluate()` is the reusable expectation boundary invoked with that output followed by the original dataset arguments.

It can use ordinary Pest expectations or Pest Evals scorers. Use `toPassBenchmarkScorer()` when a scorer result must be included in the benchmark scorecard:

```php
->evaluate(function (string $output): void {
    expect($output)->toPassBenchmarkScorer(
        scorer: new ReceiptAccuracyScorer,
        threshold: 0.9,
    );
})
```

The benchmark expectation delegates scoring and pass/fail behavior to Pest Evals' native `toPassScorer()` expectation while recording the result through its public `Scorer` contract. This avoids an unpublished callback or a maintained Pest Evals fork. Pest Evals convenience expectations such as `toBeRelevant()` remain available as ordinary assertions, but version 0.1 only records explicitly supplied scorers in benchmark scorecards.

The same evaluation callback runs after a live target, during replay, and when resume reuses compatible output.

## Laravel AI observations

Add the middleware to an eval-only subclass of your production Laravel AI agent:

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

The middleware is inert outside an active `benchmark()` body. Real calls made through an instrumented agent are recorded as `live`; Laravel AI fake-gateway calls and uninstrumented benchmark bodies remain `simulated` and cannot be promoted as baselines. Runtime response metadata is authoritative for the effective provider and model, even when it differs from the requested configuration. Pricing is calculated through `jkudish/laravel-ai-pricing`. Synchronous OpenRouter responses retain provider-reported `usage.cost` across every generation step; other providers use normalized usage and catalog pricing when their responses do not include money. Partial step cost is never presented as an authoritative total.

## Runs and private evidence

Completed and failed evals write a run beneath `storage/app/ai-evals/runs/`:

```text
storage/app/ai-evals/runs/<run-id>/
├── scorecard.json
└── replay.private.json
```

Stable scorecards are recursively sanitized and exclude prompts, outputs, expected values, and scorer inputs. Private replay data retains the exact JSON-safe output needed to rerun evaluation, including sensitive-looking keys and long strings, and must be protected as application data.

Requested and effective model identities are recorded separately. Scorecards use the bundled [JSON Schema 2020-12 contract](resources/schema/scorecard.schema.json).

## Replay and resume

Replay evaluates compatible private saved output without entering application configuration scope or invoking the target/provider path:

```bash
./vendor/bin/pest --evals --benchmark-replay='20260809T120000Z-abc123'
```

Resume reuses output only from compatible trials whose target and evaluation evidence passed. It invokes the target for missing, failed, incomplete, or incompatible trials:

```bash
./vendor/bin/pest --evals --benchmark-resume='20260809T120000Z-abc123'
```

Targets, evaluation callbacks, JSON-safe captures, cases, configurations, resolved production settings, declared dependencies, and package/schema identity are fingerprinted before execution. Declare delegated production code explicitly with `dependsOn([...])`; missing, unreadable, duplicated, or ambiguous dependencies fail closed.

## Baselines and regression gates

Promote a completed live run:

```php
benchmarks()->promote(
    run: '20260809T120000Z-abc123',
    baseline: 'production',
);
```

Promotion copies strict metric and identity evidence while excluding private output, scorecard context, and scorer reasoning. Simulated and recorded measurements are rejected in version 0.1 because their complete ancestry cannot yet be proven.

Declare gates on the benchmark:

```php
->failWhen([
    'pass_rate_drop' => 0.05,
    'median_latency_increase' => 0.20,
    'average_cost_increase' => 0.15,
])
```

Then select the historical baseline explicitly:

```bash
./vendor/bin/pest --evals --benchmark-baseline=production
```

Without `--benchmark-baseline`, results remain evidence-only. A failed or not-evaluable explicit gate returns a nonzero process exit.

`reference('production')` can also identify same-run comparative evidence without turning it into a historical baseline.

## Serial execution in version 0.1

Benchmark evals deliberately run serially. Combining `--evals` with `--parallel` or `-p` fails before execution because partial worker scorecards cannot yet be aggregated truthfully.

## Terminology

- **Benchmark:** the named Pest test and its comparative declaration.
- **Configuration:** one production, model, options, or application-setting candidate.
- **Trial:** one case, configuration, and repetition.
- **Result:** evaluation or scorer evidence attached to a trial.
- **Run:** the durable bundle produced by one benchmark execution.
- **Baseline:** sanitized live evidence promoted under a stable name.
- **Replay:** reevaluation of compatible private saved output.
- **Resume:** selective reuse of compatible passed output while completing missing work.

## Requirements

- PHP 8.4 or newer.
- Laravel 13.23 or newer.
- Pest 5.
- Pest Evals 5.0.2 or newer.

Pest 5 requires Symfony Process 8.1, while Laravel 12 requires Symfony Process 7.x. Those upstream constraints cannot be installed together, so Laravel 12 is not supported by version 0.1.

## Stability

The package follows Semantic Versioning. The public API and scorecard schema may evolve between minor releases before `1.0.0`; changes will be documented in the [changelog](CHANGELOG.md).

## Roadmap

See [roadmap.md](roadmap.md) for parallel aggregation, run and baseline commands, and the remaining version 0.1 publication gates.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security

Please report vulnerabilities privately according to [SECURITY.md](SECURITY.md).

## Sponsoring

If this package helps your work, consider [sponsoring its development](https://github.com/sponsors/jkudish).

## License

Pest AI Benchmarks is open-source software licensed under the [MIT license](LICENSE.md).
