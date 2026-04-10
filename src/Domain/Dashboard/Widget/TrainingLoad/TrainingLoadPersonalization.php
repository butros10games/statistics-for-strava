<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final readonly class TrainingLoadPersonalization
{
    private function __construct(
        private int $readinessAdjustment,
        private float $forecastLoadFactor,
        private ?string $headline,
        private ?string $summary,
    ) {
    }

    public static function neutral(): self
    {
        return new self(0, 1.0, null, null);
    }

    public static function fromAdjustment(int $readinessAdjustment, float $forecastLoadFactor, string $headline, string $summary): self
    {
        return new self($readinessAdjustment, $forecastLoadFactor, $headline, $summary);
    }

    public function isActive(): bool
    {
        return 0 !== $this->readinessAdjustment || abs($this->forecastLoadFactor - 1.0) >= 0.01;
    }

    public function getReadinessAdjustment(): int
    {
        return $this->readinessAdjustment;
    }

    public function getForecastLoadFactor(): float
    {
        return $this->forecastLoadFactor;
    }

    public function getHeadline(): ?string
    {
        return $this->headline;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }
}