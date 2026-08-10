<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use Jkudish\PestAiBenchmarks\Runs\BaselineStore;
use Jkudish\PestAiBenchmarks\Runs\RunPaths;
use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use Throwable;

/** @internal */
final class GateEvaluator
{
    public function evaluate(
        Scorecard $current,
        BenchmarkDeclaration $declaration,
        ?string $baselineName,
        RunPaths $paths,
    ): RegressionEvaluation {
        $policy = $declaration->regressionPolicy ?? RegressionPolicy::evidenceOnly();

        if ($baselineName === null || ! $policy->hasGates()) {
            return new RegressionEvaluation(RegressionStatus::EvidenceOnly);
        }

        try {
            $baseline = (new BaselineStore($paths))->load($baselineName);
            $baseline->assertCompatible($current);
            $aggregator = new ScorecardEvidence;

            return (new RegressionEvaluator)->evaluate(
                current: $aggregator->aggregate($current->toArray()),
                historical: $aggregator->aggregate($baseline->toArray()),
                policy: $policy,
            );
        } catch (Throwable $exception) {
            return new RegressionEvaluation(
                status: RegressionStatus::NotEvaluable,
                failures: [$exception->getMessage()],
            );
        }
    }
}
