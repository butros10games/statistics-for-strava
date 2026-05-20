<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityType;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class TrainingPlannerActivityIndex
{
    /**
     * @param list<Activity> $activities
     */
    private function __construct(
        private array $activities,
    ) {
    }

    /**
     * @param iterable<Activity> $activities
     */
    public static function fromActivities(iterable $activities): self
    {
        return new self(array_values(iterator_to_array($activities)));
    }

    /**
     * @return list<Activity>
     */
    public function all(): array
    {
        return $this->activities;
    }

    /**
     * @return list<Activity>
     */
    public function byActivityType(ActivityType $activityType): array
    {
        return array_values(array_filter(
            $this->activities,
            static fn (Activity $activity): bool => $activity->getSportType()->getActivityType() === $activityType,
        ));
    }

    /**
     * @return list<Activity>
     */
    public function byDateRange(DateRange $dateRange): array
    {
        return array_values(array_filter(
            $this->activities,
            static fn (Activity $activity): bool => $activity->getStartDate() >= $dateRange->getFrom() && $activity->getStartDate() <= $dateRange->getTill(),
        ));
    }

    /**
     * @return list<Activity>
     */
    public function byDateRangeAndActivityType(DateRange $dateRange, ActivityType $activityType): array
    {
        return array_values(array_filter(
            $this->byDateRange($dateRange),
            static fn (Activity $activity): bool => $activity->getSportType()->getActivityType() === $activityType,
        ));
    }

    /**
     * @return list<Activity>
     */
    public function byDayAndActivityType(SerializableDateTime $day, ActivityType $activityType): array
    {
        $formattedDay = $day->format('Y-m-d');

        return array_values(array_filter(
            $this->byActivityType($activityType),
            static fn (Activity $activity): bool => $activity->getStartDate()->format('Y-m-d') === $formattedDay,
        ));
    }
}