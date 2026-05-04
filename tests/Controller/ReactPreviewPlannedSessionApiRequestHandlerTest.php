<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Controller\PlannedSessionRequestHandler;
use App\Controller\ReactPreviewPlannedSessionApiRequestHandler;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\DbalTrainingSessionRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionActivityMatcher;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionForecastBuilder;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\TrainingSession;
use App\Domain\TrainingPlanner\TrainingSessionId;
use App\Domain\TrainingPlanner\TrainingSessionLibrarySynchronizer;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReactPreviewPlannedSessionApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewPlannedSessionApiRequestHandler $requestHandler;
    private DbalPlannedSessionRepository $repository;
    private DbalTrainingSessionRepository $trainingSessionRepository;
    private MockObject $commandBus;

    public function testHandleReturnsBootstrapForExistingPlannedSession(): void
    {
        $this->seedActivity('2026-04-12 08:00:00', 'Sunday long run', 5100);
        $this->seedTrainingSessionRecommendation(
            activityType: ActivityType::RUN,
            title: 'Progression long run',
            lastPlannedOn: '2026-04-08 00:00:00',
            targetLoad: 92.4,
            targetDurationInSeconds: 5700,
            targetIntensity: PlannedSessionIntensity::MODERATE,
        );
        $plannedSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2026-04-12 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Sunday long run',
            notes: 'Keep it controlled.',
            targetLoad: 78.5,
            targetDurationInSeconds: 5100,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            workoutSteps: [[
                'itemId' => 'steady-run',
                'parentBlockId' => null,
                'type' => 'steady',
                'label' => 'Steady effort',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => '',
                'durationInSeconds' => 2400,
                'distanceInMeters' => null,
                'targetPace' => '5:00/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );
        $this->repository->upsert($plannedSession);
        $this->commandBus->expects(self::never())->method('dispatch');

        $response = $this->requestHandler->handle(new Request(query: [
            'plannedSessionId' => (string) $plannedSession->getId(),
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('edit', $payload['mode']);
        self::assertSame('Sunday long run', $payload['context']['plannedSession']['title']);
        self::assertSame('2026-04-12', $payload['defaults']['day']);
        self::assertSame('Run', $payload['defaults']['activityType']);
        self::assertSame('steady-run', $payload['defaults']['workoutSteps'][0]['itemId']);
        self::assertSame('Sunday long run', $payload['context']['matchedActivity']['name']);
        self::assertSame('suggested', $payload['context']['matchStatus']);
        self::assertSame('Progression long run', $payload['options']['recommendations']['Run'][0]['title']);
        self::assertStringContainsString((string) $plannedSession->getId(), $payload['legacyPath']);
    }

    public function testSavePersistsPlannedSessionViaLegacyHandler(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->save(new Request(
            content: json_encode([
                'day' => '2026-04-12',
                'title' => 'Sunday long run',
                'activityType' => 'Run',
                'targetLoad' => '78.5',
                'targetDurationInMinutes' => '85',
                'targetDurationInSecondsPart' => '30',
                'targetIntensity' => 'moderate',
                'notes' => 'Keep it controlled',
            ], JSON_THROW_ON_ERROR),
            server: [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame('Sunday long run', $records[0]->getTitle());
        self::assertSame(78.5, $records[0]->getTargetLoad());
        self::assertSame(5130, $records[0]->getTargetDurationInSeconds());

        $trainingSession = $this->trainingSessionRepository->findBySourcePlannedSessionId($records[0]->getId());
        self::assertNotNull($trainingSession);
        self::assertSame('Sunday long run', $trainingSession->getTitle());
    }

    public function testDeleteRemovesPlannedSessionViaLegacyHandler(): void
    {
        $plannedSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2026-04-12 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Sunday long run',
            notes: null,
            targetLoad: 78.5,
            targetDurationInSeconds: 5100,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );
        $this->repository->upsert($plannedSession);
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->delete((string) $plannedSession->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertNull($this->repository->findById($plannedSession->getId()));
    }

    public function testConfirmLinkMarksSessionAsLinked(): void
    {
        $this->seedActivity('2026-04-12 08:00:00', 'Sunday long run', 5100);
        $plannedSession = PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString('2026-04-12 00:00:00'),
            activityType: ActivityType::RUN,
            title: 'Sunday long run',
            notes: null,
            targetLoad: 78.5,
            targetDurationInSeconds: 5100,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        );
        $this->repository->upsert($plannedSession);
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->confirmLink(
            (string) $plannedSession->getId(),
            new Request(
                content: json_encode([
                    'linkedActivityId' => 'activity-'.md5('2026-04-12 08:00:00Sunday long run'.SportType::RUN->value),
                ], JSON_THROW_ON_ERROR),
                server: [
                    'REQUEST_METHOD' => 'POST',
                    'CONTENT_TYPE' => 'application/json',
                ],
            ),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $reloaded = $this->repository->findById($plannedSession->getId());
        self::assertNotNull($reloaded);
        self::assertSame(PlannedSessionLinkStatus::LINKED, $reloaded->getLinkStatus());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAthlete();
        $this->repository = new DbalPlannedSessionRepository($this->getConnection());
        $this->trainingSessionRepository = new DbalTrainingSessionRepository($this->getConnection());
        $legacyHandler = new PlannedSessionRequestHandler(
            repository: $this->repository,
            trainingSessionRepository: $this->trainingSessionRepository,
            trainingSessionLibrarySynchronizer: $this->getContainer()->get(TrainingSessionLibrarySynchronizer::class),
            activityRepository: $this->getContainer()->get(DbalActivityRepository::class),
            plannedSessionActivityMatcher: $this->getContainer()->get(PlannedSessionActivityMatcher::class),
            plannedSessionLoadEstimator: $this->getContainer()->get(PlannedSessionLoadEstimator::class),
            plannedSessionForecastBuilder: $this->getContainer()->get(PlannedSessionForecastBuilder::class),
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(Clock::class),
            twig: $this->getContainer()->get(Environment::class),
        );
        $this->requestHandler = new ReactPreviewPlannedSessionApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            repository: $this->repository,
            trainingSessionRepository: $this->trainingSessionRepository,
            activityRepository: $this->getContainer()->get(DbalActivityRepository::class),
            plannedSessionActivityMatcher: $this->getContainer()->get(PlannedSessionActivityMatcher::class),
            plannedSessionLoadEstimator: $this->getContainer()->get(PlannedSessionLoadEstimator::class),
            plannedSessionForecastBuilder: $this->getContainer()->get(PlannedSessionForecastBuilder::class),
            plannedSessionRequestHandler: $legacyHandler,
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

    private function seedAthlete(): void
    {
        $this->getContainer()->get(AthleteRepository::class)->save(Athlete::create([
            'id' => 100,
            'birthDate' => '1989-08-14',
            'firstname' => 'Robin',
            'lastname' => 'Ingelbrecht',
            'sex' => 'M',
        ]));
    }

    private function seedActivity(string $startDate, string $name, int $movingTimeInSeconds, SportType $sportType = SportType::RUN): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed(md5($startDate.$name.$sportType->value)))
                ->withName($name)
                ->withSportType($sportType)
                ->withStartDateTime(SerializableDateTime::fromString($startDate))
                ->withMovingTimeInSeconds($movingTimeInSeconds)
                ->build(),
            rawData: [],
        ));
    }

    private function seedTrainingSessionRecommendation(
        ActivityType $activityType,
        string $title,
        string $lastPlannedOn,
        ?float $targetLoad = null,
        ?int $targetDurationInSeconds = null,
        ?PlannedSessionIntensity $targetIntensity = null,
        ?string $notes = null,
        array $workoutSteps = [],
    ): void {
        $lastPlannedOnDate = SerializableDateTime::fromString($lastPlannedOn);

        $this->trainingSessionRepository->upsert(TrainingSession::create(
            trainingSessionId: TrainingSessionId::random(),
            sourcePlannedSessionId: null,
            activityType: $activityType,
            title: $title,
            notes: $notes,
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: null,
            estimationSource: [] === $workoutSteps ? PlannedSessionEstimationSource::MANUAL_TARGET_LOAD : PlannedSessionEstimationSource::WORKOUT_TARGETS,
            lastPlannedOn: $lastPlannedOnDate,
            createdAt: $lastPlannedOnDate,
            updatedAt: $lastPlannedOnDate,
            workoutSteps: $workoutSteps,
        ));
    }

    private function expectPlannerRebuilds(int $times = 1): void
    {
        $this->commandBus
            ->expects(self::exactly($times * 2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(BuildDashboardHtml::class),
                self::isInstanceOf(BuildMonthlyStatsHtml::class),
            ));
    }
}
