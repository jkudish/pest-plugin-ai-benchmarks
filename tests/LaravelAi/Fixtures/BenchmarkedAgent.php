<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures;

use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

final class BenchmarkedAgent implements Agent, HasMiddleware
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract structured receipt data.';
    }

    public function middleware(): array
    {
        return [new BenchmarkAgentMiddleware];
    }
}
