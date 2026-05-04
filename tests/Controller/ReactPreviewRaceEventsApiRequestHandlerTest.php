<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Controller\ReactPreviewRaceEventsApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
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

final class ReactPreviewRaceEventsApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewRaceEventsApiRequestHandler $requestHandler;
    private DbalRaceEventRepository $repository;
    private MockObject $commandBus;

    public function testHandleReturnsRaceEventOptionsAndRecords(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        $this->repository->upsert(RaceEvent::createWithClassification(
            raceEventId: RaceEventId::random(),
            ownerUserId: null,
            day: SerializableDateTime::fromString('2026-09-13'),
            family: RaceEventFamily::RUN,
            profile: RaceEventProfile::HALF_MARATHON,
            title: 'Autumn half marathon',
            location: 'Ghent',
            notes: 'Stay patient through 15K.',
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5400,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        ));

        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('options', $payload);
        self::assertArrayHasKey('races', $payload);
        self::assertSame(1, $payload['summary']['totalRaces']);
        self::assertSame('Autumn half marathon', $payload['races'][0]['title']);
        self::assertSame('Half marathon (21.1K)', $payload['races'][0]['profileLabel']);
        self::assertArrayHasKey('familyLabel', $payload['options']['profileGroups'][0]);
    }

    public function testSavePersistsRaceEventAndReturnsUpdatedPayload(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->save(new Request(
            content: json_encode([
                'day' => '2026-06-14',
                'family' => RaceEventFamily::TRIATHLON->value,
                'profile' => RaceEventProfile::HALF_DISTANCE_TRIATHLON->value,
                'priority' => RaceEventPriority::A->value,
                'title' => 'Ironman 70.3 Nice',
                'location' => 'Nice',
                'targetFinishTimeHours' => '4',
                'targetFinishTimeMinutes' => '45',
                'notes' => 'Stay patient on the bike.',
            ], JSON_THROW_ON_ERROR),
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNotNull($payload['savedRaceEventId']);
        self::assertSame(1, $payload['summary']['totalRaces']);
        self::assertSame('Ironman 70.3 Nice', $payload['races'][0]['title']);
        self::assertSame('4:45', $payload['races'][0]['targetFinishTimeLabel']);

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-06-14 00:00:00'),
            SerializableDateTime::fromString('2026-06-14 23:59:59'),
        ));
        self::assertCount(1, $records);
        self::assertSame(17100, $records[0]->getTargetFinishTimeInSeconds());
        self::assertSame(RaceEventProfile::HALF_DISTANCE_TRIATHLON, $records[0]->getProfile());
    }

    public function testDeleteRemovesRaceEvent(): void
    {
        $existing = RaceEvent::createWithClassification(
            raceEventId: RaceEventId::random(),
            ownerUserId: null,
            day: SerializableDateTime::fromString('2026-09-13'),
            family: RaceEventFamily::RUN,
            profile: RaceEventProfile::RUN_10K,
            title: 'Summer 10K',
            location: null,
            notes: null,
            priority: RaceEventPriority::B,
            targetFinishTimeInSeconds: 2460,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );
        $this->repository->upsert($existing);
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->delete((string) $existing->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame((string) $existing->getId(), $payload['deletedRaceEventId']);
        self::assertSame(0, $payload['summary']['totalRaces']);
        self::assertNull($this->repository->findById($existing->getId()));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->getContainer()->get(DbalRaceEventRepository::class);
        $this->requestHandler = new ReactPreviewRaceEventsApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            repository: $this->repository,
            trainingPlanRepository: $this->getContainer()->get(\App\Domain\TrainingPlanner\TrainingPlanRepository::class),
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
            ->expects(self::exactly($times))
            ->method('dispatch')
            ->with(self::isInstanceOf(BuildMonthlyStatsHtml::class));
    }
}
