<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimatedLoadMapBuilder;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Tests\ContainerTestCase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class PlannedSessionEstimatedLoadMapBuilderTest extends ContainerTestCase
{
    public function testBuildMapsEstimatedLoadsByPlannedSessionId(): void
    {
        /** @var PlannedSessionEstimatedLoadMapBuilder $builder */
        $builder = $this->getContainer()->get(PlannedSessionEstimatedLoadMapBuilder::class);
        $plannedSessions = [
            $this->createPlannedSession('2023-10-18 00:00:00', 'Wednesday threshold run', 42.0),
            $this->createPlannedSession('2023-10-20 00:00:00', 'Friday bike primer', 55.0),
        ];

        $estimatesById = $builder->build($plannedSessions);

        self::assertSame(42.0, $estimatesById[(string) $plannedSessions[0]->getId()]);
        self::assertSame(55.0, $estimatesById[(string) $plannedSessions[1]->getId()]);
    }

    private function createPlannedSession(string $day, string $title, float $targetLoad): PlannedSession
    {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: ActivityType::RUN,
            title: $title,
            notes: null,
            targetLoad: $targetLoad,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }
}