<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionActivityMatcher;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

final class PlannedSessionActivityMatcherTest extends ContainerTestCase
{
    private PlannedSessionActivityMatcher $matcher;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new PlannedSessionActivityMatcher(
            $this->getContainer()->get(DbalActivityRepository::class),
        );
    }

    public function testItSuggestsSingleSameDaySameTypeActivity(): void
    {
        $this->seedActivity('run-1', '2026-04-12 08:00:00', 'Morning run', 3600);

        $match = $this->matcher->findSuggestedMatch($this->createPlannedSession(
            day: '2026-04-12 00:00:00',
            title: 'Morning run',
            targetDurationInSeconds: 3600,
        ));

        self::assertNotNull($match);
        self::assertSame('activity-run-1', (string) $match->getId());
    }

    public function testItUsesDurationToResolveMultipleActivities(): void
    {
        $this->seedActivity('run-short', '2026-04-12 07:00:00', 'Short run', 1800);
        $this->seedActivity('run-long', '2026-04-12 09:00:00', 'Long run', 5100);

        $match = $this->matcher->findSuggestedMatch($this->createPlannedSession(
            day: '2026-04-12 00:00:00',
            title: 'Sunday long run',
            targetDurationInSeconds: 5400,
        ));

        self::assertNotNull($match);
        self::assertSame('activity-run-long', (string) $match->getId());
    }

    public function testItReturnsNullWhenMultipleCandidatesRemainAmbiguous(): void
    {
        $this->seedActivity('run-a', '2026-04-12 07:00:00', 'Morning run', 3600);
        $this->seedActivity('run-b', '2026-04-12 09:00:00', 'Evening run', 3650);

        $match = $this->matcher->findSuggestedMatch($this->createPlannedSession(
            day: '2026-04-12 00:00:00',
            title: null,
            targetDurationInSeconds: null,
        ));

        self::assertNull($match);
    }

    private function createPlannedSession(string $day, ?string $title, ?int $targetDurationInSeconds): PlannedSession
    {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: ActivityType::RUN,
            title: $title,
            notes: null,
            targetLoad: null,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
        );
    }

    private function seedActivity(string $idSuffix, string $startDate, string $name, int $movingTimeInSeconds): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed($idSuffix))
                ->withName($name)
                ->withSportType(SportType::RUN)
                ->withStartDateTime(SerializableDateTime::fromString($startDate))
                ->withMovingTimeInSeconds($movingTimeInSeconds)
                ->build(),
            rawData: [],
        ));
    }
}