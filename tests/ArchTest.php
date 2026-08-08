<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the package has no persistence concerns')
    ->expect('Jkudish\PestAiBenchmarks')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Schema',
    ]);

arch('the package contains no consumer domain assumptions')
    ->expect('Jkudish\PestAiBenchmarks')
    ->not->toUse([
        'App',
        'ReceiptFox',
        'PlanMode',
    ]);
