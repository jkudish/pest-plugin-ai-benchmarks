<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Comparisons;

/**
 * Internal identity captured by Pest's native test closure for the full test
 * lifecycle. The registry keys weakly to this token, avoiding persistent
 * object-id maps while allowing BenchmarkCall itself to be collected.
 *
 * @internal
 */
final class DeclarationContext {}
