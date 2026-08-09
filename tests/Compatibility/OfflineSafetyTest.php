<?php

declare(strict_types=1);

benchmark('does not execute benchmark code outside eval mode', function (): void {
    throw new RuntimeException('Benchmark code executed without [--evals].');
});
