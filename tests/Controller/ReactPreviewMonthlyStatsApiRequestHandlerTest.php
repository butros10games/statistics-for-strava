<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\CurrentWeekCoachInsightsBuilder;
use App\Controller\ReactPreviewMonthlyStatsApiRequestHandler;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewMonthlyStatsApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewMonthlyStatsApiRequestHandler $requestHandler;

    public function testHandleReturnsMonthlyStatsPayload(): void
    {
        $response = $this->requestHandler->handle(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('navigation', $payload);
        self::assertArrayHasKey('month', $payload);
        self::assertArrayHasKey('currentWeek', $payload);
        self::assertArrayHasKey('calendar', $payload);
        self::assertArrayHasKey('weeks', $payload['calendar']);
        self::assertArrayHasKey('currentMonthId', $payload['navigation']);
        self::assertArrayHasKey('totalActivities', $payload['summary']);
        self::assertArrayHasKey('activityTypeBreakdown', $payload['month']);
        self::assertArrayHasKey('estimatedLoad', $payload['currentWeek']);

        self::assertNotEmpty($payload['calendar']['weeks']);
        self::assertCount(7, $payload['calendar']['weeks'][0]['days']);
        self::assertArrayHasKey('date', $payload['calendar']['weeks'][0]['days'][0]);
        self::assertArrayHasKey('activities', $payload['calendar']['weeks'][0]['days'][0]);
        self::assertArrayHasKey('plannedSessions', $payload['calendar']['weeks'][0]['days'][0]);
        self::assertArrayHasKey('raceEvents', $payload['calendar']['weeks'][0]['days'][0]);
        self::assertArrayHasKey('trainingBlocks', $payload['calendar']['weeks'][0]['days'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewMonthlyStatsApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            enrichedActivities: $this->getContainer()->get(EnrichedActivities::class),
            plannedSessionRepository: $this->getContainer()->get(PlannedSessionRepository::class),
            raceEventRepository: $this->getContainer()->get(RaceEventRepository::class),
            trainingBlockRepository: $this->getContainer()->get(TrainingBlockRepository::class),
            plannedSessionLoadEstimator: $this->getContainer()->get(PlannedSessionLoadEstimator::class),
            currentWeekCoachInsightsBuilder: $this->getContainer()->get(CurrentWeekCoachInsightsBuilder::class),
            queryBus: $this->getContainer()->get(QueryBus::class),
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
}
