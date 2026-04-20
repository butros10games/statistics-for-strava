<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Controller\ReactPreviewTrainingBlocksApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingBlockRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewTrainingBlocksApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewTrainingBlocksApiRequestHandler $requestHandler;
    private DbalTrainingBlockRepository $repository;
    private DbalRaceEventRepository $raceEventRepository;
    private MockObject $commandBus;

    public function testHandleReturnsTrainingBlockOptionsAndRecords(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        $raceEvent = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-09-13 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Autumn half marathon',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5400,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($raceEvent);
        $this->repository->upsert(TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-08-10'),
            endDay: SerializableDateTime::fromString('2026-09-06'),
            targetRaceEventId: $raceEvent->getId(),
            phase: TrainingBlockPhase::BUILD,
            title: 'Late summer build',
            focus: 'Threshold support and long-run durability',
            notes: 'Hold one fresh day before the weekend.',
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        ));

        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('options', $payload);
        self::assertArrayHasKey('blocks', $payload);
        self::assertSame(1, $payload['summary']['totalBlocks']);
        self::assertSame('Late summer build', $payload['blocks'][0]['title']);
        self::assertSame('Build', $payload['blocks'][0]['phaseLabel']);
        self::assertSame('Autumn half marathon', $payload['blocks'][0]['linkedRace']['title']);
        self::assertSame('Base', $payload['options']['phases'][0]['label']);
    }

    public function testSavePersistsTrainingBlockAndReturnsUpdatedPayload(): void
    {
        $this->expectPlannerRebuilds();

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

        $response = $this->requestHandler->save(new Request(
            content: json_encode([
                'startDay' => '2026-05-05',
                'endDay' => '2026-05-25',
                'targetRaceEventId' => (string) $raceEvent->getId(),
                'phase' => TrainingBlockPhase::BUILD->value,
                'title' => 'May build block',
                'focus' => 'Bike durability and threshold support',
                'notes' => 'Keep the long run controlled.',
            ], JSON_THROW_ON_ERROR),
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNotNull($payload['savedTrainingBlockId']);
        self::assertSame(1, $payload['summary']['totalBlocks']);
        self::assertSame('May build block', $payload['blocks'][0]['title']);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-05-01 00:00:00'),
            SerializableDateTime::fromString('2026-05-31 23:59:59'),
        ));
        self::assertCount(1, $records);
        self::assertSame('May build block', $records[0]->getTitle());
        self::assertSame('Bike durability and threshold support', $records[0]->getFocus());
        self::assertSame('Keep the long run controlled.', $records[0]->getNotes());
        self::assertSame((string) $raceEvent->getId(), (string) $records[0]->getTargetRaceEventId());
        self::assertSame(TrainingBlockPhase::BUILD, $records[0]->getPhase());
    }

    public function testDeleteRemovesTrainingBlock(): void
    {
        $existing = TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString('2026-05-05'),
            endDay: SerializableDateTime::fromString('2026-05-25'),
            targetRaceEventId: null,
            phase: TrainingBlockPhase::BASE,
            title: 'May base block',
            focus: 'Easy volume',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );
        $this->repository->upsert($existing);
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->delete((string) $existing->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame((string) $existing->getId(), $payload['deletedTrainingBlockId']);
        self::assertSame(0, $payload['summary']['totalBlocks']);
        self::assertNull($this->repository->findById($existing->getId()));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalTrainingBlockRepository($this->getConnection());
        $this->raceEventRepository = new DbalRaceEventRepository($this->getConnection());
        $this->requestHandler = new ReactPreviewTrainingBlocksApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            repository: $this->repository,
            raceEventRepository: $this->raceEventRepository,
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(Clock::class),
            translator: $this->getContainer()->get(TranslatorInterface::class),
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

    private function expectPlannerRebuilds(int $times = 1): void
    {
        $this->commandBus
            ->expects(self::exactly($times * 2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildMonthlyStatsHtml::class),
                self::isInstanceOf(BuildRacePlannerHtml::class),
            ));
    }
}
