<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests;

use Jkudish\PestAiBenchmarks\PestAiBenchmarksServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PestAiBenchmarksServiceProvider::class,
        ];
    }
}
