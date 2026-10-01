<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Tests\LaravelAi\Fixtures;

use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Strict]
#[Timeout(30)]
class ProductionReceiptAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract structured receipt data.';
    }
}
