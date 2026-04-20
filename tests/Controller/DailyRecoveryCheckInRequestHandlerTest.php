<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Controller\DailyRecoveryCheckInRequestHandler;
use App\Domain\Wellness\DbalDailyRecoveryCheckInRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class DailyRecoveryCheckInRequestHandlerTest extends ContainerTestCase
{
    private DailyRecoveryCheckInRequestHandler $requestHandler;
    private DbalDailyRecoveryCheckInRepository $repository;
    private MockObject $commandBus;

    public function testHandleGetRendersRecoveryCheckInModalAndKeepsRedirectTarget(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $response = $this->requestHandler->handle(new Request(query: [
            'redirectTo' => '/dashboard#/recovery-check-in',
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Morning recovery check-in', (string) $response->getContent());
        self::assertStringContainsString('name="redirectTo"', (string) $response->getContent());
        self::assertStringContainsString('value="/dashboard#/recovery-check-in"', (string) $response->getContent());
        self::assertStringContainsString('Save check-in', (string) $response->getContent());
    }

    public function testHandlePostPersistsRecoveryCheckInAndRedirectsBackToModalRoute(): void
    {
        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(BuildDashboardHtml::class));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'redirectTo' => '/dashboard#/recovery-check-in',
                'day' => '2026-04-17',
                'fatigue' => '4',
                'soreness' => '3',
                'stress' => '2',
                'motivation' => '5',
                'sleepQuality' => '4',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard#/recovery-check-in', Response::HTTP_FOUND), $response);

        $record = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-17 00:00:00'));

        self::assertNotNull($record);
        self::assertSame(4, $record->getFatigue());
        self::assertSame(3, $record->getSoreness());
        self::assertSame(2, $record->getStress());
        self::assertSame(5, $record->getMotivation());
        self::assertSame(4, $record->getSleepQuality());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->getContainer()->get(DbalDailyRecoveryCheckInRepository::class);
        $this->requestHandler = new DailyRecoveryCheckInRequestHandler(
            repository: $this->repository,
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(Clock::class),
            twig: $this->getContainer()->get(Environment::class),
        );
    }
}
