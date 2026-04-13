<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\Athlete\AthleteRepository;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Infrastructure\Exception\EntityNotFound;

final class ActivityIntensity
{
    /** @var array<string, int|null> */
    public static array $cachedIntensities = [];

    public static function reset(): void
    {
        self::$cachedIntensities = [];
    }

    public function __construct(
        private readonly EnrichedActivities $enrichedActivities,
        private readonly AthleteRepository $athleteRepository,
        private readonly PerformanceAnchorHistory $performanceAnchorHistory,
    ) {
    }

    public function calculate(ActivityId $activityId): int
    {
        $cacheKey = (string) $activityId;
        if (array_key_exists($cacheKey, self::$cachedIntensities) && null !== self::$cachedIntensities[$cacheKey]) {
            return self::$cachedIntensities[$cacheKey];
        }

        try {
            return $this->calculatePowerBased($activityId);
        } catch (CouldNotDetermineActivityIntensity|EntityNotFound) {
        }

        try {
            return $this->calculateHeartRateBased($activityId);
        } catch (CouldNotDetermineActivityIntensity|EntityNotFound) {
        }

        self::$cachedIntensities[$cacheKey] = 0;

        return 0;
    }

    public function calculatePowerBased(ActivityId $activityId): int
    {
        $activity = $this->enrichedActivities->find($activityId);
        $activityType = $activity->getSportType()->getActivityType();
        if (!$activityType->supportsPowerData()) {
            throw new CouldNotDetermineActivityIntensity('Activity does not support power-based intensity');
        }

        $cacheKey = (string) $activity->getId();
        if (array_key_exists($cacheKey, self::$cachedIntensities) && null !== self::$cachedIntensities[$cacheKey]) {
            return self::$cachedIntensities[$cacheKey];
        }

        if (!$normalizedPower = $activity->getNormalizedPower()) {
            throw new CouldNotDetermineActivityIntensity('Activity has no normalized power');
        }

        try {
            $thresholdPower = $this->performanceAnchorHistory->find(
                PerformanceAnchorType::fromActivityType($activityType),
                $activity->getStartDate(),
            )->getValue();
            // IF = Normalized Power / Threshold Power
            $intensity = (int) round(($normalizedPower / $thresholdPower) * 100);
            self::$cachedIntensities[$cacheKey] = $intensity;

            return self::$cachedIntensities[$cacheKey];
        } catch (EntityNotFound) {
        }

        throw new CouldNotDetermineActivityIntensity('Threshold power not found');
    }

    public function calculateHeartRateBased(ActivityId $activityId): int
    {
        $cacheKey = (string) $activityId;
        if (array_key_exists($cacheKey, self::$cachedIntensities) && null !== self::$cachedIntensities[$cacheKey]) {
            return self::$cachedIntensities[$cacheKey];
        }

        $activity = $this->enrichedActivities->find($activityId);
        if (!$averageHeartRate = $activity->getAverageHeartRate()) {
            throw new CouldNotDetermineActivityIntensity();
        }

        $athlete = $this->athleteRepository->find();
        $athleteRestingHeartRate = $athlete->getRestingHeartRateFormula($activity->getStartDate());
        $athleteMaxHeartRate = $athlete->getMaxHeartRate($activity->getStartDate());

        $intensity = (int) round(($averageHeartRate - $athleteRestingHeartRate) / ($athleteMaxHeartRate - $athleteRestingHeartRate) * 100);
        self::$cachedIntensities[$cacheKey] = $intensity;

        return self::$cachedIntensities[$cacheKey];
    }
}
