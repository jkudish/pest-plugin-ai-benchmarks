<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use ReflectionAttribute;
use ReflectionClass;

/**
 * Checks that an eval-only agent subclass sends what the production agent
 * it extends sends.
 *
 * Laravel AI reads agent configuration attributes such as #[Strict],
 * #[Timeout], #[Model], #[Provider] and #[UseCheapestModel] from the
 * concrete agent class, and PHP does not inherit class attributes. A
 * subclass that only adds BenchmarkAgentMiddleware therefore silently drops
 * them, and the benchmark measures a different request than production.
 * The subclass must repeat every Laravel AI attribute of its parent.
 */
final class InstrumentedAgent
{
    private const string LARAVEL_AI_ATTRIBUTES = 'Laravel\\Ai\\Attributes\\';

    /** @var array<class-string, string|null> */
    private static array $checked = [];

    /**
     * @param  class-string  $agent
     *
     * @throws UnfaithfulInstrumentation when the agent's Laravel AI attributes differ from its parent's
     */
    public static function assertFaithful(string $agent): void
    {
        $violation = self::violation($agent);

        if ($violation !== null) {
            throw new UnfaithfulInstrumentation($violation);
        }
    }

    /**
     * Why the agent does not mirror the production agent it extends, or null
     * when it does (or extends nothing).
     *
     * @param  class-string  $agent
     */
    public static function violation(string $agent): ?string
    {
        if (array_key_exists($agent, self::$checked)) {
            return self::$checked[$agent];
        }

        $class = new ReflectionClass($agent);
        $parent = $class->getParentClass();
        $violation = null;

        if ($parent !== false) {
            $instrumented = self::attributes($class);
            $production = self::attributes($parent);
            $missing = array_values(array_diff($production, $instrumented));
            $added = array_values(array_diff($instrumented, $production));

            if ($missing !== [] || $added !== []) {
                $violation = sprintf(
                    'Benchmark agent [%s] must declare the same Laravel AI attributes as [%s], which it instruments; PHP does not inherit class attributes, so the benchmark would send a different request than production.%s%s',
                    $class->getName(),
                    $parent->getName(),
                    $missing === [] ? '' : ' Missing: '.implode(', ', $missing).'.',
                    $added === [] ? '' : ' Not on the production agent: '.implode(', ', $added).'.',
                );
            }
        }

        return self::$checked[$agent] = $violation;
    }

    /**
     * Laravel AI attributes declared on exactly this class, with arguments.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private static function attributes(ReflectionClass $class): array
    {
        $attributes = array_map(
            static fn (ReflectionAttribute $attribute): string => '#['.substr($attribute->getName(), strlen(self::LARAVEL_AI_ATTRIBUTES))
                .($attribute->getArguments() === [] ? '' : '('.self::arguments($attribute->getArguments()).')')
                .']',
            array_values(array_filter(
                $class->getAttributes(),
                static fn (ReflectionAttribute $attribute): bool => str_starts_with($attribute->getName(), self::LARAVEL_AI_ATTRIBUTES),
            )),
        );
        sort($attributes);

        return $attributes;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    private static function arguments(array $arguments): string
    {
        $rendered = [];

        foreach ($arguments as $name => $value) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $rendered[] = (is_string($name) ? $name.': ' : '').($encoded === false ? get_debug_type($value) : $encoded);
        }

        return implode(', ', $rendered);
    }
}
