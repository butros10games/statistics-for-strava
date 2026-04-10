<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\SportType\SportType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionActivityLinker;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

final class PlannedSessionActivityLinkerTest extends ContainerTestCase
{
    public function testItAutoLinksMatchingActivity(): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        /** @var PlannedSessionActivityLinker $plannedSessionActivityLinker */
        $plannedSessionActivityLinker = $this->getContainer()->get(PlannedSessionActivityLinker::class);

        $plannedSessionId = PlannedSessionId::random();
        $plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: $plannedSessionId,
            day: SerializableDateTime::fromString('2026-04-12 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Morning run',
            notes: null,
            targetLoad: null,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
        ));

        $activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('run-1'))
                ->withName('Morning run')
                ->withSportType(SportType::RUN)
                ->withStartDateTime(SerializableDateTime::fromString('2026-04-12 08:00:00'))
                ->withMovingTimeInSeconds(3600)
                ->build(),
            rawData: [],
        ));

        $plannedSessionActivityLinker->syncDay(SerializableDateTime::fromString('2026-04-12 00:00:00'));

        $linkedPlannedSession = $plannedSessionRepository->findById($plannedSessionId);

        self::assertNotNull($linkedPlannedSession);
        self::assertSame(PlannedSessionLinkStatus::LINKED, $linkedPlannedSession->getLinkStatus());
        self::assertSame('activity-run-1', (string) $linkedPlannedSession->getLinkedActivityId());
    }
}