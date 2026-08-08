<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Results\EvidenceId;

it('generates stable machine-readable evidence identifiers', function (string $prefix): void {
    $identifier = EvidenceId::generate($prefix);

    expect($identifier->value)->toMatch('/^'.preg_quote($prefix, '/').'_[a-f0-9]{32}$/')
        ->and(EvidenceId::from($identifier->value, $prefix)->value)->toBe($identifier->value);
})->with(['sc', 'exec', 'trial', 'res']);

it('rejects invalid evidence identifiers and prefixes', function (Closure $create): void {
    expect($create)->toThrow(InvalidArgumentException::class);
})->with([
    'unknown prefix' => fn (): EvidenceId => EvidenceId::generate('unknown'),
    'wrong prefix' => fn (): EvidenceId => EvidenceId::from('trial_12345678', 'res'),
    'short value' => fn (): EvidenceId => EvidenceId::from('res_short', 'res'),
]);
