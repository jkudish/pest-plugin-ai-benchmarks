<?php

declare(strict_types=1);

benchmark('invokes static target and evaluation closures', static function (): string {
    $counter = getenv('BENCHMARK_TARGET_COUNTER');

    if (is_string($counter) && $counter !== '') {
        file_put_contents($counter, "target\n", FILE_APPEND | LOCK_EX);
    }

    return 'static output';
})->evaluate(static function (string $output): void {
    $counter = getenv('BENCHMARK_EVALUATION_COUNTER');

    if (is_string($counter) && $counter !== '') {
        file_put_contents($counter, "evaluation\n", FILE_APPEND | LOCK_EX);
    }

    expect($output)->toBe('static output');
});
