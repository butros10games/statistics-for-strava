<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Controller\RacePlannerSaveRecoveryRequestHandler;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerRecoveryManager;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RacePlannerSaveRecoveryRequestHandlerTest extends ContainerTestCase
{
    private RacePlannerSaveRecoveryRequestHandler $requestHandler;
    private RaceEventRepository $raceEventRepository;
    private TrainingBlockRepository $trainingBlockRepository;
    private PlannedSessionRepository $plannedSessionRepository;
    private MockObject $commandBus;

    public function testHandlePersistsRecoveryIntoCalendarAndRebuildsViews(): void
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

        $recoveryBlocks = array_values(array_filter(
            $this->trainingBlockRepository->findByDateRange(DateRange::fromDates(
                SerializableDateTime::fromString('2026-06-22 00:00:00'),
                SerializableDateTime::fromString('2026-07-20 23:59:59'),
            )),
            static fn ($block): bool => TrainingBlockPhase::RECOVERY === $block->getPhase(),
        ));

        self::assertCount(1, $recoveryBlocks);
        self::assertNotEmpty($this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-06-22 00:00:00'),
            SerializableDateTime::fromString('2026-07-20 23:59:59'),
        )));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $this->trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $this->requestHandler = new RacePlannerSaveRecoveryRequestHandler(
            raceEventRepository: $this->raceEventRepository,
            racePlannerRecoveryManager: $this->getContainer()->get(RacePlannerRecoveryManager::class),
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(Clock::class),
        );
    }
}