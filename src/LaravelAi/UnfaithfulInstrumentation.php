<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\LaravelAi;

use LogicException;

/**
 * An instrumented benchmark agent would send a different request than the
 * production agent it extends. The trial fails instead of measuring it.
 */
final class UnfaithfulInstrumentation extends LogicException {}
