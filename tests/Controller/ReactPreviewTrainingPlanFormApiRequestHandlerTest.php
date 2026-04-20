<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Controller\ReactPreviewTrainingPlanFormApiRequestHandler;
use App\Controller\ReactPreviewTrainingPlansApiRequestHandler;
use App\Controller\TrainingPlanRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingPlanRepository;
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
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class ReactPreviewTrainingPlanFormApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewTrainingPlanFormApiRequestHandler $formRequestHandler;
    private DbalTrainingPlanRepository $repository;
    private DbalRaceEventRepository $raceEventRepository;
    private PlannedSessionRepository $plannedSessionRepository;

    public function testHandleReturnsPreviewFormDefaultsAndOptions(): void
    {
        $existingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-05-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-01 00:00:00'),
            targetRaceEventId: null,
            title: 'Spring block',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
        );
        $this->repository->upsert($existingPlan);

        $raceEvent = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-07-12 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Goal half marathon',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_700,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($raceEvent);

        $response = $this->formRequestHandler->handle(new Request(query: [
            'afterTrainingPlanId' => (string) $existingPlan->getId(),
            'targetRaceEventId' => (string) $raceEvent->getId(),
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('create', $payload['mode']);
        self::assertSame('race', $payload['defaults']['type']);
        self::assertSame('Goal half marathon', $payload['defaults']['title']);
        self::assertSame('2026-06-02', $payload['defaults']['startDay']);
        self::assertSame('2026-07-12', $payload['defaults']['endDay']);
        self::assertSame((string) $raceEvent->getId(), $payload['defaults']['targetRaceEventId']);
        self::assertSame('running', $payload['defaults']['discipline']);
        self::assertSame('halfMarathon', $payload['defaults']['targetRaceProfile']);
        self::assertSame((string) $existingPlan->getId(), $payload['context']['afterTrainingPlan']['id']);
        self::assertNotEmpty($payload['options']['types']);
        self::assertNotEmpty($payload['options']['raceEvents']);
        self::assertNotEmpty($payload['options']['raceProfileGroups']);
    }

    public function testHandleReturnsExistingPlanDefaultsWhenEditing(): void
    {
        $raceEvent = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-09-20 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Autumn half marathon',
            location: 'Antwerp',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5_900,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($raceEvent);

        $existingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2026-07-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-09-20 00:00:00'),
            targetRaceEventId: $raceEvent->getId(),
            title: 'Sub-90 build',
            notes: 'Keep Tuesday threshold work.',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-02 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            sportSchedule: ['runDays' => [2, 4, 7], 'longRunDays' => [7]],
            performanceMetrics: ['runningThresholdPace' => 252, 'weeklyRunningVolume' => 61.5],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
            trainingBlockStyle: TrainingBlockStyle::BALANCED,
            runningWorkoutTargetMode: RunningWorkoutTargetMode::DISTANCE,
            runHillSessionsEnabled: true,
        );
        $this->repository->upsert($existingPlan);

        $response = $this->formRequestHandler->handle(new Request(query: [
            'trainingPlanId' => (string) $existingPlan->getId(),
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('edit', $payload['mode']);
        self::assertSame((string) $existingPlan->getId(), $payload['context']['trainingPlan']['id']);
        self::assertSame('Sub-90 build', $payload['defaults']['title']);
        self::assertSame('2026-07-01', $payload['defaults']['startDay']);
        self::assertSame('2026-09-20', $payload['defaults']['endDay']);
        self::assertSame((string) $raceEvent->getId(), $payload['defaults']['targetRaceEventId']);
        self::assertSame('running', $payload['defaults']['discipline']);
        self::assertSame(['runDays' => [2, 4, 7], 'longRunDays' => [7]], $payload['defaults']['sportSchedule']);
        self::assertSame(252, $payload['defaults']['performanceMetrics']['runningThresholdPace']);
        self::assertSame(61.5, $payload['defaults']['performanceMetrics']['weeklyRunningVolume']);
        self::assertSame('distance', $payload['defaults']['runningWorkoutTargetMode']);
        self::assertTrue($payload['defaults']['runHillSessionsEnabled']);
        self::assertSame('Keep Tuesday threshold work.', $payload['defaults']['notes']);
    }

    public function testCreateDelegatesToTrainingPlanHandlerAndReturnsUpdatedPreviewPayload(): void
    {
        $commandBus = $this->createMock(CommandBus::class);
        $commandBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildTrainingPlansHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));

        $plansRequestHandler = new ReactPreviewTrainingPlansApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            trainingPlanRepository: $this->repository,
            raceEventRepository: $this->raceEventRepository,
            clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
            trainingPlanRequestHandler: new TrainingPlanRequestHandler(
                repository: $this->repository,
                raceEventRepository: $this->raceEventRepository,
                plannedSessionRepository: $this->plannedSessionRepository,
                racePlannerUpcomingSessionRegenerator: $this->getContainer()->get(\App\Domain\TrainingPlanner\RacePlannerUpcomingSessionRegenerator::class),
                commandBus: $commandBus,
                clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
                twig: $this->getContainer()->get(Environment::class),
                performanceAnchorHistory: $this->getContainer()->get(\App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory::class),
                connection: $this->getConnection(),
            ),
        );

        $response = $plansRequestHandler->create(new Request(
            content: json_encode([
                'type' => TrainingPlanType::TRAINING->value,
                'title' => 'React-created plan',
                'startDay' => '2026-05-13',
                'endDay' => '2026-06-23',
                'discipline' => TrainingPlanDiscipline::RUNNING->value,
                'runDays' => [2, 4, 7],
                'longRunDays' => [7],
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 52.0,
                'targetRaceProfile' => RaceEventProfile::RUN_10K->value,
                'trainingBlockStyle' => TrainingBlockStyle::SPEED_ENDURANCE->value,
                'runHillSessionsEnabled' => true,
                'notes' => 'Created via React preview modal.',
            ], JSON_THROW_ON_ERROR),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'REQUEST_METHOD' => 'POST',
            ],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $records = $this->repository->findAll();

        self::assertCount(1, $records);
        self::assertSame('React-created plan', $records[0]->getTitle());
        self::assertSame(['runDays' => [2, 4, 7], 'longRunDays' => [7]], $records[0]->getSportSchedule());
        self::assertSame(TrainingPlanType::TRAINING, $records[0]->getType());
        self::assertSame(TrainingPlanDiscipline::RUNNING, $records[0]->getDiscipline());
        self::assertSame('React-created plan', $payload['plans'][0]['title']);
        self::assertSame(1, $payload['stats']['totalPlans']);
    }

    public function testEditDelegatesToTrainingPlanHandlerAndReturnsUpdatedPreviewPayload(): void
    {
        $existingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-05-13 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-23 00:00:00'),
            targetRaceEventId: null,
            title: 'Original plan',
            notes: 'Initial notes',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            sportSchedule: ['runDays' => [2, 5], 'longRunDays' => [7]],
            performanceMetrics: ['runningThresholdPace' => 270],
            targetRaceProfile: RaceEventProfile::RUN_10K,
            trainingBlockStyle: TrainingBlockStyle::BALANCED,
        );
        $this->repository->upsert($existingPlan);

        $commandBus = $this->createMock(CommandBus::class);
        $commandBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildTrainingPlansHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));

        $plansRequestHandler = new ReactPreviewTrainingPlansApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            trainingPlanRepository: $this->repository,
            raceEventRepository: $this->raceEventRepository,
            clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
            trainingPlanRequestHandler: new TrainingPlanRequestHandler(
                repository: $this->repository,
                raceEventRepository: $this->raceEventRepository,
                plannedSessionRepository: $this->plannedSessionRepository,
                racePlannerUpcomingSessionRegenerator: $this->getContainer()->get(\App\Domain\TrainingPlanner\RacePlannerUpcomingSessionRegenerator::class),
                commandBus: $commandBus,
                clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
                twig: $this->getContainer()->get(Environment::class),
                performanceAnchorHistory: $this->getContainer()->get(\App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory::class),
                connection: $this->getConnection(),
            ),
        );

        $response = $plansRequestHandler->create(new Request(
            content: json_encode([
                'trainingPlanId' => (string) $existingPlan->getId(),
                'type' => TrainingPlanType::TRAINING->value,
                'title' => 'React-edited plan',
                'startDay' => '2026-05-20',
                'endDay' => '2026-07-01',
                'discipline' => TrainingPlanDiscipline::RUNNING->value,
                'runDays' => [2, 4, 6],
                'longRunDays' => [6],
                'runningThresholdPace' => 248,
                'weeklyRunningVolume' => 68.5,
                'targetRaceProfile' => RaceEventProfile::HALF_MARATHON->value,
                'trainingBlockStyle' => TrainingBlockStyle::SPEED_ENDURANCE->value,
                'runningWorkoutTargetMode' => RunningWorkoutTargetMode::DISTANCE->value,
                'runHillSessionsEnabled' => true,
                'notes' => 'Updated via React preview modal.',
            ], JSON_THROW_ON_ERROR),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'REQUEST_METHOD' => 'POST',
            ],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $savedPlan = $this->repository->findById($existingPlan->getId());

        self::assertInstanceOf(TrainingPlan::class, $savedPlan);
        self::assertSame('React-edited plan', $savedPlan->getTitle());
        self::assertSame('2026-05-20', $savedPlan->getStartDay()->format('Y-m-d'));
        self::assertSame('2026-07-01', $savedPlan->getEndDay()->format('Y-m-d'));
        self::assertSame(['runDays' => [2, 4, 6], 'longRunDays' => [6]], $savedPlan->getSportSchedule());
        self::assertSame(248, $savedPlan->getPerformanceMetrics()['runningThresholdPace']);
        self::assertSame(68.5, $savedPlan->getPerformanceMetrics()['weeklyRunningVolume']);
        self::assertSame(TrainingBlockStyle::SPEED_ENDURANCE, $savedPlan->getTrainingBlockStyle());
        self::assertSame(RunningWorkoutTargetMode::DISTANCE, $savedPlan->getRunningWorkoutTargetMode());
        self::assertTrue($savedPlan->isRunHillSessionsEnabled());
        self::assertSame('React-edited plan', $payload['plans'][0]['title']);
        self::assertSame(1, $payload['stats']['totalPlans']);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalTrainingPlanRepository($this->getConnection());
        $this->raceEventRepository = new DbalRaceEventRepository($this->getConnection());
        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);

        $this->formRequestHandler = new ReactPreviewTrainingPlanFormApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            trainingPlanRepository: $this->repository,
            raceEventRepository: $this->raceEventRepository,
            performanceAnchorHistory: $this->getContainer()->get(\App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory::class),
            connection: $this->getConnection(),
            clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
        );
    }

    private function buildCurrentAppUser(): CurrentAppUser
    {
        $security = $this->createStub(Security::class);
        $security
            ->method('getUser')
            ->willReturn(AppUser::register(
                AppUserId::random(),
                'preview@example.com',
                'hash',
                SerializableDateTime::fromString('2026-01-01 08:00:00'),
            ));

        return new CurrentAppUser($security);
    }
}
