<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures;

use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\HasMiddleware;

#[Timeout(30)]
#[Strict]
final class FaithfulReceiptAgent extends ProductionReceiptAgent implements HasMiddleware
{
    public function middleware(): array
    {
        return [new BenchmarkAgentMiddleware];
    }
}
