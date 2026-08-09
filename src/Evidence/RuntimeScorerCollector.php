<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Evidence;

use LogicException;

final class RuntimeScorerCollector
{
    private static bool $active = false;

    /** @var list<PestEvalObservation> */
    private static array $observations = [];

    public static function begin(): void
    {
        if (self::$active) {
            throw new LogicException('A benchmark scorer span is already active.');
        }

        self::$active = true;
        self::$observations = [];
    }

    public static function record(PestEvalObservation $observation): void
    {
        if (self::$active) {
            self::$observations[] = $observation;
        }
    }

    /** @return list<PestEvalObservation> */
    public static function finish(): array
    {
        $observations = self::$observations;

        self::reset();

        return $observations;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$observations = [];
    }
}
