<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Controller\ReactPreviewRecoveryCheckInApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Wellness\DbalDailyRecoveryCheckInRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReactPreviewRecoveryCheckInApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewRecoveryCheckInApiRequestHandler $requestHandler;
    private CommandBus $commandBus;
    private DbalDailyRecoveryCheckInRepository $repository;
    private Clock $clock;

    public function testHandleReturnsRecoveryDefaults(): void
    {
        $this->commandBus
            ->expects(self::never())
            ->method('dispatch');

        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('form', $payload);
        self::assertArrayHasKey('latestCheckIn', $payload);
        self::assertArrayHasKey('state', $payload['summary']);
        self::assertArrayHasKey('defaults', $payload['form']);
        self::assertSame('empty', $payload['summary']['state']);
        self::assertFalse($payload['summary']['hasTodayCheckIn']);
        self::assertSame(3, $payload['form']['defaults']['fatigue']);
    }

    public function testSavePersistsRecoveryCheckInAndReturnsUpdatedPayload(): void
    {
        $today = $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d');

        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(BuildDashboardHtml::class));

        $response = $this->requestHandler->save(new Request(
            request: [
                'day' => $today,
                'fatigue' => 8,
                'soreness' => 0,
                'stress' => 2,
                'motivation' => 4,
                'sleepQuality' => 5,
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('updated-today', $payload['summary']['state']);
        self::assertTrue($payload['summary']['hasTodayCheckIn']);
        self::assertSame($today, $payload['savedDay']);
        self::assertSame(5, $payload['todayCheckIn']['fatigue']);
        self::assertSame(1, $payload['todayCheckIn']['soreness']);
        self::assertSame(3.8, $payload['todayCheckIn']['readinessScore']);

        $saved = $this->repository->findByDay(SerializableDateTime::fromString($today));

        self::assertNotNull($saved);
        self::assertSame(5, $saved->getFatigue());
        self::assertSame(1, $saved->getSoreness());
        self::assertSame(2, $saved->getStress());
        self::assertSame(4, $saved->getMotivation());
        self::assertSame(5, $saved->getSleepQuality());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->getContainer()->get(DbalDailyRecoveryCheckInRepository::class);
        $this->clock = $this->getContainer()->get(Clock::class);
        $this->commandBus = $this->createMock(CommandBus::class);

        $this->requestHandler = new ReactPreviewRecoveryCheckInApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            repository: $this->repository,
            commandBus: $this->commandBus,
            clock: $this->clock,
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
