<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Configuration;

benchmark('records stable repeated case identities', function (string $case): array {
    expect(func_get_args())->toHaveCount(1);

    return ['case' => $case];
})->configurations([
    'production' => Configuration::production(),
    'candidate' => Configuration::production(),
])->with([
    'first' => 'first-case',
    'second' => 'second-case',
])->repeat(3);
