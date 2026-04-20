<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

final readonly class RunningRaceBenchmarkPrediction
{
    public function __construct(
        private string $label,
        private int $distanceInMeters,
        private int $currentFinishTimeInSeconds,
        private int $projectedFinishTimeInSeconds,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDistanceInMeters(): int
    {
        return $this->distanceInMeters;
    }

    public function getCurrentFinishTimeInSeconds(): int
    {
        return $this->currentFinishTimeInSeconds;
    }

    public function getProjectedFinishTimeInSeconds(): int
    {
        return $this->projectedFinishTimeInSeconds;
    }
}