<?php

namespace App\Domain\Activity;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class DailyTrainingLoad
{
    /** @var array<string, int|null> */
    public static array $cachedLoad = [];

    public function __construct(
        private readonly EnrichedActivities $enrichedActivities,
        private readonly ActivityTrainingLoadCalculator $activityTrainingLoadCalculator,
    ) {
    }

    public function calculate(SerializableDateTime $on): int
    {
        $cacheKey = $on->format('Y-m-d');
        if (array_key_exists($cacheKey, self::$cachedLoad) && null !== self::$cachedLoad[$cacheKey]) {
            return self::$cachedLoad[$cacheKey];
        }

        $activities = $this->enrichedActivities->findByStartDate(
            startDate: $on,
            activityType: null
        );
        $load = 0;

        /** @var Activity $activity */
        foreach ($activities as $activity) {
            $load += $this->activityTrainingLoadCalculator->calculate($activity);
        }

        self::$cachedLoad[$cacheKey] = (int) round($load);

        return self::$cachedLoad[$cacheKey];
    }
}
