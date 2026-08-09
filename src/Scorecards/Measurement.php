<?php

declare(strict_types=1);

namespace Jkudish\PestAiBenchmarks\Scorecards;

use InvalidArgumentException;
use Jkudish\PestAiBenchmarks\Results\StableEvidenceSanitizer;

/** @internal */
final readonly class Measurement
{
    public const int MAX_IDENTITY_BYTES = 1_024;

    private NormalizedObject $usage;

    private NormalizedObject $pricingSnapshot;

    /**
     * @param  array<mixed>  $usage
     * @param  array<mixed>  $pricingSnapshot
     */
    public function __construct(
        public Component $component,
        public ExecutionMode $mode,
        public ?string $requestedProvider,
        public ?string $requestedModel,
        public ?string $effectiveProvider,
        public ?string $effectiveModel,
        public float $latencyMs,
        array $usage,
        public int $retries,
        public PricingCompleteness $pricingCompleteness,
        array $pricingSnapshot,
        public string $fingerprint,
    ) {
        self::validateModelIdentity($this->requestedProvider, $this->requestedModel, 'Requested');
        self::validateModelIdentity($this->effectiveProvider, $this->effectiveModel, 'Effective');

        if (! is_finite($this->latencyMs) || $this->latencyMs < 0) {
            throw new InvalidArgumentException('Measurement latency must be a finite, non-negative number.');
        }

        if ($this->retries < 0) {
            throw new InvalidArgumentException('Measurement retries must be non-negative.');
        }

        if (trim($this->fingerprint) === '') {
            throw new InvalidArgumentException('Measurement fingerprint must not be empty.');
        }

        $this->usage = new NormalizedObject($usage);
        $this->pricingSnapshot = new NormalizedObject($pricingSnapshot);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'component' => $this->component->value,
            'mode' => $this->mode->value,
            'requested_model' => self::modelIdentity($this->requestedProvider, $this->requestedModel),
            'effective_model' => self::modelIdentity($this->effectiveProvider, $this->effectiveModel),
            'latency_ms' => $this->latencyMs,
            'usage' => $this->usage->toJsonValue(),
            'retries' => $this->retries,
            'pricing' => [
                'completeness' => $this->pricingCompleteness->value,
                'snapshot' => $this->pricingSnapshot->toJsonValue(),
            ],
            'fingerprint' => $this->fingerprint,
        ];
    }

    private static function validateModelIdentity(?string $provider, ?string $model, string $label): void
    {
        if (($provider === null) !== ($model === null)) {
            throw new InvalidArgumentException("{$label} provider and model must either both be present or both be absent.");
        }

        if ($provider !== null && (trim($provider) === '' || trim((string) $model) === '')) {
            throw new InvalidArgumentException("{$label} provider and model must not be empty.");
        }
    }

    /** @return array{provider: string, model: string}|null */
    private static function modelIdentity(?string $provider, ?string $model): ?array
    {
        if ($provider === null || $model === null) {
            return null;
        }

        return [
            'provider' => StableEvidenceSanitizer::text($provider, self::MAX_IDENTITY_BYTES),
            'model' => StableEvidenceSanitizer::text($model, self::MAX_IDENTITY_BYTES),
        ];
    }
}
