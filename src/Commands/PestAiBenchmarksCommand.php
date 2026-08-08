<?php

namespace Jkudish\PestAiBenchmarks\Commands;

use Illuminate\Console\Command;

class PestAiBenchmarksCommand extends Command
{
    public $signature = 'pest-plugin-ai-benchmarks';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
