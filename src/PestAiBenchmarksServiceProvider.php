<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class PestAiBenchmarksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('pest-plugin-ai-benchmarks')
            ->hasConfigFile();
    }
}
