<?php

declare(strict_types=1);

namespace App\Domain\Performance\PerformanceAnchor;

use App\Infrastructure\ValueObject\Measurement\Mass\Kilogram;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class PerformanceAnchor
{
    private ?Kilogram $athleteWeightInKg = null;

    private function __construct(
        private readonly SerializableDateTime $setOn,
        private readonly PerformanceAnchorType $type,
        private readonly float $value,
        private readonly PerformanceAnchorSource $source,
        private readonly PerformanceAnchorConfidence $confidence,
        private readonly int $sampleSize,
    ) {
    }

    public static function fromState(
        SerializableDateTime $setOn,
        PerformanceAnchorType $type,
        float $value,
        PerformanceAnchorSource $source = PerformanceAnchorSource::MANUAL,
        PerformanceAnchorConfidence $confidence = PerformanceAnchorConfidence::HIGH,
        int $sampleSize = 1,
    ): self {
        return new self(
            setOn: $setOn,
            type: $type,
            value: round($value, 2),
            source: $source,
            confidence: $confidence,
            sampleSize: $sampleSize,
        );
    }

    public function getSetOn(): SerializableDateTime
    {
        return $this->setOn;
    }

    public function getType(): PerformanceAnchorType
    {
        return $this->type;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function getSource(): PerformanceAnchorSource
    {
        return $this->source;
    }

    public function getConfidence(): PerformanceAnchorConfidence
    {
        return $this->confidence;
    }

    public function getSampleSize(): int
    {
        return $this->sampleSize;
    }

    public function getRelativeValue(): ?float
    {
        if (!$this->type->isPowerBased() || !$this->athleteWeightInKg instanceof Kilogram) {
            return null;
        }

        return round($this->value / $this->athleteWeightInKg->toFloat(), 1);
    }

    public function withAthleteWeight(?Kilogram $athleteWeight): self
    {
        if (is_null($athleteWeight)) {
            return $this;
        }

        return clone ($this, [
            'athleteWeightInKg' => $athleteWeight,
        ]);
    }
}