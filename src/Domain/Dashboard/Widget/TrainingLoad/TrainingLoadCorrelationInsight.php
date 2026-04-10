<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final readonly class TrainingLoadCorrelationInsight
{
    public function __construct(
        private string $key,
        private string $title,
        private string $summary,
        private float $correlation,
        private int $sampleSize,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getCorrelation(): float
    {
        return $this->correlation;
    }

    public function getSampleSize(): int
    {
        return $this->sampleSize;
    }

    public function getStrengthLabel(): string
    {
        return match (true) {
            abs($this->correlation) >= 0.55 => 'Strong signal',
            default => 'Moderate signal',
        };
    }

    public function getStrengthPillColors(): string
    {
        return match (true) {
            abs($this->correlation) >= 0.55 => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-700',
        };
    }
}