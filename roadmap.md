# Roadmap

Pest AI Benchmarks extends Pest's existing vocabulary with comparative configurations and durable evidence. It does not aim to replace Pest Evals scorers, judges, datasets, expectations, or failure behavior.

## Version 0.1 release

- [x] Pest-native benchmark declaration and configuration expansion.
- [x] Explicit eval-mode safety and benchmark filtering.
- [x] Live Laravel AI identity, usage, latency, failure, and fallback evidence.
- [x] Durable scorecards and private replay bundles.
- [x] Replay, compatible resume, baseline promotion, and historical gates.
- [x] Stable identities and fail-closed source fingerprints.
- [x] Pass-rate, latency, and cost regression policies.
- [x] Record explicit scorer results through Pest Evals' public scorer contract.
- [x] Replace development branch dependencies with tagged constraints.
- [ ] Publish `v0.1.0` to GitHub and Packagist.
- [ ] Verify installation in a clean consumer without sibling path repositories.

Laravel AI's current public response objects do not expose authoritative provider-reported monetary cost. Benchmark measurements use the shared pricing package's configured and public catalog resolution until Laravel AI surfaces that value.

## Planned

### Parallel-worker aggregation

Support Pest parallel execution only after scorecards, failures, retries, repetitions, and process exit state can be merged deterministically across workers.

### Run and baseline commands

Add safe commands to list, inspect, promote, prune, and validate saved runs and named baselines without requiring application code to call the promotion API directly.

## Deferred until a concrete need

- Reporter and exporter extension contracts.
- Schema migration tooling beyond explicit versioned schemas.
- Additional live observation adapters.
- Richer GitHub-specific reporting.
- Promotion of recorded evidence without complete, verifiable ancestry.

## Not planned

- A competing scorer, judge, sampling, case, target, candidate, or variant API.
- Silent execution during ordinary Pest runs.
- Promotion of simulated evidence as a production baseline.
