<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\Athlete\AthleteRepository;
use App\Domain\Ftp\FtpHistory;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\Measurement\Time\Seconds;

final readonly class ActivityTrainingLoadCalculator
{
    public function __construct(
        private ActivityIntensity $activityIntensity,
        private FtpHistory $ftpHistory,
        private AthleteRepository $athleteRepository,
    ) {
    }

    public function calculate(Activity $activity): int
    {
        $load = 0.0;
        $movingTimeInSeconds = $activity->getMovingTimeInSeconds();

        if (ActivityType::RIDE === $activity->getSportType()->getActivityType() && ($normalizedPower = $activity->getNormalizedPower())) {
            try {
                $intensity = $this->activityIntensity->calculatePowerBased($activity->getId()) / 100;
                $ftp = $this->ftpHistory->find(ActivityType::RIDE, $activity->getStartDate())->getFtp();

                $load += ($movingTimeInSeconds * $normalizedPower * $intensity) / ($ftp->getValue() * 3600) * 100;

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