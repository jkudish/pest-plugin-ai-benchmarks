<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Configuration;

benchmark('applies explicit historical regression gates', function (): string {
    $delay = getenv('BENCHMARK_TARGET_DELAY_US');

    if (is_string($delay) && ctype_digit($delay)) {
        usleep((int) $delay);
    }

    return 'stable output';
})
    ->configurations(['production' => Configuration::production()])
    ->reference('production')
    ->evaluate(function (string $output): void {
        $delay = getenv('BENCHMARK_EVALUATION_DELAY_US');

        if (is_string($delay) && ctype_digit($delay)) {
            usleep((int) $delay);
        }

        expect($output)->toBe('stable output');
    })
    ->failWhen([
        'median_latency_increase' => (float) (getenv('BENCHMARK_GATE_THRESHOLD') ?: 0.1),
    ]);
