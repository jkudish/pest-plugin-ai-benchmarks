# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-01

Upgrading: re-promote baselines from a 0.2 run. Baseline compatibility does not check the package version, and 0.2 counts input tokens differently (see Changed), so 0.1 baselines are not comparable for cost.

### Added

- `toPassBenchmarkScorers()` runs and records every scorer for a trial before failing, and the failure lists each scorer below its threshold.
- `InstrumentedAgent::assertFaithful()` checks that an eval-only agent subclass repeats the Laravel AI attributes of the production agent it extends.
- A failed trial records why: the scorecard's Pest test result names the stage (configuration, target, or evaluation) and the exception class, and `replay.private.json` keeps the exception message.

### Changed

- Requires `laravel/ai` 1.0 for live observations (`conflict: laravel/ai <1.0`). Laravel AI 1.0 runs agent middleware around each generation step; the steps of one invocation are folded back into a single measurement with summed usage and provider cost.
- Input token usage now follows Laravel AI 1.0, where `inputTokens` includes cached and cache-written tokens; the direct fallback reports the uncached remainder.
- Requires `jkudish/laravel-ai-pricing` ^0.2, Pest Evals ^5.1 (5.0 conflicts with Laravel AI 1.x), Pest ^5.2.1, and `illuminate/contracts` ^13.23, matching the versions actually supported.
- `BenchmarkAgentMiddleware` refuses to send a request from an agent subclass whose Laravel AI attributes differ from its parent's, and fails the trial with `UnfaithfulInstrumentation`, even when the application catches the exception. Previously such a subclass silently measured a different request than production.
- A trial with no observed model call records the configured model as requested and no effective model, instead of reporting the configured model as effective.
- Scorecards record the installed package version instead of the fixed `0.1.0-dev`.

### Fixed

- A configuration that cannot be applied (for example, a production model key that does not resolve) is recorded as a failed trial, so the run is still written.
- Streamed step failures, including failures in non-first steps, are recorded in the observation fold.

## [0.1.0] - 2026-09-07

### Added

- Pest-native `benchmark()` declarations with datasets, repetitions, filtering, groups, and dependencies.
- Named production, model, and application-setting configurations.
- Explicit eval-mode safety that keeps ordinary Pest runs offline.
- Live Laravel AI provider, model, token, latency, failure, and fallback evidence.
- Durable versioned scorecards and private replay bundles.
- Stable execution, trial, result, and measurement identities.
- Source, closure, case, configuration, and dependency fingerprints.
- Compatible replay and resume workflows.
- Sanitized baseline promotion and historical regression gates.
- Pass-rate, median-latency, and average-cost comparison policies.
- Explicit rejection of unsupported parallel benchmark execution.
- Benchmark-owned `toPassBenchmarkScorer()` evidence capture using Pest Evals' public scorer contract.
- Contributor and CI installation through public package sources without sibling path repositories.

### Changed

- Declared Laravel 13.23 as the supported framework line because Pest 5's Symfony Process requirement is incompatible with Laravel 12.

[Unreleased]: https://github.com/jkudish/pest-plugin-ai-benchmarks/compare/v0.2.0...main
[0.2.0]: https://github.com/jkudish/pest-plugin-ai-benchmarks/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/jkudish/pest-plugin-ai-benchmarks/releases/tag/v0.1.0
