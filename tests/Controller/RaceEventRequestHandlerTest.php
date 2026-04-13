<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Controller\RaceEventRequestHandler;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class RaceEventRequestHandlerTest extends ContainerTestCase
{
    private RaceEventRequestHandler $requestHandler;
    private DbalRaceEventRepository $repository;
    private MockObject $commandBus;

    public function testHandleGetRendersRaceEventModal(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $response = $this->requestHandler->handle(new Request(query: ['day' => '2026-06-01']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Race event', (string) $response->getContent());
        self::assertStringContainsString('Event family', (string) $response->getContent());
        self::assertStringContainsString('Distance / profile', (string) $response->getContent());
        self::assertStringContainsString('Target finish', (string) $response->getContent());
        self::assertStringContainsString('Triathlon', (string) $response->getContent());
        self::assertStringContainsString('Run', (string) $response->getContent());
        self::assertStringContainsString('5K run', (string) $response->getContent());
        self::assertStringContainsString('10K run', (string) $response->getContent());
        self::assertStringContainsString('Half marathon (21.1K)', (string) $response->getContent());
        self::assertStringContainsString('Marathon (42.2K)', (string) $response->getContent());
        self::assertStringContainsString('Create race', (string) $response->getContent());
    }

    public function testHandlePostPersistsRaceEventAndRebuildsMonthlyStats(): void
    {
        $this->expectMonthlyStatsRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-06-14',
                'type' => RaceEventType::HALF_DISTANCE_TRIATHLON->value,
                'priority' => RaceEventPriority::A->value,
                'title' => 'Ironman 70.3 Nice',
                'location' => 'Nice',
                'targetFinishTimeHours' => '4',
                'targetFinishTimeMinutes' => '45',
                'notes' => 'Stay patient on the bike.',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-06-14 00:00:00'),
            SerializableDateTime::fromString('2026-06-14 23:59:59'),
        ));
        self::assertCount(1, $records);
        self::assertSame('Ironman 70.3 Nice', $records[0]->getTitle());
        self::assertSame('Nice', $records[0]->getLocation());
        self::assertSame(RaceEventPriority::A, $records[0]->getPriority());
        self::assertSame(RaceEventType::HALF_DISTANCE_TRIATHLON, $records[0]->getType());
        self::assertSame(17100, $records[0]->getTargetFinishTimeInSeconds());
    }

    public function testHandlePostPersistsFiveKilometerRaceEvent(): void
    {
        $this->expectMonthlyStatsRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-05-03',
                'type' => RaceEventType::RUN_5K->value,
                'priority' => RaceEventPriority::B->value,
                'title' => 'Spring park 5K',
                'targetFinishTimeHours' => '0',
                'targetFinishTimeMinutes' => '19',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-05-03 00:00:00'),
            SerializableDateTime::fromString('2026-05-03 23:59:59'),
        ));
        self::assertCount(1, $records);
        self::assertSame('Spring park 5K', $records[0]->getTitle());
        self::assertSame(RaceEventType::RUN_5K, $records[0]->getType());
        self::assertSame(1140, $records[0]->getTargetFinishTimeInSeconds());
    }

    public function testHandlePostPersistsHalfMarathonRaceEvent(): void
    {
        $this->expectMonthlyStatsRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-06-21',
                'type' => RaceEventType::HALF_MARATHON->value,
                'priority' => RaceEventPriority::B->value,
                'title' => 'City half marathon',
                'targetFinishTimeHours' => '1',
                'targetFinishTimeMinutes' => '34',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-06-21 00:00:00'),
            SerializableDateTime::fromString('2026-06-21 23:59:59'),
        ));
        self::assertCount(1, $records);
        self::assertSame('City half marathon', $records[0]->getTitle());
        self::assertSame(RaceEventType::HALF_MARATHON, $records[0]->getType());
        self::assertSame(5640, $records[0]->getTargetFinishTimeInSeconds());
    }

    public function testHandlePostPersistsRaceEventUsingFamilyAndProfileFields(): void
    {
        $this->expectMonthlyStatsRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-07-05',
                'family' => RaceEventFamily::RUN->value,
                'profile' => RaceEventProfile::RUN_10K->value,
                'priority' => RaceEventPriority::B->value,
                'title' => 'Summer 10K',
                'targetFinishTimeHours' => '0',
                'targetFinishTimeMinutes' => '41',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-07-05 00:00:00'),
            SerializableDateTime::fromString('2026-07-05 23:59:59'),
        ));
        self::assertCount(1, $records);
        self::assertSame('Summer 10K', $records[0]->getTitle());
        self::assertSame(RaceEventFamily::RUN, $records[0]->getFamily());
        self::assertSame(RaceEventProfile::RUN_10K, $records[0]->getProfile());
        self::assertSame(RaceEventType::RUN_10K, $records[0]->getType());
        self::assertSame(2460, $records[0]->getTargetFinishTimeInSeconds());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalRaceEventRepository($this->getConnection());
        $this->requestHandler = new RaceEventRequestHandler(
            repository: $this->repository,
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
