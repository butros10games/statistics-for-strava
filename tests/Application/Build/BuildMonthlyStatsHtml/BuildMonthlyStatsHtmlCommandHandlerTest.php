<?php

namespace App\Tests\Application\Build\BuildMonthlyStatsHtml;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Application\BuildAppFilesTestCase;

class BuildMonthlyStatsHtmlCommandHandlerTest extends BuildAppFilesTestCase
{
    public function testHandle(): void
    {
        $this->provideFullTestSet();

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml(SerializableDateTime::fromString('2023-10-17 16:15:04')));
        $this->assertFileSystemWrites($this->getContainer()->get('build.storage'));
    }

    public function testHandleBuildsFuturePlannerMonth(): void
    {
        $this->provideFullTestSet();

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2023-11-04 00:00:00'),
            activityType: ActivityType::RIDE,
            title: 'Future long ride',
            notes: null,
            targetLoad: 55.0,
            targetDurationInSeconds: 7200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-10 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-10 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml(SerializableDateTime::fromString('2023-10-17 16:15:04')));

        $buildStorage = $this->getContainer()->get('build.storage');

        self::assertTrue($buildStorage->fileExists('month/month-2023-11.html'));
        self::assertStringContainsString('Future long ride', $buildStorage->read('month/month-2023-11.html'));
        self::assertStringContainsString('Training calendar', $buildStorage->read('monthly-stats.html'));
    }
}
