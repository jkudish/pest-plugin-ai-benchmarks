# Contributing

Thanks for considering a contribution to Pest AI Benchmarks.

## Before opening an issue

- Search existing issues and discussions.
- Use Discussions for questions and early feature ideas.
- Use the bug template for reproducible defects.
- Report security issues privately according to [SECURITY.md](SECURITY.md).

## Development setup

The repository currently develops alongside `laravel-ai-pricing`:

```text
workspace/
├── laravel-ai-pricing/
└── pest-plugin-ai-benchmarks/
```

```bash
git clone https://github.com/jkudish/laravel-ai-pricing.git
git clone https://github.com/jkudish/pest-plugin-ai-benchmarks.git
cd pest-plugin-ai-benchmarks
composer install
```

Run the complete local verification suite:

```bash
composer test
composer analyse
composer lint:check
composer validate --strict
composer audit
```

## Pull requests

- Keep changes focused and include tests for behavioral changes.
- Preserve ordinary Pest behavior and explicit `--evals` safety.
- Fail closed when replay, resume, or baseline evidence cannot be proven compatible.
- Keep prompts, outputs, expected values, and scorer inputs out of stable scorecards.
- Update the README, schema, fixtures, and changelog when the public contract changes.
- Ensure all automated checks pass before requesting review.

By participating, you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
