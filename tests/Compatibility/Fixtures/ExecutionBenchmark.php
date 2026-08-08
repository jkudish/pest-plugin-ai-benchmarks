<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Configuration;

benchmark('executes scoped configurations and records evidence', function (): array {
    $provider = config('benchmark.provider');
    $model = config('benchmark.model');
    $prompt = config('benchmark.prompt');

    expect($provider)->toBeString()
        ->and($model)->toBeString()
        ->and($prompt)->toBeString();

    return compact('provider', 'model', 'prompt');
})->configurations([
    'production' => Configuration::production(),
    'candidate' => Configuration::model('openrouter', 'candidate/model')
        ->withSettings(['benchmark.prompt' => 'v2']),
]);
