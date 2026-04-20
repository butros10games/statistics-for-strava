<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Controller\RacePlannerRegenerateUpcomingSessionsRequestHandler;
use App\Domain\Activity\ActivityId;
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
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\RacePlannerUpcomingSessionRegenerator;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RacePlannerRegenerateUpcomingSessionsRequestHandlerTest extends ContainerTestCase
{
    private RacePlannerRegenerateUpcomingSessionsRequestHandler $requestHandler;
    private RaceEventRepository $raceEventRepository;
    private TrainingBlockRepository $trainingBlockRepository;
    private TrainingPlanRepository $trainingPlanRepository;
    private PlannedSessionRepository $plannedSessionRepository;
    private Clock $clock;
    private MockObject $commandBus;

    public function testHandleRegeneratesUpcomingUnlinkedSessionsAndPreservesHistory(): void
    {
        $now = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $planStartDay = SerializableDateTime::fromDateTimeImmutable($now->modify('monday this week -4 weeks'))->setTime(0, 0);
        $raceDay = SerializableDateTime::fromDateTimeImmutable($now->modify('+8 weeks'))->setTime(0, 0);
        $planEndDay = SerializableDateTime::fromDateTimeImmutable($raceDay->modify('+2 weeks'))->setTime(0, 0);

        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: $raceDay,
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'West Friesland',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19800,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->raceEventRepository->upsert($targetRace);

        $this->trainingPlanRepository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: $planStartDay,
            endDay: $planEndDay,
            targetRaceEventId: $targetRace->getId(),
            title: 'West Friesland',
            notes: null,
            createdAt: $now,
            updatedAt: $now,
            discipline: TrainingPlanDiscipline::TRIATHLON,
        ));

        $this->trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: $planStartDay,
            endDay: $raceDay,
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Build to target race',
            focus: 'Use real plan window',
            notes: null,
            createdAt: $now,
            updatedAt: $now,
        ));

        $pastSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromDateTimeImmutable($now->modify('-2 days')),
            activityType: ActivityType::RUN,
            title: 'Completed steady run',
            notes: 'Keep this history intact.',
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
        $linkedFutureSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromDateTimeImmutable($now->modify('+2 days')),
            activityType: ActivityType::RUN,
            title: 'Matched long run',
            notes: 'Already matched to an activity.',
            targetLoad: null,
            targetDurationInSeconds: 5_400,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: ActivityId::random(),
            linkStatus: PlannedSessionLinkStatus::SUGGESTED,
            createdAt: $now,
            updatedAt: $now,
        );
        $staleUpcomingSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromDateTimeImmutable($now->modify('+5 days')),
            activityType: ActivityType::RIDE,
            title: 'Old future ride',
            notes: 'This should be replaced.',
            targetLoad: null,
            targetDurationInSeconds: 4_200,
            targetIntensity: PlannedSessionIntensity::EASY,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->plannedSessionRepository->upsert($pastSession);
        $this->plannedSessionRepository->upsert($linkedFutureSession);
        $this->plannedSessionRepository->upsert($staleUpcomingSession);

        $this->commandBus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildDashboardHtml::class),
                self::isInstanceOf(BuildMonthlyStatsHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'raceEventId' => (string) $targetRace->getId(),
                'redirectTo' => '/race-planner',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/race-planner', Response::HTTP_FOUND), $response);

        $sessionsInPlanWindow = $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
            $planStartDay,
            $planEndDay->setTime(23, 59, 59),
        ));

        $sessionIds = array_map(static fn (PlannedSession $plannedSession): string => (string) $plannedSession->getId(), $sessionsInPlanWindow);
        $sessionTitles = array_map(static fn (PlannedSession $plannedSession): ?string => $plannedSession->getTitle(), $sessionsInPlanWindow);
        $newUpcomingUnlinkedSessions = array_values(array_filter(
            $sessionsInPlanWindow,
            static fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() >= $now
                && null === $plannedSession->getLinkedActivityId(),
        ));

        self::assertContains((string) $pastSession->getId(), $sessionIds);
        self::assertContains((string) $linkedFutureSession->getId(), $sessionIds);
        self::assertNotContains((string) $staleUpcomingSession->getId(), $sessionIds);
        self::assertNotContains('Old future ride', $sessionTitles);
        self::assertNotEmpty($newUpcomingUnlinkedSessions);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $this->trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $this->trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $this->clock = $this->getContainer()->get(Clock::class);
        $this->getContainer()->get(RacePlannerConfiguration::class)->clearPlanStartDay();

        $this->requestHandler = new RacePlannerRegenerateUpcomingSessionsRequestHandler(
            raceEventRepository: $this->raceEventRepository,
            racePlannerUpcomingSessionRegenerator: $this->getContainer()->get(RacePlannerUpcomingSessionRegenerator::class),
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->clock,
        );
    }
}