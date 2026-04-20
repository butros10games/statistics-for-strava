<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Controller\RacePlannerStartDateRequestHandler;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RacePlannerStartDateRequestHandlerTest extends ContainerTestCase
{
    private RacePlannerStartDateRequestHandler $requestHandler;
    private RacePlannerConfiguration $racePlannerConfiguration;
    private MockObject $commandBus;

    public function testHandlePersistsRequestedPlanStartDay(): void
    {
        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(BuildRacePlannerHtml::class));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'planStartDay' => '2026-04-21',
                'redirectTo' => '/race-planner',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/race-planner', Response::HTTP_FOUND), $response);
        self::assertSame(
            '2026-04-21 00:00:00',
            $this->racePlannerConfiguration->findPlanStartDay()?->format('Y-m-d H:i:s'),
        );
    }

    public function testHandleClearsConfiguredPlanStartDayWhenResetRequested(): void
    {
        $this->racePlannerConfiguration->savePlanStartDay(SerializableDateTime::fromString('2026-04-21 00:00:00'));
        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(BuildRacePlannerHtml::class));

        $response = $this->requestHandler->handle(new Request(
            request: [
                'planStartDay' => '2026-04-21',
                'resetPlanStartDay' => '1',
                'redirectTo' => '/race-planner',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/race-planner', Response::HTTP_FOUND), $response);
        self::assertNull($this->racePlannerConfiguration->findPlanStartDay());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->racePlannerConfiguration = $this->getContainer()->get(RacePlannerConfiguration::class);
        $this->racePlannerConfiguration->clearPlanStartDay();
        $this->requestHandler = new RacePlannerStartDateRequestHandler(
            racePlannerConfiguration: $this->racePlannerConfiguration,
            raceEventRepository: $this->getContainer()->get(\App\Domain\TrainingPlanner\RaceEventRepository::class),
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(Clock::class),
        );
    }
}