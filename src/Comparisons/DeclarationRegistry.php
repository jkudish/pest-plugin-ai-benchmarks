<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

use Closure;
use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\BenchmarkCall;
use Jkudish\PestAiBenchmarks\Configuration;
use Jkudish\PestAiBenchmarks\Results\OpaqueContext;
use LogicException;
use WeakMap;

/** @internal */
final class DeclarationRegistry
{
    /** @var WeakMap<DeclarationContext, BenchmarkDeclaration>|null */
    private static ?WeakMap $declarations = null;

    /** @var WeakMap<BenchmarkCall, DeclarationContext>|null */
    private static ?WeakMap $contexts = null;

    /** @var list<DeclarationContext> */
    private static array $active = [];

    public static function register(BenchmarkCall $call, DeclarationContext $context): void
    {
        self::declarations()[$context] = new BenchmarkDeclaration;
        self::contexts()[$call] = $context;
    }

    /** @param array<string, Configuration> $configurations */
    public static function setConfigurations(BenchmarkCall $call, array $configurations): void
    {
        $declaration = self::get($call);
        self::declarations()[self::context($call)] = $declaration->withConfigurations($configurations);
    }

    public static function setReference(BenchmarkCall $call, string $reference): void
    {
        $declaration = self::get($call);

        if ($declaration->reference !== null) {
            throw new InvalidArgumentException('A benchmark reference may only be declared once.');
        }

        if (! in_array($reference, $declaration->configurations, true)) {
            throw new InvalidArgumentException(sprintf('Benchmark reference [%s] is not a declared configuration.', $reference));
        }

        self::declarations()[self::context($call)] = $declaration->withReference($reference);
    }

    public static function setContext(BenchmarkCall $call, OpaqueContext $context): void
    {
        $declaration = self::get($call);

        if ($declaration->context !== null) {
            throw new InvalidArgumentException('Benchmark context may only be declared once.');
        }

        self::declarations()[self::context($call)] = $declaration->withContext($context);
    }

    public static function setRegressionPolicy(BenchmarkCall $call, RegressionPolicy $regressionPolicy): void
    {
        $declaration = self::get($call);

        if ($declaration->regressionPolicy !== null) {
            throw new InvalidArgumentException('Benchmark regression gates may only be declared once.');
        }

        self::declarations()[self::context($call)] = $declaration->withRegressionPolicy($regressionPolicy);
    }

    public static function get(BenchmarkCall $call): BenchmarkDeclaration
    {
        return self::declarations()[self::context($call)]
            ?? throw new LogicException('The benchmark call is not registered.');
    }

    public static function current(): BenchmarkDeclaration
    {
        if (self::$active === []) {
            throw new LogicException('No benchmark declaration is active.');
        }

        $context = self::$active[count(self::$active) - 1];

        return self::declarations()[$context]
            ?? throw new LogicException('The active benchmark declaration is not registered.');
    }

    public static function configurationName(Configuration $configuration): string
    {
        foreach (self::current()->configurationValues as $name => $candidate) {
            if ($candidate === $configuration) {
                return $name;
            }
        }

        throw new LogicException('The active benchmark configuration is not registered.');
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function within(DeclarationContext $context, Closure $callback): mixed
    {
        if (! isset(self::declarations()[$context])) {
            throw new LogicException('The benchmark declaration context is not registered.');
        }

        self::$active[] = $context;

        try {
            return $callback();
        } finally {
            array_pop(self::$active);
        }
    }

    /** @return WeakMap<DeclarationContext, BenchmarkDeclaration> */
    private static function declarations(): WeakMap
    {
        return self::$declarations ??= new WeakMap;
    }

    /** @return WeakMap<BenchmarkCall, DeclarationContext> */
    private static function contexts(): WeakMap
    {
        return self::$contexts ??= new WeakMap;
    }

    private static function context(BenchmarkCall $call): DeclarationContext
    {
        return self::contexts()[$call]
            ?? throw new LogicException('The benchmark call is not registered.');
    }
}
