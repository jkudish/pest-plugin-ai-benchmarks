<?php

namespace Jkudish\PestAiBenchmarks;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Jkudish\PestAiBenchmarks\Commands\PestAiBenchmarksCommand;

class PestAiBenchmarksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('pest-plugin-ai-benchmarks')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_pest_plugin_ai_benchmarks_table')
            ->hasCommand(PestAiBenchmarksCommand::class);
    }
}
