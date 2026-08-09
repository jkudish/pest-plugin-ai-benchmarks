<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Reporters;

use Jkudish\PestAiBenchmarks\Scorecards\Scorecard;
use JsonException;

/** @internal */
final readonly class JsonReporter
{
    /** @throws JsonException */
    public function render(Scorecard $scorecard): string
    {
        return $scorecard->toJson();
    }
}
