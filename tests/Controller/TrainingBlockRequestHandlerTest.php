<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Controller\TrainingBlockRequestHandler;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingBlockRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class TrainingBlockRequestHandlerTest extends ContainerTestCase
{
    private TrainingBlockRequestHandler $requestHandler;
    private DbalTrainingBlockRepository $repository;
    private DbalRaceEventRepository $raceEventRepository;
    private MockObject $commandBus;

    public function testHandleGetRendersTrainingBlockModal(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $response = $this->requestHandler->handle(new Request(query: ['day' => '2026-05-05']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Training block', (string) $response->getContent());
        self::assertStringContainsString('Block details', (string) $response->getContent());
        self::assertStringContainsString('Create block', (string) $response->getContent());
    }

    public function testHandlePostPersistsTrainingBlockAndRebuildsMonthlyStats(): void
    {
        $this->expectMonthlyStatsRebuilds();
        $raceEvent = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-14 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'Ironman 70.3 Nice',
            location: 'Nice',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 17100,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($raceEvent);

        $response = $this->requestHandler->handle(new Request(
            request: [
                'startDay' => '2026-05-05',
                'endDay' => '2026-05-25',
                'targetRaceEventId' => (string) $raceEvent->getId(),
                'phase' => TrainingBlockPhase::BUILD->value,
                'title' => 'May build block',
                'focus' => 'Bike durability and threshold support',
                'notes' => 'Keep the long run controlled.',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-05-01 00:00:00'),
            SerializableDateTime::fromString('2026-05-31 23:59:59'),
        ));

        self::assertCount(1, $records);
        self::assertSame('May build block', $records[0]->getTitle());
        self::assertSame('Bike durability and threshold support', $records[0]->getFocus());
        self::assertSame('Keep the long run controlled.', $records[0]->getNotes());
        self::assertSame((string) $raceEvent->getId(), $records[0]->getTargetRaceEventId()?->__toString());
        self::assertSame(TrainingBlockPhase::BUILD, $records[0]->getPhase());
        self::assertSame('2026-05-05', $records[0]->getStartDay()->format('Y-m-d'));
        self::assertSame('2026-05-25', $records[0]->getEndDay()->format('Y-m-d'));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalTrainingBlockRepository($this->getConnection());
        $this->raceEventRepository = new DbalRaceEventRepository($this->getConnection());
        $this->requestHandler = new TrainingBlockRequestHandler(
            repository: $this->repository,
            raceEventRepository: $this->raceEventRepository,
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
            twig: $this->getContainer()->get(Environment::class),
        );
    }

    private function expectMonthlyStatsRebuilds(int $times = 1): void
    {
        $this->commandBus
            ->expects(self::exactly($times))
            ->method('dispatch')
            ->with(self::isInstanceOf(BuildMonthlyStatsHtml::class));
    }
}
