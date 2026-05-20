<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\SportType\SportType;
use App\Domain\TrainingPlanner\TrainingPlannerActivityIndex;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Domain\Activity\ActivityBuilder;
use PHPUnit\Framework\TestCase;

final class TrainingPlannerActivityIndexTest extends TestCase
{
    public function testByDayAndActivityTypeReturnsOnlyMatchingActivities(): void
    {
        $index = TrainingPlannerActivityIndex::fromActivities([
            $this->createActivity('run-1', '2026-04-12 08:00:00', SportType::RUN),
            $this->createActivity('ride-1', '2026-04-12 09:00:00', SportType::RIDE),
            $this->createActivity('run-2', '2026-04-13 08:00:00', SportType::RUN),
        ]);

        $matches = $index->byDayAndActivityType(
            SerializableDateTime::fromString('2026-04-12 00:00:00'),
            ActivityType::RUN,
        );

        self::assertCount(1, $matches);
        self::assertSame('activity-run-1', (string) $matches[0]->getId());
    }

    public function testByDateRangeAndActivityTypeReturnsOnlyActivitiesInsideWindow(): void
    {
        $index = TrainingPlannerActivityIndex::fromActivities([
            $this->createActivity('run-before', '2026-04-09 08:00:00', SportType::RUN),
            $this->createActivity('run-inside', '2026-04-12 08:00:00', SportType::RUN),
            $this->createActivity('ride-inside', '2026-04-12 09:00:00', SportType::RIDE),
            $this->createActivity('run-after', '2026-04-16 08:00:00', SportType::RUN),
        ]);

        $matches = $index->byDateRangeAndActivityType(
            DateRange::fromDates(
                SerializableDateTime::fromString('2026-04-10 00:00:00'),
                SerializableDateTime::fromString('2026-04-14 23:59:59'),
            ),
            ActivityType::RUN,
        );

        self::assertCount(1, $matches);
        self::assertSame('activity-run-inside', (string) $matches[0]->getId());
    }

    private function createActivity(string $idSuffix, string $startDate, SportType $sportType): \App\Domain\Activity\Activity
    {
        return ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed($idSuffix))
            ->withSportType($sportType)
            ->withStartDateTime(SerializableDateTime::fromString($startDate))
            ->build();
    }
}
