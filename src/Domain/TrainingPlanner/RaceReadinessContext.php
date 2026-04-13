<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessScore;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastConfidence;

final readonly class RaceReadinessContext
{
    /**
     * @param list<array{activityType: ActivityType, count: int}> $activityTypeSummaries
     * @param array{swim: int, bike: int, run: int} $disciplineCounts
     */
    public function __construct(
        private ?RaceEvent $targetRace,
        private ?TrainingBlock $primaryTrainingBlock,
        private ?int $targetRaceCountdownDays,
        private bool $hasRaceEventInContextWindow,
        private float $estimatedLoad,
        private array $activityTypeSummaries,
        private array $disciplineCounts,
        private int $sessionCount,
        private int $distinctSessionDayCount,
        private int $hardSessionCount,
        private int $easySessionCount,
        private int $brickDayCount,
        private bool $hasLongRideSession,
        private bool $hasLongRunSession,
        private ?ReadinessScore $readinessScore,
        private ?TrainingLoadForecastConfidence $forecastConfidence,
        private ?int $forecastDaysUntilTsbHealthy,
        private ?int $forecastDaysUntilAcRatioHealthy,
    ) {
    }

    public function getTargetRace(): ?RaceEvent
    {
        return $this->targetRace;
    }

    public function getPrimaryTrainingBlock(): ?TrainingBlock
    {
        return $this->primaryTrainingBlock;
    }

    public function getTargetRaceCountdownDays(): ?int
    {
        return $this->targetRaceCountdownDays;
    }

    public function hasRaceEventInContextWindow(): bool
    {
        return $this->hasRaceEventInContextWindow;
    }

    public function getEstimatedLoad(): float
    {
        return $this->estimatedLoad;
    }

    /**
     * @return list<array{activityType: ActivityType, count: int}>
     */
    public function getActivityTypeSummaries(): array
    {
        return $this->activityTypeSummaries;
    }

    /**
     * @return array{swim: int, bike: int, run: int}
     */
    public function getDisciplineCounts(): array
    {
        return $this->disciplineCounts;
    }

    public function getSessionCount(): int
    {
        return $this->sessionCount;
    }

    public function getDistinctSessionDayCount(): int
    {
        return $this->distinctSessionDayCount;
    }

    public function getHardSessionCount(): int
    {
        return $this->hardSessionCount;
    }

    public function getEasySessionCount(): int
    {
        return $this->easySessionCount;
    }

    public function getBrickDayCount(): int
    {
        return $this->brickDayCount;
    }

    public function hasLongRideSession(): bool
    {
        return $this->hasLongRideSession;
    }

    public function hasLongRunSession(): bool
    {
        return $this->hasLongRunSession;
    }

    public function getReadinessScore(): ?ReadinessScore
    {
        return $this->readinessScore;
    }

    public function getForecastConfidence(): ?TrainingLoadForecastConfidence
    {
        return $this->forecastConfidence;
    }

    public function getForecastDaysUntilTsbHealthy(): ?int
    {
        return $this->forecastDaysUntilTsbHealthy;
    }

    public function getForecastDaysUntilAcRatioHealthy(): ?int
    {
        return $this->forecastDaysUntilAcRatioHealthy;
    }
}