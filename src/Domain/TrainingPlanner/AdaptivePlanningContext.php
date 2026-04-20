<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class AdaptivePlanningContext
{
    public function __construct(
        private RaceReadinessContext $currentWeekReadinessContext,
        private ?float $historicalWeeklyRunningVolume,
        private ?float $historicalWeeklyBikingVolume,
    ) {
    }

    public function getCurrentWeekReadinessContext(): RaceReadinessContext
    {
        return $this->currentWeekReadinessContext;
    }

    public function getHistoricalWeeklyRunningVolume(): ?float
    {
        return $this->historicalWeeklyRunningVolume;
    }

    public function getHistoricalWeeklyBikingVolume(): ?float
    {
        return $this->historicalWeeklyBikingVolume;
    }
}
