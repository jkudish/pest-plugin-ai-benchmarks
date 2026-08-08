<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Measurements\Clock;
use Jkudish\PestAiBenchmarks\Measurements\LatencyMeasurer;

it('measures latency using an injected monotonic clock', function (): void {
    $clock = new class implements Clock
    {
        /** @var list<int> */
        private array $times = [1_000_000_000, 1_125_500_000];

        public function nowNanoseconds(): int
        {
            return array_shift($this->times) ?? throw new RuntimeException('No time remains.');
        }
    };

    $measurement = (new LatencyMeasurer($clock))->measure(fn (): string => 'response');

    expect($measurement->value)->toBe('response')
        ->and($measurement->latency->nanoseconds)->toBe(125_500_000)
        ->and($measurement->latency->milliseconds())->toBe(125.5);
});

it('rejects a clock that moves backwards', function (): void {
    $clock = new class implements Clock
    {
        private int $calls = 0;

        public function nowNanoseconds(): int
        {
            return $this->calls++ === 0 ? 20 : 10;
        }
    };

    expect(fn (): mixed => (new LatencyMeasurer($clock))->measure(fn (): null => null))
        ->toThrow(RuntimeException::class, 'The monotonic clock moved backwards.');
});
