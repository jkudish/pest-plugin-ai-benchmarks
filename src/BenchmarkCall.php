<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks;

use Closure;
use InvalidArgumentException;
use Pest\PendingCalls\TestCall;

/**
 * A narrow compatibility boundary around Pest's internal TestCall.
 *
 * Pest keeps TestCall internal and final. Keeping that dependency in this one
 * class lets the plugin retain Pest's native datasets, filters, failures, and
 * lifecycle while giving compatibility tests one explicit boundary to cover.
 */
final class BenchmarkCall
{
    private const string BENCHMARK_GROUP = '__pest_ai_benchmark';

    private bool $hasConfigurations = false;

    public function __construct(private readonly TestCall $testCall)
    {
        $this->testCall->group(self::BENCHMARK_GROUP);
    }

    /**
     * @param  array<array-key, mixed>  $configurations
     */
    public function configurations(array $configurations): self
    {
        if ($this->hasConfigurations) {
            throw new InvalidArgumentException('Benchmark configurations may only be declared once.');
        }

        if ($configurations === []) {
            throw new InvalidArgumentException('Benchmark configurations may not be empty.');
        }

        foreach ($configurations as $name => $configuration) {
            if (! is_string($name) || $name === '' || trim($name) !== $name || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                throw new InvalidArgumentException('Benchmark configuration names must be non-empty, stable strings without surrounding whitespace or control characters.');
            }

            if (! $configuration instanceof Configuration) {
                throw new InvalidArgumentException(sprintf('Benchmark configuration [%s] must be an instance of %s.', $name, Configuration::class));
            }
        }

        $this->testCall->with($configurations);
        $this->hasConfigurations = true;

        return $this;
    }

    /**
     * @param  Closure|iterable<array-key, mixed>|string  ...$data
     */
    public function with(Closure|iterable|string ...$data): self
    {
        $this->testCall->with(...$data);

        return $this;
    }

    public function repeat(int $times): self
    {
        $this->testCall->repeat($times);

        return $this;
    }

    public function group(string ...$groups): self
    {
        $this->testCall->group(...$groups);

        return $this;
    }

    public function skip(Closure|bool|string $conditionOrMessage = true, string $message = ''): self
    {
        $this->testCall->skip($conditionOrMessage, $message);

        return $this;
    }

    public function only(): self
    {
        $this->testCall->only();

        return $this;
    }

    public function depends(string ...$depends): self
    {
        $this->testCall->depends(...$depends);

        return $this;
    }
}
