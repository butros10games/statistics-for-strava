<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Controller\TrainingPlanRequestHandler;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingPlanRepository;
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
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RunningWorkoutTargetMode;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class TrainingPlanRequestHandlerTest extends ContainerTestCase
{
    private TrainingPlanRequestHandler $requestHandler;
    private DbalTrainingPlanRepository $repository;
    private DbalRaceEventRepository $raceEventRepository;
    private PlannedSessionRepository $plannedSessionRepository;
    private MockObject $commandBus;

    public function testHandleGetRendersTrainingPlanModal(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $response = $this->requestHandler->handle(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('New plan', (string) $response->getContent());
        self::assertStringContainsString('What kind of plan?', (string) $response->getContent());
        self::assertStringContainsString('Create plan', (string) $response->getContent());
        self::assertStringNotContainsString('Event family', (string) $response->getContent());
        self::assertStringContainsString('Training block style', (string) $response->getContent());
        self::assertStringContainsString('Speed-endurance build', (string) $response->getContent());
        self::assertStringContainsString('Balanced or speed-endurance build', (string) $response->getContent());
        self::assertStringContainsString('Running workout default', (string) $response->getContent());
        self::assertStringContainsString('Include hill sessions when terrain allows', (string) $response->getContent());
        self::assertStringNotContainsString('sessionsPerWeek', (string) $response->getContent());
    }

    public function testHandleGetPrefillsSequentialNextPlanDefaults(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $previousPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-12 00:00:00'),
            targetRaceEventId: null,
            title: 'Spring prep',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->repository->upsert($previousPlan);

        $suggestedRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Ironman 70.3 Nice',
            location: 'Nice',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 17100,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($suggestedRace);

        $response = $this->requestHandler->handle(new Request(query: [
            'afterTrainingPlanId' => (string) $previousPlan->getId(),
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('value="2026-05-13"', (string) $response->getContent());
        self::assertStringContainsString('Ironman 70.3 Nice', (string) $response->getContent());
        self::assertStringContainsString('Create the next plan in sequence', (string) $response->getContent());
    }

    public function testHandleGetSuggestsRaceOutsideExistingPlanWindow(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $existingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2027-01-05 00:00:00'),
            endDay: SerializableDateTime::fromString('2027-03-30 00:00:00'),
            targetRaceEventId: null,
            title: 'Winter build',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->repository->upsert($existingPlan);

        $integratedBRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2027-02-14 00:00:00'),
            type: RaceEventType::RUN_10K,
            title: 'Tune-up race inside window',
            location: 'Antwerp',
            notes: null,
            priority: RaceEventPriority::B,
            targetFinishTimeInSeconds: 2700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $unassignedRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2027-05-09 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Goal race outside window',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5400,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($integratedBRace);
        $this->raceEventRepository->upsert($unassignedRace);

        $response = $this->requestHandler->handle(new Request());
        $content = (string) $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Goal race outside window', $content);
        self::assertStringNotContainsString('Tune-up race inside window on Feb 14, 2027 is a good anchor for this plan.', $content);
    }

    public function testHandlePostPersistsTrainingPlanAndRebuildsManagementPage(): void
    {
        $this->commandBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildTrainingPlansHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'type' => TrainingPlanType::RACE->value,
                'title' => 'Summer A-race build',
                'startDay' => '2026-05-13',
                'endDay' => '2026-06-21',
                'notes' => 'Keep long rides progressive but controlled.',
                'discipline' => TrainingPlanDiscipline::CYCLING->value,
                'bikeDays' => ['2', '6'],
                'longRideDays' => ['6'],
                'cyclingFtp' => '280',
                'weeklyBikingVolume' => '8.5',
                'targetRaceProfile' => RaceEventProfile::HALF_DISTANCE_TRIATHLON->value,
                'trainingFocus' => 'bike',
                'redirectTo' => '/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/training-plans', Response::HTTP_FOUND), $response);

        $records = $this->repository->findAll();
        self::assertCount(1, $records);
        self::assertSame('Summer A-race build', $records[0]->getTitle());
        self::assertSame(TrainingPlanType::RACE, $records[0]->getType());
        self::assertSame('2026-05-13', $records[0]->getStartDay()->format('Y-m-d'));
        self::assertSame('2026-06-21', $records[0]->getEndDay()->format('Y-m-d'));
        self::assertSame('Keep long rides progressive but controlled.', $records[0]->getNotes());
        self::assertSame(TrainingPlanDiscipline::CYCLING, $records[0]->getDiscipline());
        self::assertSame(['bikeDays' => [2, 6], 'longRideDays' => [6]], $records[0]->getSportSchedule());
        self::assertSame(['cyclingFtp' => 280, 'weeklyBikingVolume' => 8.5], $records[0]->getPerformanceMetrics());
        self::assertSame(RaceEventProfile::HALF_DISTANCE_TRIATHLON, $records[0]->getTargetRaceProfile());
        self::assertNull($records[0]->getTrainingFocus());
    }

    public function testHandlePostPersistsTrainingBlockTargetDistanceAndFocus(): void
    {
        $this->commandBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildTrainingPlansHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'type' => TrainingPlanType::TRAINING->value,
                'title' => 'Run-focused 70.3 block',
                'startDay' => '2026-05-13',
                'endDay' => '2026-08-30',
                'notes' => 'Build toward a run-led half-distance triathlon.',
                'discipline' => TrainingPlanDiscipline::TRIATHLON->value,
                'swimDays' => ['3'],
                'bikeDays' => ['2', '6'],
                'runDays' => ['1', '4', '7'],
                'longRideDays' => ['6'],
                'longRunDays' => ['7'],
                'cyclingFtp' => '280',
                'runningThresholdPace' => '255',
                'weeklyBikingVolume' => '8.5',
                'weeklyRunningVolume' => '52.0',
                'targetRaceProfile' => RaceEventProfile::HALF_DISTANCE_TRIATHLON->value,
                'trainingBlockStyle' => TrainingBlockStyle::SPEED_ENDURANCE->value,
                'trainingFocus' => 'run',
                'redirectTo' => '/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/training-plans', Response::HTTP_FOUND), $response);

        $records = $this->repository->findAll();
        self::assertCount(1, $records);
        self::assertSame(TrainingPlanType::TRAINING, $records[0]->getType());
        self::assertSame(TrainingPlanDiscipline::TRIATHLON, $records[0]->getDiscipline());
        self::assertSame(RaceEventProfile::HALF_DISTANCE_TRIATHLON, $records[0]->getTargetRaceProfile());
        self::assertSame(TrainingBlockStyle::SPEED_ENDURANCE, $records[0]->getTrainingBlockStyle());
        self::assertSame(
            TrainingFocus::RUN,
            $records[0]->getTrainingFocus(),
        );
    }

    public function testHandlePostAllowsRunningTrainingBlockWithoutTrainingFocus(): void
    {
        $this->commandBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildTrainingPlansHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'type' => TrainingPlanType::TRAINING->value,
                'title' => 'Running speed block',
                'startDay' => '2026-05-13',
                'endDay' => '2026-06-23',
                'discipline' => TrainingPlanDiscipline::RUNNING->value,
                'runDays' => ['2', '4', '7'],
                'longRunDays' => ['7'],
                'runningThresholdPace' => '255',
                'weeklyRunningVolume' => '52.0',
                'targetRaceProfile' => RaceEventProfile::RUN_10K->value,
                'trainingBlockStyle' => TrainingBlockStyle::SPEED_ENDURANCE->value,
                'runningWorkoutTargetMode' => RunningWorkoutTargetMode::DISTANCE->value,
                'runHillSessionsEnabled' => '1',
                'redirectTo' => '/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/training-plans', Response::HTTP_FOUND), $response);

        $records = $this->repository->findAll();
        self::assertCount(1, $records);
        self::assertSame(TrainingPlanType::TRAINING, $records[0]->getType());
        self::assertSame(TrainingPlanDiscipline::RUNNING, $records[0]->getDiscipline());
        self::assertSame(RaceEventProfile::RUN_10K, $records[0]->getTargetRaceProfile());
        self::assertSame(TrainingBlockStyle::SPEED_ENDURANCE, $records[0]->getTrainingBlockStyle());
        self::assertSame(RunningWorkoutTargetMode::DISTANCE, $records[0]->getRunningWorkoutTargetMode());
        self::assertTrue($records[0]->isRunHillSessionsEnabled());
        self::assertNull($records[0]->getTrainingFocus());
    }

    public function testHandlePostRegeneratesLinkedRacePlanAfterEditingSettings(): void
    {
        $now = $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class)->getCurrentDateTimeImmutable()->setTime(0, 0);
        $planStartDay = SerializableDateTime::fromDateTimeImmutable($now->modify('monday this week -4 weeks'))->setTime(0, 0);
        $raceDay = SerializableDateTime::fromDateTimeImmutable($now->modify('+8 weeks'))->setTime(0, 0);
        $planEndDay = SerializableDateTime::fromDateTimeImmutable($raceDay->modify('+2 weeks'))->setTime(0, 0);

        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: $raceDay,
            type: RaceEventType::HALF_MARATHON,
            title: 'Goal half marathon',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->raceEventRepository->upsert($targetRace);

        $existingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: $planStartDay,
            endDay: $planEndDay,
            targetRaceEventId: $targetRace->getId(),
            title: 'Original race build',
            notes: null,
            createdAt: $now,
            updatedAt: $now,
            discipline: TrainingPlanDiscipline::RUNNING,
            sportSchedule: ['runDays' => [2, 4, 7], 'longRunDays' => [7]],
            performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 42.0],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
        );
        $this->repository->upsert($existingPlan);

        $staleUpcomingSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromDateTimeImmutable($now->modify('+5 days')),
            activityType: ActivityType::RUN,
            title: 'Old future run',
            notes: 'Should be replaced after plan edit.',
            targetLoad: null,
            targetDurationInSeconds: 3_600,
            targetIntensity: PlannedSessionIntensity::EASY,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->plannedSessionRepository->upsert($staleUpcomingSession);

        $dispatchedCommands = [];
        $this->commandBus
            ->expects(self::exactly(4))
            ->method('dispatch')
            ->willReturnCallback(static function ($command) use (&$dispatchedCommands): void {
                $dispatchedCommands[] = $command;
            });

        $response = $this->requestHandler->handle(new Request(
            request: [
                'trainingPlanId' => (string) $existingPlan->getId(),
                'type' => TrainingPlanType::RACE->value,
                'targetRaceEventId' => (string) $targetRace->getId(),
                'title' => 'Updated race build',
                'startDay' => $planStartDay->format('Y-m-d'),
                'endDay' => $planEndDay->format('Y-m-d'),
                'discipline' => TrainingPlanDiscipline::RUNNING->value,
                'runDays' => ['1', '3', '6', '7'],
                'longRunDays' => ['7'],
                'runningThresholdPace' => '250',
                'weeklyRunningVolume' => '55.0',
                'targetRaceProfile' => RaceEventProfile::HALF_MARATHON->value,
                'redirectTo' => '/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/training-plans', Response::HTTP_FOUND), $response);

        $records = $this->repository->findAll();
        self::assertCount(1, $records);
        self::assertSame('Updated race build', $records[0]->getTitle());
        self::assertSame(['runDays' => [1, 3, 6, 7], 'longRunDays' => [7]], $records[0]->getSportSchedule());
        self::assertSame(250, $records[0]->getPerformanceMetrics()['runningThresholdPace'] ?? null);
        self::assertEquals(55.0, $records[0]->getPerformanceMetrics()['weeklyRunningVolume'] ?? null);

        $sessionsInPlanWindow = $this->plannedSessionRepository->findByDateRange(\App\Infrastructure\ValueObject\Time\DateRange::fromDates(
            $planStartDay,
            $planEndDay->setTime(23, 59, 59),
        ));
        $sessionIds = array_map(static fn (PlannedSession $plannedSession): string => (string) $plannedSession->getId(), $sessionsInPlanWindow);
        $newUpcomingUnlinkedSessions = array_values(array_filter(
            $sessionsInPlanWindow,
            static fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() >= $now
                && null === $plannedSession->getLinkedActivityId(),
        ));

        self::assertNotContains((string) $staleUpcomingSession->getId(), $sessionIds);
        self::assertNotEmpty($newUpcomingUnlinkedSessions);
        self::assertContainsOnlyInstancesOf(BuildTrainingPlansHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildTrainingPlansHtml));
        self::assertContainsOnlyInstancesOf(BuildDashboardHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildDashboardHtml));
        self::assertContainsOnlyInstancesOf(BuildMonthlyStatsHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildMonthlyStatsHtml));
        self::assertContainsOnlyInstancesOf(BuildRacePlannerHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildRacePlannerHtml));
    }

    public function testDeleteRemovesLinkedPlanSessionsAndRebuildsDependentViews(): void
    {
        $now = $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class)->getCurrentDateTimeImmutable()->setTime(0, 0);
        $planStartDay = SerializableDateTime::fromDateTimeImmutable($now->modify('monday this week -4 weeks'))->setTime(0, 0);
        $raceDay = SerializableDateTime::fromDateTimeImmutable($now->modify('+8 weeks'))->setTime(0, 0);
        $planEndDay = SerializableDateTime::fromDateTimeImmutable($raceDay->modify('+2 weeks'))->setTime(0, 0);

        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: $raceDay,
            type: RaceEventType::HALF_MARATHON,
            title: 'Goal half marathon',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->raceEventRepository->upsert($targetRace);

        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: $planStartDay,
            endDay: $planEndDay,
            targetRaceEventId: $targetRace->getId(),
            title: 'Disposable race build',
            notes: null,
            createdAt: $now,
            updatedAt: $now,
            discipline: TrainingPlanDiscipline::RUNNING,
            sportSchedule: ['runDays' => [2, 4, 7], 'longRunDays' => [7]],
            performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 42.0],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
        );
        $this->repository->upsert($trainingPlan);

        $replaceableUpcomingSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromDateTimeImmutable($now->modify('+5 days')),
            activityType: ActivityType::RUN,
            title: 'Replaceable future run',
            notes: 'Should disappear when the plan is deleted.',
            targetLoad: null,
            targetDurationInSeconds: 3_600,
            targetIntensity: PlannedSessionIntensity::EASY,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: $now,
            updatedAt: $now,
        );
        $linkedUpcomingSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromDateTimeImmutable($now->modify('+6 days')),
            activityType: ActivityType::RUN,
            title: 'Matched future run',
            notes: 'Linked sessions should be preserved.',
            targetLoad: null,
            targetDurationInSeconds: 3_600,
            targetIntensity: PlannedSessionIntensity::EASY,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: \App\Domain\Activity\ActivityId::random(),
            linkStatus: PlannedSessionLinkStatus::LINKED,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->plannedSessionRepository->upsert($replaceableUpcomingSession);
        $this->plannedSessionRepository->upsert($linkedUpcomingSession);

        $dispatchedCommands = [];
        $this->commandBus
            ->expects(self::exactly(4))
            ->method('dispatch')
            ->willReturnCallback(static function ($command) use (&$dispatchedCommands): void {
                $dispatchedCommands[] = $command;
            });

        $response = $this->requestHandler->delete(new Request(
            request: [
                'trainingPlanId' => (string) $trainingPlan->getId(),
                'redirectTo' => '/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/training-plans', Response::HTTP_FOUND), $response);
        self::assertSame([], $this->repository->findAll());

        $sessionsInPlanWindow = $this->plannedSessionRepository->findByDateRange(\App\Infrastructure\ValueObject\Time\DateRange::fromDates(
            $planStartDay,
            $planEndDay->setTime(23, 59, 59),
        ));
        $sessionIds = array_map(static fn (PlannedSession $plannedSession): string => (string) $plannedSession->getId(), $sessionsInPlanWindow);

        self::assertNotContains((string) $replaceableUpcomingSession->getId(), $sessionIds);
        self::assertContains((string) $linkedUpcomingSession->getId(), $sessionIds);
        self::assertContainsOnlyInstancesOf(BuildTrainingPlansHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildTrainingPlansHtml));
        self::assertContainsOnlyInstancesOf(BuildDashboardHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildDashboardHtml));
        self::assertContainsOnlyInstancesOf(BuildMonthlyStatsHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildMonthlyStatsHtml));
        self::assertContainsOnlyInstancesOf(BuildRacePlannerHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildRacePlannerHtml));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalTrainingPlanRepository($this->getConnection());
        $this->raceEventRepository = new DbalRaceEventRepository($this->getConnection());
        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $this->requestHandler = new TrainingPlanRequestHandler(
            repository: $this->repository,
            raceEventRepository: $this->raceEventRepository,
            plannedSessionRepository: $this->plannedSessionRepository,
            racePlannerUpcomingSessionRegenerator: $this->getContainer()->get(\App\Domain\TrainingPlanner\RacePlannerUpcomingSessionRegenerator::class),
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
            twig: $this->getContainer()->get(Environment::class),
            performanceAnchorHistory: $this->getContainer()->get(\App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory::class),
            connection: $this->getConnection(),
        );
    }
}