<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

enum RegressionStatus: string
{
    case EvidenceOnly = 'evidence_only';
    case Passed = 'passed';
    case Failed = 'failed';
    case NotEvaluable = 'not_evaluable';
}
