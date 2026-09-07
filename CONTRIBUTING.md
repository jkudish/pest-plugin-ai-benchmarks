# Contributing

Thanks for considering a contribution to Pest AI Benchmarks.

## Before opening an issue

- Search existing issues and discussions.
- Use Discussions for questions and early feature ideas.
- Use the bug template for reproducible defects.
- Report security issues privately according to [SECURITY.md](SECURITY.md).

## Development setup

```bash
git clone https://github.com/jkudish/pest-plugin-ai-benchmarks.git
cd pest-plugin-ai-benchmarks
composer install
```

Composer installs the tagged Laravel AI Pricing dependency and all other dependencies from public package sources. No sibling repository or custom Composer authentication is required.

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
