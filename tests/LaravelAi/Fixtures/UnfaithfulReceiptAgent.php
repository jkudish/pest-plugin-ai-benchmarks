<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures;

use Jkudish\PestAiBenchmarks\LaravelAi\BenchmarkAgentMiddleware;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\HasMiddleware;

/** Drops #[Strict] and changes the timeout: not the request production sends. */
#[Timeout(60)]
final class UnfaithfulReceiptAgent extends ProductionReceiptAgent implements HasMiddleware
{
    public function middleware(): array
    {
        return [new BenchmarkAgentMiddleware];
    }
}
