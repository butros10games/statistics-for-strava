<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\Athlete\AthleteRepository;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\Measurement\Time\Seconds;

final readonly class ActivityTrainingLoadCalculator
{
    public function __construct(
        private ActivityIntensity $activityIntensity,
        private PerformanceAnchorHistory $performanceAnchorHistory,
        private AthleteRepository $athleteRepository,
    ) {
    }

    public function calculate(Activity $activity): int
    {
        $load = 0.0;
        $movingTimeInSeconds = $activity->getMovingTimeInSeconds();
        $activityType = $activity->getSportType()->getActivityType();

        if ($activityType->supportsPowerData() && ($normalizedPower = $activity->getNormalizedPower())) {
            try {
                $intensity = $this->activityIntensity->calculatePowerBased($activity->getId()) / 100;
                $thresholdPower = $this->performanceAnchorHistory->find(
                    PerformanceAnchorType::fromActivityType($activityType),
                    $activity->getStartDate(),
                )->getValue();

                $load += ($movingTimeInSeconds * $normalizedPower * $intensity) / ($thresholdPower * 3600) * 100;

                return (int) round($load);
            } catch (CouldNotDetermineActivityIntensity|EntityNotFound) {
            }
        }

        try {
            $intensity = $this->activityIntensity->calculateHeartRateBased($activity->getId()) / 100;
            $bannisterKFactor = $this->athleteRepository->find()->isMale() ? 1.92 : 1.67;
            $load += Seconds::from($movingTimeInSeconds)->toMinute()->toFloat() * $intensity * exp($bannisterKFactor * $intensity);
        } catch (CouldNotDetermineActivityIntensity) {
        }

        return (int) round($load);
    }
}