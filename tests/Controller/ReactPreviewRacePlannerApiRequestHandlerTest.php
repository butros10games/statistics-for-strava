<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\ReactPreview\RacePlannerPreviewPayloadBuilder;
use App\Controller\RacePlannerRegenerateUpcomingSessionsRequestHandler;
use App\Controller\RacePlannerSaveRecoveryRequestHandler;
use App\Controller\RacePlannerSetupPlanRequestHandler;
use App\Controller\RacePlannerStartDateRequestHandler;
use App\Controller\ReactPreviewRacePlannerApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingPlanRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReactPreviewRacePlannerApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewRacePlannerApiRequestHandler $requestHandler;
    private DbalRaceEventRepository $raceEventRepository;
    private DbalTrainingPlanRepository $trainingPlanRepository;

    public function testHandleReturnsEmptyPlannerPayloadWhenNoUpcomingRacesExist(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['hasUpcomingRaces']);
        self::assertSame('global', $payload['mode']);
        self::assertNull($payload['targetRace']);
        self::assertSame([], $payload['displayedUpcomingRaces']);
        self::assertFalse($payload['actions']['canSetupPlan']);
    }

    public function testHandleReturnsLivePlannerPayloadForUpcomingRace(): void
    {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'React planner goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($targetRace);

        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['hasUpcomingRaces']);
        self::assertSame('global', $payload['mode']);
        self::assertSame('React planner goal race', $payload['targetRace']['title']);
        self::assertSame('Ghent', $payload['targetRace']['location']);
        self::assertNotNull($payload['proposal']);
        self::assertNotNull($payload['rules']);
        self::assertTrue($payload['actions']['canSetupPlan']);
    }

    public function testHandlePlanPreviewReturnsPreviewPayloadForStoredTrainingPlan(): void
    {
        $trainingPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-12-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2027-01-24 00:00:00'),
            targetRaceEventId: null,
            title: 'Winter bridge block',
            notes: 'Keep some pop in the legs.',
            createdAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-10-01 08:00:00'),
            discipline: TrainingPlanDiscipline::RUNNING,
            performanceMetrics: [
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 48.0,
            ],
            targetRaceProfile: RaceEventProfile::HALF_MARATHON,
        );
        $this->trainingPlanRepository->upsert($trainingPlan);

        $response = $this->requestHandler->handlePlanPreview((string) $trainingPlan->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('plan-preview', $payload['mode']);
        self::assertSame('Winter bridge block', $payload['linkedTrainingPlan']['title']);
        self::assertSame([], $payload['displayedUpcomingRaces']);
        self::assertFalse($payload['plannerSupportsRaceActions']);
        self::assertTrue($payload['actions']['canEditLinkedTrainingPlan']);
        self::assertNotNull($payload['runningPerformancePrediction']);
    }

    public function testUpdateStartDateDelegatesToExistingPlannerHandler(): void
    {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-11-29 00:00:00'),
            type: RaceEventType::HALF_MARATHON,
            title: 'Configured goal race',
            location: 'Ghent',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 5700,
            createdAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-09-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($targetRace);

        $response = $this->requestHandler->updateStartDate(new Request(
            content: json_encode([
                'planStartDay' => '2026-10-20',
            ], JSON_THROW_ON_ERROR),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'REQUEST_METHOD' => 'POST',
            ],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            '2026-10-20',
            $this->getContainer()->get(RacePlannerConfiguration::class)->findPlanStartDay()?->format('Y-m-d'),
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->raceEventRepository = new DbalRaceEventRepository($this->getConnection());
        $this->trainingPlanRepository = new DbalTrainingPlanRepository($this->getConnection());

        $this->requestHandler = new ReactPreviewRacePlannerApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            trainingPlanRepository: $this->trainingPlanRepository,
            payloadBuilder: $this->getContainer()->get(RacePlannerPreviewPayloadBuilder::class),
            setupPlanRequestHandler: $this->getContainer()->get(RacePlannerSetupPlanRequestHandler::class),
            regenerateUpcomingSessionsRequestHandler: $this->getContainer()->get(RacePlannerRegenerateUpcomingSessionsRequestHandler::class),
            startDateRequestHandler: $this->getContainer()->get(RacePlannerStartDateRequestHandler::class),
            saveRecoveryRequestHandler: $this->getContainer()->get(RacePlannerSaveRecoveryRequestHandler::class),
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
