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
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
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
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-10 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-10 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml(SerializableDateTime::fromString('2023-10-17 16:15:04')));

        $buildStorage = $this->getContainer()->get('build.storage');

        self::assertTrue($buildStorage->fileExists('month/month-2023-11.html'));
        self::assertTrue($buildStorage->fileExists('monthly-stats/month-2023-11.html'));
        self::assertStringContainsString('Future long ride', $buildStorage->read('month/month-2023-11.html'));
        self::assertStringContainsString('Future long ride', $buildStorage->read('monthly-stats/month-2023-11.html'));
        self::assertStringContainsString('data-router-navigate="/monthly-stats/month-2023-11"', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Month planner', $buildStorage->read('monthly-stats.html'));
    }

    public function testHandleBuildsFutureRaceMonthAndUpcomingRaceList(): void
    {
        $this->provideFullTestSet();

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $raceEventRepository->upsert(RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2023-12-03 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Ironman 70.3 Lanzarote',
            location: 'Lanzarote',
            notes: 'Keep the bike conservative.',
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18900,
            createdAt: SerializableDateTime::fromString('2023-10-10 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-10 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml(SerializableDateTime::fromString('2023-10-17 16:15:04')));

        $buildStorage = $this->getContainer()->get('build.storage');

        self::assertTrue($buildStorage->fileExists('month/month-2023-12.html'));
        self::assertStringContainsString('Ironman 70.3 Lanzarote', $buildStorage->read('month/month-2023-12.html'));
        self::assertStringContainsString('Race targets', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Ironman 70.3 Lanzarote', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('D-47', $buildStorage->read('monthly-stats.html'));
    }

    public function testHandleBuildsTrainingBlocksIntoMonthlyStats(): void
    {
        $this->provideFullTestSet();

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $raceEvent = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2023-11-12 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'November target race',
            location: 'Girona',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 18000,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
        $raceEventRepository->upsert($raceEvent);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2023-10-10 00:00:00'),
            endDay: SerializableDateTime::fromString('2023-10-24 00:00:00'),
            targetRaceEventId: $raceEvent->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'October build block',
            focus: 'Bike durability and threshold support',
            notes: 'Keep the long run controlled.',
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2023-10-18 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Wednesday threshold run',
            notes: null,
            targetLoad: 42.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::HARD,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        ));
        $plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2023-10-20 00:00:00'),
            activityType: ActivityType::RIDE,
            title: 'Friday bike primer',
            notes: null,
            targetLoad: 55.0,
            targetDurationInSeconds: 4200,
            targetIntensity: PlannedSessionIntensity::HARD,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        ));
        $plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2023-10-20 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Friday track reps',
            notes: null,
            targetLoad: 55.0,
            targetDurationInSeconds: 4200,
            targetIntensity: PlannedSessionIntensity::HARD,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        ));
        $plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2023-10-22 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Sunday long run',
            notes: null,
            targetLoad: 48.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        ));

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml(SerializableDateTime::fromString('2023-10-17 16:15:04')));

        $buildStorage = $this->getContainer()->get('build.storage');

        self::assertStringContainsString('Training blocks', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Bike durability and threshold support', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('October build block', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('November target race', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Current week', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Coach notes', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Brick structure is in the week', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('70.3 specificity is showing', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('The week includes endurance or bike-run specificity that fits a half-distance target.', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Key session', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Brick', $buildStorage->read('monthly-stats.html'));
        self::assertStringContainsString('Wednesday threshold run', $buildStorage->read('monthly-stats.html'));
    }

    public function testHandleBuildsBusyTaperCueIntoMonthlyStats(): void
    {
        $this->provideFullTestSet();

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert($this->createTrainingBlock(
            startDay: '2023-10-16 00:00:00',
            endDay: '2023-10-22 00:00:00',
            phase: TrainingBlockPhase::TAPER,
            title: 'October taper block',
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-18 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Wednesday sharpening run',
            targetLoad: 72.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-20 00:00:00',
            activityType: ActivityType::RIDE,
            title: 'Friday bike opener',
            targetLoad: 88.0,
            targetDurationInSeconds: 4800,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));

        $monthlyStatsHtml = $this->buildMonthlyStatsHtml();

        self::assertStringContainsString('October taper block', $monthlyStatsHtml);
        self::assertStringContainsString('Taper load looks busy', $monthlyStatsHtml);
        self::assertStringContainsString('This taper still stacks stress.', $monthlyStatsHtml);
    }

    public function testHandleBuildsControlledTaperCueIntoMonthlyStats(): void
    {
        $this->provideFullTestSet();

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert($this->createTrainingBlock(
            startDay: '2023-10-16 00:00:00',
            endDay: '2023-10-22 00:00:00',
            phase: TrainingBlockPhase::TAPER,
            title: 'October taper block',
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-18 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Wednesday easy jog',
            targetLoad: 24.0,
            targetDurationInSeconds: 2100,
            targetIntensity: PlannedSessionIntensity::EASY,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-20 00:00:00',
            activityType: ActivityType::RIDE,
            title: 'Friday race prep spin',
            targetLoad: 42.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));

        $monthlyStatsHtml = $this->buildMonthlyStatsHtml();

        self::assertStringContainsString('October taper block', $monthlyStatsHtml);
        self::assertStringContainsString('Taper looks controlled', $monthlyStatsHtml);
        self::assertStringContainsString('The load is light enough to protect freshness while keeping a bit of rhythm in the legs.', $monthlyStatsHtml);
    }

    public function testHandlePrefersTrainingBlockTargetRaceOverCurrentWeekRaceEvent(): void
    {
        $this->provideFullTestSet();

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $trainingBlockTargetRace = $this->createRaceEvent(
            day: '2023-11-12 00:00:00',
            type: RaceEventType::FULL_DISTANCE_TRIATHLON,
            title: 'A-race Iron-distance',
        );
        $raceEventRepository->upsert($trainingBlockTargetRace);
        $raceEventRepository->upsert($this->createRaceEvent(
            day: '2023-10-19 00:00:00',
            type: RaceEventType::RUN,
            title: 'Local 10K tune-up',
        ));

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert($this->createTrainingBlock(
            startDay: '2023-10-09 00:00:00',
            endDay: '2023-10-29 00:00:00',
            phase: TrainingBlockPhase::BUILD,
            title: 'October long-course build',
            targetRaceEventId: $trainingBlockTargetRace->getId(),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-18 00:00:00',
            activityType: ActivityType::RIDE,
            title: 'Wednesday long ride',
            targetLoad: 82.0,
            targetDurationInSeconds: 7200,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-20 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Friday long run',
            targetLoad: 64.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));

        $monthlyStatsHtml = $this->buildMonthlyStatsHtml();

        self::assertStringContainsString('A-race Iron-distance', $monthlyStatsHtml);
        self::assertStringContainsString('Long-course intent is visible', $monthlyStatsHtml);
        self::assertStringContainsString('There is enough long-course flavor here to support a full-distance target.', $monthlyStatsHtml);
        self::assertStringNotContainsString('Run-race focus', $monthlyStatsHtml);
    }

    public function testHandleBuildsFiveKilometerIntentIntoMonthlyStats(): void
    {
        $this->provideFullTestSet();

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $raceEventRepository->upsert($this->createRaceEvent(
            day: '2023-10-19 00:00:00',
            type: RaceEventType::RUN_5K,
            title: 'Thursday night 5K',
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-18 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Wednesday track reps',
            targetLoad: 56.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-20 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Friday threshold run',
            targetLoad: 58.0,
            targetDurationInSeconds: 3900,
            targetIntensity: PlannedSessionIntensity::HARD,
        ));

        $monthlyStatsHtml = $this->buildMonthlyStatsHtml();

        self::assertStringContainsString('Thursday night 5K', $monthlyStatsHtml);
        self::assertStringContainsString('5K sharpness is showing', $monthlyStatsHtml);
        self::assertStringContainsString('The week has enough run-specific intensity to support a sharp 5K tune-up race.', $monthlyStatsHtml);
    }

    public function testHandleBuildsHalfMarathonIntentIntoMonthlyStats(): void
    {
        $this->provideFullTestSet();

        /** @var RaceEventRepository $raceEventRepository */
        $raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $targetRace = $this->createRaceEvent(
            day: '2023-11-12 00:00:00',
            type: RaceEventType::HALF_MARATHON,
            title: 'City half marathon',
        );
        $raceEventRepository->upsert($targetRace);

        /** @var TrainingBlockRepository $trainingBlockRepository */
        $trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $trainingBlockRepository->upsert($this->createTrainingBlock(
            startDay: '2023-10-16 00:00:00',
            endDay: '2023-10-29 00:00:00',
            phase: TrainingBlockPhase::BUILD,
            title: 'Half-marathon build block',
            targetRaceEventId: $targetRace->getId(),
        ));

        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-18 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Wednesday steady run',
            targetLoad: 46.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-10-22 00:00:00',
            activityType: ActivityType::RUN,
            title: 'Sunday long run',
            targetLoad: 64.0,
            targetDurationInSeconds: 5400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        ));

        $monthlyStatsHtml = $this->buildMonthlyStatsHtml();

        self::assertStringContainsString('City half marathon', $monthlyStatsHtml);
        self::assertStringContainsString('Half-marathon durability is showing', $monthlyStatsHtml);
        self::assertStringContainsString('The week includes the run durability that usually supports a strong half marathon build.', $monthlyStatsHtml);
    }

    private function buildMonthlyStatsHtml(string $now = '2023-10-17 16:15:04'): string
    {
        $this->commandBus->dispatch(new BuildMonthlyStatsHtml(SerializableDateTime::fromString($now)));

        return $this->getContainer()->get('build.storage')->read('monthly-stats.html');
    }

    private function createPlannedSession(
        string $day,
        ActivityType $activityType,
        string $title,
        float $targetLoad,
        int $targetDurationInSeconds,
        PlannedSessionIntensity $targetIntensity,
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: $activityType,
            title: $title,
            notes: null,
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }

    private function createRaceEvent(
        string $day,
        RaceEventType $type,
        string $title,
    ): RaceEvent {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString($day),
            type: $type,
            title: $title,
            location: null,
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: null,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }

    private function createTrainingBlock(
        string $startDay,
        string $endDay,
        TrainingBlockPhase $phase,
        string $title,
        ?RaceEventId $targetRaceEventId = null,
    ): TrainingBlock {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString($startDay),
            endDay: SerializableDateTime::fromString($endDay),
            targetRaceEventId: $targetRaceEventId,
            phase: $phase,
            title: $title,
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }
}
