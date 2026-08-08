<?php

declare(strict_types=1);

use Jkudish\PestAiBenchmarks\Results\OpaqueContext;

it('preserves bounded JSON-safe correlation context without interpreting it', function (): void {
    $context = new OpaqueContext([
        'correlation_id' => 'external-work-123',
        'trace_id' => 'trace-456',
        'work_refs' => ['task-789'],
        'attributes' => ['attempt' => 2, 'sampled' => true],
    ]);

    expect($context->toArray())->toBe([
        'correlation_id' => 'external-work-123',
        'trace_id' => 'trace-456',
        'work_refs' => ['task-789'],
        'attributes' => ['attempt' => 2, 'sampled' => true],
    ]);
});

it('rejects unsafe or unbounded correlation context', function (Closure $create, string $message): void {
    expect($create)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'list root' => [fn (): OpaqueContext => new OpaqueContext(['value']), 'JSON object'],
    'object value' => [fn (): OpaqueContext => new OpaqueContext(['value' => new stdClass]), 'JSON-safe'],
    'infinite float' => [fn (): OpaqueContext => new OpaqueContext(['value' => INF]), 'finite'],
    'too deep' => [fn (): OpaqueContext => new OpaqueContext(['a' => ['b' => ['c' => ['d' => ['e' => 'value']]]]]), 'nesting depth'],
    'too many keys' => [fn (): OpaqueContext => new OpaqueContext(array_fill_keys(array_map(fn (int $key): string => "key-{$key}", range(1, OpaqueContext::MAX_KEYS + 1)), true)), 'too many keys'],
    'too large' => [fn (): OpaqueContext => new OpaqueContext(['value' => str_repeat('x', OpaqueContext::MAX_BYTES)]), 'encoded size'],
]);
