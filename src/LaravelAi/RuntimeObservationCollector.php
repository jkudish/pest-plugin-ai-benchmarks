<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use Jkudish\PestAiBenchmarks\Scorecards\Component;
use LogicException;

final class RuntimeObservationCollector
{
    private static bool $active = false;

    private static ?Component $component = null;

    /** @var list<AgentObservation> */
    private static array $observations = [];

    public static function begin(Component $component = Component::Target): void
    {
        if (self::$active) {
            throw new LogicException('A benchmark observation span is already active.');
        }

        self::$active = true;
        self::$component = $component;
        self::$observations = [];
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function component(): ?Component
    {
        return self::$component;
    }

    public static function record(AgentObservation $observation): void
    {
        if (self::$active) {
            self::$observations[] = $observation;
        }
    }

    /** @return list<AgentObservation> */
    public static function finish(): array
    {
        $observations = self::$observations;

        self::reset();

        return $observations;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$component = null;
        self::$observations = [];
    }
}
