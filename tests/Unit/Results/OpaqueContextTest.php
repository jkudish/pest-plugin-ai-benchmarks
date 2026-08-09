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

it('recursively sanitizes secrets and invalid UTF-8 before context becomes stable evidence', function (): void {
    $context = new OpaqueContext([
        'authorization' => 'Bearer private-token',
        'nested' => [
            'api_key' => 'sk-this-must-not-be-committed',
            'trace' => "trace-\xB1",
        ],
    ]);

    expect($context->toArray()['authorization'])->toBe('[REDACTED]')
        ->and($context->toArray()['nested']['api_key'])->toBe('[REDACTED]')
        ->and(mb_check_encoding($context->toArray()['nested']['trace'], 'UTF-8'))->toBeTrue();
});

it('bounds individual context strings without splitting UTF-8', function (): void {
    $context = new OpaqueContext(['value' => str_repeat('é', OpaqueContext::MAX_STRING_BYTES)]);
    $value = $context->toArray()['value'];

    expect(strlen($value))->toBeLessThanOrEqual(OpaqueContext::MAX_STRING_BYTES)
        ->and($value)->toEndWith('[TRUNCATED]')
        ->and(mb_check_encoding($value, 'UTF-8'))->toBeTrue();
});

it('rejects unsafe or unbounded correlation context', function (Closure $create, string $message): void {
    expect($create)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'list root' => [fn (): OpaqueContext => new OpaqueContext(['value']), 'JSON object'],
    'object value' => [fn (): OpaqueContext => new OpaqueContext(['value' => new stdClass]), 'JSON-safe'],
    'infinite float' => [fn (): OpaqueContext => new OpaqueContext(['value' => INF]), 'finite'],
    'too deep' => [fn (): OpaqueContext => new OpaqueContext(['a' => ['b' => ['c' => ['d' => ['e' => 'value']]]]]), 'nesting depth'],
    'too many keys' => [fn (): OpaqueContext => new OpaqueContext(array_fill_keys(array_map(fn (int $key): string => "key-{$key}", range(1, OpaqueContext::MAX_KEYS + 1)), true)), 'too many keys'],
    'too large' => [fn (): OpaqueContext => new OpaqueContext([
        'one' => str_repeat('x', OpaqueContext::MAX_STRING_BYTES),
        'two' => str_repeat('x', OpaqueContext::MAX_STRING_BYTES),
        'three' => str_repeat('x', OpaqueContext::MAX_STRING_BYTES),
        'four' => str_repeat('x', OpaqueContext::MAX_STRING_BYTES),
        'five' => str_repeat('x', OpaqueContext::MAX_STRING_BYTES),
    ]), 'encoded size'],
]);
