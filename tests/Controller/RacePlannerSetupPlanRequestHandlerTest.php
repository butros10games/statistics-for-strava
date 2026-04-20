<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Controller\RacePlannerSetupPlanRequestHandler;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RacePlannerSetupPlanRequestHandlerTest extends ContainerTestCase
{
    private RacePlannerSetupPlanRequestHandler $requestHandler;
    private RaceEventRepository $raceEventRepository;
    private TrainingBlockRepository $trainingBlockRepository;
    private TrainingPlanRepository $trainingPlanRepository;
    private MockObject $commandBus;

    public function testHandleCreatesLinkedRacePlanFromPlannerWindow(): void
    {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'West Friesland',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($targetRace);

        $this->trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-02-02 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Existing season structure',
            focus: 'Use the real season plan window',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        ));

        $dispatchedCommands = [];
        $this->commandBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function ($command) use (&$dispatchedCommands): void {
                $dispatchedCommands[] = $command;
            });

        $response = $this->requestHandler->handle(new Request(
            request: [
                'raceEventId' => (string) $targetRace->getId(),
                'redirectTo' => '/race-planner',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/race-planner', Response::HTTP_FOUND), $response);

        $trainingPlan = $this->trainingPlanRepository->findByTargetRaceEventId($targetRace->getId());

        self::assertNotNull($trainingPlan);
        self::assertSame(TrainingPlanType::RACE, $trainingPlan->getType());
        self::assertSame('West Friesland', $trainingPlan->getTitle());
        self::assertSame('2026-02-02', $trainingPlan->getStartDay()->format('Y-m-d'));
        self::assertSame('2026-07-05', $trainingPlan->getEndDay()->format('Y-m-d'));
        self::assertSame((string) $targetRace->getId(), (string) $trainingPlan->getTargetRaceEventId());
        self::assertContainsOnlyInstancesOf(BuildTrainingPlansHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildTrainingPlansHtml));
        self::assertContainsOnlyInstancesOf(BuildRacePlannerHtml::class, array_filter($dispatchedCommands, static fn ($command): bool => $command instanceof BuildRacePlannerHtml));
    }

    public function testHandlePreservesExistingRichPlanMetadataWhenSyncing(): void
    {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'West Friesland',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($targetRace);

        $existingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: SerializableDateTime::fromString('2026-02-02 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-07-05 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            title: 'Custom title survives sync',
            notes: 'Preserve the coaching context.',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: TrainingPlanDiscipline::TRIATHLON,
            sportSchedule: ['bikeDays' => [3, 6], 'runDays' => [2, 5], 'longRideDays' => [6], 'longRunDays' => [7]],
            performanceMetrics: ['cyclingFtp' => 290, 'weeklyBikingVolume' => 9.5],
            trainingFocus: TrainingFocus::BIKE,
        );
        $this->trainingPlanRepository->upsert($existingPlan);

        $this->trainingBlockRepository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-02-02 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            targetRaceEventId: $targetRace->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Existing season structure',
            focus: 'Use the real season plan window',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        ));

        $this->commandBus->expects(self::exactly(2))->method('dispatch');

        $this->requestHandler->handle(new Request(
            request: [
                'raceEventId' => (string) $targetRace->getId(),
                'redirectTo' => '/race-planner',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        $trainingPlan = $this->trainingPlanRepository->findByTargetRaceEventId($targetRace->getId());

        self::assertNotNull($trainingPlan);
        self::assertSame('Custom title survives sync', $trainingPlan->getTitle());
        self::assertSame('Preserve the coaching context.', $trainingPlan->getNotes());
        self::assertSame(TrainingPlanDiscipline::TRIATHLON, $trainingPlan->getDiscipline());
        self::assertSame(['bikeDays' => [3, 6], 'runDays' => [2, 5], 'longRideDays' => [6], 'longRunDays' => [7]], $trainingPlan->getSportSchedule());
        self::assertSame(['cyclingFtp' => 290, 'weeklyBikingVolume' => 9.5], $trainingPlan->getPerformanceMetrics());
        self::assertSame(TrainingFocus::BIKE, $trainingPlan->getTrainingFocus());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $this->trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $this->trainingPlanRepository = $this->getContainer()->get(TrainingPlanRepository::class);
        $this->getContainer()->get(RacePlannerConfiguration::class)->clearPlanStartDay();

        $this->requestHandler = new RacePlannerSetupPlanRequestHandler(
            raceEventRepository: $this->raceEventRepository,
            trainingBlockRepository: $this->trainingBlockRepository,
            plannedSessionRepository: $this->getContainer()->get(PlannedSessionRepository::class),
            existingBlockSelector: $this->getContainer()->get(\App\Domain\TrainingPlanner\RacePlannerExistingBlockSelector::class),
            racePlannerConfiguration: $this->getContainer()->get(RacePlannerConfiguration::class),
            trainingPlanGenerator: $this->getContainer()->get(\App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator::class),
            adaptivePlanningContextBuilder: $this->getContainer()->get(\App\Domain\TrainingPlanner\AdaptivePlanningContextBuilder::class),
            trainingPlanRepository: $this->trainingPlanRepository,
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(Clock::class),
        );
    }
}