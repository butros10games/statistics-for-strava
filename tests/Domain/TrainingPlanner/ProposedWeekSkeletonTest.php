<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedWeekSkeleton;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class ProposedWeekSkeletonTest extends TestCase
{
    public function testFormatsReadableDisciplineDurationsAndLoadPercentage(): void
    {
        $week = ProposedWeekSkeleton::create(
            weekNumber: 1,
            startDay: SerializableDateTime::fromString('2026-10-12 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-10-18 00:00:00'),
            sessions: [
                ProposedSession::create(
                    day: SerializableDateTime::fromString('2026-10-13 00:00:00'),
                    activityType: ActivityType::WATER_SPORTS,
                    targetIntensity: PlannedSessionIntensity::EASY,
                    title: 'Pool endurance',
                    targetDurationInSeconds: 2700,
                ),
                ProposedSession::create(
                    day: SerializableDateTime::fromString('2026-10-15 00:00:00'),
                    activityType: ActivityType::RIDE,
                    targetIntensity: PlannedSessionIntensity::MODERATE,
                    title: 'Bike endurance',
                    targetDurationInSeconds: 5400,
                ),
                ProposedSession::create(
                    day: SerializableDateTime::fromString('2026-10-17 00:00:00'),
                    activityType: ActivityType::RUN,
                    targetIntensity: PlannedSessionIntensity::RACE,
                    title: 'Half marathon',
                    notes: 'B race',
                    targetDurationInSeconds: 4200,
                ),
            ],
            targetLoadMultiplier: 0.84,
            isManuallyPlanned: true,
            isRecoveryWeek: true,
        );

        self::assertSame(84, $week->getTargetLoadPercentage());
        self::assertSame('45m', $week->getFormattedTargetDurationForActivityType(ActivityType::WATER_SPORTS));
        self::assertSame('1h 30m', $week->getFormattedTargetDurationForActivityType(ActivityType::RIDE));
        self::assertSame('1h 10m', $week->getFormattedTargetDurationForActivityType(ActivityType::RUN));
        self::assertTrue($week->isManuallyPlanned());
        self::assertTrue($week->isRecoveryWeek());
        self::assertTrue($week->hasRaceEffortSession());
        self::assertSame('B race · Half marathon', $week->getRaceSummaryLabel());
    }
}
