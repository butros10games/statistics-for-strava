<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Controller\PlannedSessionRequestHandler;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\TrainingPlanner\PlannedSessionActivityMatcher;
use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionForecastBuilder;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class PlannedSessionRequestHandlerTest extends ContainerTestCase
{
    private PlannedSessionRequestHandler $requestHandler;
    private DbalPlannedSessionRepository $repository;
    private MockObject $commandBus;

    public function testHandleGetRendersPlannerModal(): void
    {
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $response = $this->requestHandler->handle(new Request(query: ['day' => '2026-04-12']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Planned session', (string) $response->getContent());
        self::assertStringContainsString('Add repeat block', (string) $response->getContent());
        self::assertStringContainsString('Planner outlook', (string) $response->getContent());
        self::assertStringContainsString('option value="steady" selected', (string) $response->getContent());
    }

    public function testHandleGetShowsRecentTemplateActivitiesAndPlannerOutlook(): void
    {
        $this->seedActivity('2026-04-06 08:00:00', 'Easy jog', 2400);
        $this->seedActivity('2026-04-05 08:00:00', 'Long ride', 5400, SportType::RIDE);
        $this->commandBus->expects(self::never())->method('dispatch');

        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $response = $this->requestHandler->handle(new Request(query: ['day' => '2026-04-12']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Reuse duration and load profile from a recent activity.', (string) $response->getContent());
        self::assertStringContainsString('Easy jog', (string) $response->getContent());
        self::assertStringContainsString('No estimated planned sessions in the next 14 days yet.', (string) $response->getContent());
    }

    public function testHandlePostPersistsPlannedSession(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Sunday long run',
                'activityType' => 'Run',
                'targetLoad' => '78.5',
                'targetDurationInMinutes' => '85',
                'targetDurationInSecondsPart' => '30',
                'targetIntensity' => 'moderate',
                'notes' => 'Keep it controlled',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(\App\Infrastructure\ValueObject\Time\SerializableDateTime::fromString('2026-04-12 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame('Sunday long run', $records[0]->getTitle());
        self::assertSame(78.5, $records[0]->getTargetLoad());
        self::assertSame(5130, $records[0]->getTargetDurationInSeconds());
        self::assertSame(PlannedSessionEstimationSource::MANUAL_TARGET_LOAD, $records[0]->getEstimationSource());
    }

    public function testHandlePostKeepsWorkoutEstimateSourceWhenManualOverrideIsDisabled(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Controlled run',
                'activityType' => 'Run',
                'targetLoad' => '55.0',
                'targetDurationInMinutes' => '40',
                'manualTargetLoadOverride' => '0',
                'workoutSteps' => [[
                    'itemId' => 'steady-run',
                    'parentBlockId' => '',
                    'type' => 'steady',
                    'label' => 'Steady effort',
                    'repetitions' => '1',
                    'targetType' => 'heartRate',
                    'conditionType' => 'holdTarget',
                    'durationInMinutes' => '40',
                    'durationInSecondsPart' => '',
                    'distanceInMeters' => '',
                    'targetPace' => '',
                    'targetPower' => '',
                    'targetHeartRate' => '150',
                ]],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame(55.0, $records[0]->getTargetLoad());
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $records[0]->getEstimationSource());
    }

    public function testHandlePostRedirectsToRequestedLocation(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Sunday long run',
                'activityType' => 'Run',
                'targetDurationInMinutes' => '85',
                'redirectTo' => '/monthly-stats#/month/month-2026-04.html',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats#/month/month-2026-04.html', Response::HTTP_FOUND), $response);
    }

    public function testHandlePostPersistsStructuredWorkoutStepsAndDerivedDuration(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Track workout',
                'activityType' => ActivityType::RUN->value,
                'targetIntensity' => 'hard',
                'workoutSteps' => [
                    [
                        'itemId' => 'warmup-1',
                        'parentBlockId' => '',
                        'type' => 'warmup',
                        'label' => 'Jog in',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '15',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '5:25/km',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'block-1',
                        'parentBlockId' => '',
                        'type' => 'repeatBlock',
                        'label' => 'Track reps',
                        'repetitions' => '4',
                        'targetType' => '',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'interval-1',
                        'parentBlockId' => 'block-1',
                        'type' => 'interval',
                        'label' => '1K reps',
                        'repetitions' => '1',
                        'targetType' => 'distance',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '1000',
                        'targetPace' => '4:00/km',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'recovery-1',
                        'parentBlockId' => 'block-1',
                        'type' => 'recovery',
                        'label' => 'Float',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '1',
                        'durationInSecondsPart' => '30',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'hr-1',
                        'parentBlockId' => '',
                        'type' => 'steady',
                        'label' => 'HR settle',
                        'repetitions' => '1',
                        'targetType' => 'heartRate',
                        'conditionType' => 'untilBelow',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '150',
                    ],
                ],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00'));
        self::assertCount(1, $records);
        self::assertCount(5, $records[0]->getWorkoutSteps());
        self::assertNull($records[0]->getTargetDurationInSeconds());
        self::assertSame('block-1', $records[0]->getWorkoutSteps()[1]['itemId']);
        self::assertSame('block-1', $records[0]->getWorkoutSteps()[2]['parentBlockId']);
        self::assertSame(90, $records[0]->getWorkoutSteps()[3]['durationInSeconds']);
        self::assertSame('heartRate', $records[0]->getWorkoutSteps()[4]['targetType']);
        self::assertSame('untilBelow', $records[0]->getWorkoutSteps()[4]['conditionType']);
        self::assertSame(150, $records[0]->getWorkoutSteps()[4]['targetHeartRate']);
    }

    public function testHandlePostPersistsNestedRepeatBlocks(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-17',
                'title' => 'Nested block workout',
                'activityType' => ActivityType::RUN->value,
                'targetIntensity' => 'hard',
                'workoutSteps' => [
                    [
                        'itemId' => 'outer-block',
                        'parentBlockId' => '',
                        'type' => 'repeatBlock',
                        'label' => 'Outer set',
                        'repetitions' => '2',
                        'targetType' => '',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'inner-block',
                        'parentBlockId' => 'outer-block',
                        'type' => 'repeatBlock',
                        'label' => 'Inner reps',
                        'repetitions' => '3',
                        'targetType' => '',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'inner-interval',
                        'parentBlockId' => 'inner-block',
                        'type' => 'interval',
                        'label' => 'On',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '1',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '4:00/km',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'inner-recovery',
                        'parentBlockId' => 'inner-block',
                        'type' => 'recovery',
                        'label' => 'Off',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '30',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                ],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-17 00:00:00'));
        self::assertCount(1, $records);
        self::assertCount(4, $records[0]->getWorkoutSteps());
        self::assertSame(540, $records[0]->getTargetDurationInSeconds());
        self::assertSame('outer-block', $records[0]->getWorkoutSteps()[1]['parentBlockId']);
        self::assertSame('inner-block', $records[0]->getWorkoutSteps()[2]['parentBlockId']);
        self::assertSame('inner-block', $records[0]->getWorkoutSteps()[3]['parentBlockId']);
    }

    public function testHandlePostPersistsTimeTargetWithUntilButtonPressCondition(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-13',
                'title' => 'Easy run with flexible cooldown',
                'activityType' => ActivityType::RUN->value,
                'targetIntensity' => 'easy',
                'workoutSteps' => [
                    [
                        'itemId' => 'warmup-press',
                        'parentBlockId' => '',
                        'type' => 'warmup',
                        'label' => 'Warm up',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => 'lapButton',
                        'durationInMinutes' => '12',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '5:50/km',
                        'targetHeartRate' => '',
                    ],
                ],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-13 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame(720, $records[0]->getTargetDurationInSeconds());
        self::assertCount(1, $records[0]->getWorkoutSteps());
        self::assertSame('time', $records[0]->getWorkoutSteps()[0]['targetType']);
        self::assertSame('lapButton', $records[0]->getWorkoutSteps()[0]['conditionType']);
        self::assertSame(720, $records[0]->getWorkoutSteps()[0]['durationInSeconds']);
    }

    public function testHandlePostDerivesDurationFromUnitlessRunningPaceValues(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-15',
                'title' => '6x 800 meter',
                'activityType' => ActivityType::RUN->value,
                'targetIntensity' => 'hard',
                'workoutSteps' => [
                    [
                        'itemId' => 'warmup',
                        'parentBlockId' => '',
                        'type' => 'warmup',
                        'label' => '',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => 'lapButton',
                        'durationInMinutes' => '10',
                        'durationInSecondsPart' => '0',
                        'distanceInMeters' => '',
                        'targetPace' => '6:00',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'block',
                        'parentBlockId' => '',
                        'type' => 'repeatBlock',
                        'label' => '',
                        'repetitions' => '8',
                        'targetType' => '',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'interval',
                        'parentBlockId' => 'block',
                        'type' => 'interval',
                        'label' => 'ongeveer 3 minuten',
                        'repetitions' => '1',
                        'targetType' => 'distance',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '800',
                        'targetPace' => '4:00',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'recovery',
                        'parentBlockId' => 'block',
                        'type' => 'recovery',
                        'label' => '',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '1',
                        'durationInSecondsPart' => '0',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'cooldown',
                        'parentBlockId' => '',
                        'type' => 'cooldown',
                        'label' => '',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => 'lapButton',
                        'durationInMinutes' => '10',
                        'durationInSecondsPart' => '0',
                        'distanceInMeters' => '',
                        'targetPace' => '6:00',
                        'targetHeartRate' => '',
                    ],
                ],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-15 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame(3216, $records[0]->getTargetDurationInSeconds());
    }

    public function testHandlePostPersistsCyclingPowerTargetsForTimeAndDistanceSteps(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-14',
                'title' => 'Bike intervals',
                'activityType' => ActivityType::RIDE->value,
                'targetIntensity' => 'moderate',
                'workoutSteps' => [
                    [
                        'itemId' => 'ride-warmup',
                        'parentBlockId' => '',
                        'type' => 'warmup',
                        'label' => 'Spin up',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '10',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '',
                        'targetPower' => '190',
                        'targetHeartRate' => '',
                    ],
                    [
                        'itemId' => 'ride-interval',
                        'parentBlockId' => '',
                        'type' => 'interval',
                        'label' => 'Threshold pull',
                        'repetitions' => '1',
                        'targetType' => 'distance',
                        'conditionType' => '',
                        'durationInMinutes' => '',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '5000',
                        'targetPace' => '',
                        'targetPower' => '240',
                        'targetHeartRate' => '',
                    ],
                ],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-14 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame(ActivityType::RIDE, $records[0]->getActivityType());
        self::assertNull($records[0]->getTargetDurationInSeconds());
        self::assertCount(2, $records[0]->getWorkoutSteps());
        self::assertSame(190, $records[0]->getWorkoutSteps()[0]['targetPower']);
        self::assertNull($records[0]->getWorkoutSteps()[0]['targetPace']);
        self::assertSame(240, $records[0]->getWorkoutSteps()[1]['targetPower']);
        self::assertNull($records[0]->getWorkoutSteps()[1]['targetPace']);
        self::assertSame(5000, $records[0]->getWorkoutSteps()[1]['distanceInMeters']);
    }

    public function testHandlePostPreservesRunningPowerTargetsWhenSubmitted(): void
    {
        $this->expectPlannerRebuilds();

        $response = $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-16',
                'title' => 'Power based run workout',
                'activityType' => ActivityType::RUN->value,
                'targetIntensity' => 'hard',
                'workoutSteps' => [
                    [
                        'itemId' => 'run-power-step',
                        'parentBlockId' => '',
                        'type' => 'interval',
                        'label' => 'Threshold effort',
                        'repetitions' => '1',
                        'targetType' => 'time',
                        'conditionType' => '',
                        'durationInMinutes' => '8',
                        'durationInSecondsPart' => '',
                        'distanceInMeters' => '',
                        'targetPace' => '4:05/km',
                        'targetPower' => '300',
                        'targetHeartRate' => '',
                    ],
                ],
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-16 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame(ActivityType::RUN, $records[0]->getActivityType());
        self::assertCount(1, $records[0]->getWorkoutSteps());
        self::assertSame(300, $records[0]->getWorkoutSteps()[0]['targetPower']);
        self::assertNull($records[0]->getWorkoutSteps()[0]['targetPace']);
    }

    public function testHandlePostPersistsConfirmedLinkWhenActivityExists(): void
    {
        $this->seedActivity('2026-04-12 08:00:00', 'Sunday long run', 5100);
        $this->expectPlannerRebuilds();

        $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Sunday long run',
                'activityType' => ActivityType::RUN->value,
                'targetDurationInMinutes' => '85',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        $records = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00'));
        self::assertCount(1, $records);
        self::assertSame(PlannedSessionLinkStatus::LINKED, $records[0]->getLinkStatus());
        self::assertSame('activity-'.md5('2026-04-12 08:00:00Sunday long run'.SportType::RUN->value), (string) $records[0]->getLinkedActivityId());
    }

    public function testConfirmLinkMarksPlannedSessionAsLinked(): void
    {
        $this->seedActivity('2026-04-12 08:00:00', 'Sunday long run', 5100);
        $this->expectPlannerRebuilds(2);

        $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Sunday long run',
                'activityType' => ActivityType::RUN->value,
                'targetDurationInMinutes' => '85',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        $plannedSession = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00'))[0];
        $response = $this->requestHandler->confirmLink(new Request(
            request: [
                'plannedSessionId' => (string) $plannedSession->getId(),
                'linkedActivityId' => 'activity-'.md5('2026-04-12 08:00:00Sunday long run'.SportType::RUN->value),
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/dashboard', Response::HTTP_FOUND), $response);
        $reloaded = $this->repository->findById(PlannedSessionId::fromString((string) $plannedSession->getId()));
        self::assertNotNull($reloaded);
        self::assertSame(PlannedSessionLinkStatus::LINKED, $reloaded->getLinkStatus());
    }

    public function testDeleteRemovesPlannedSession(): void
    {
        $this->expectPlannerRebuilds(2);

        $this->requestHandler->handle(new Request(
            request: [
                'day' => '2026-04-12',
                'title' => 'Sunday long run',
                'activityType' => ActivityType::RUN->value,
                'targetDurationInMinutes' => '85',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        $plannedSession = $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00'))[0];

        $response = $this->requestHandler->delete(new Request(
            request: [
                'plannedSessionId' => (string) $plannedSession->getId(),
                'redirectTo' => '/monthly-stats#/month/month-2026-04.html',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        self::assertEquals(new RedirectResponse('/monthly-stats#/month/month-2026-04.html', Response::HTTP_FOUND), $response);
        self::assertSame([], $this->repository->findByDay(SerializableDateTime::fromString('2026-04-12 00:00:00')));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAthlete();
        $this->repository = new DbalPlannedSessionRepository($this->getConnection());
        $this->requestHandler = new PlannedSessionRequestHandler(
            repository: $this->repository,
            activityRepository: $this->getContainer()->get(DbalActivityRepository::class),
            plannedSessionActivityMatcher: $this->getContainer()->get(PlannedSessionActivityMatcher::class),
            plannedSessionLoadEstimator: $this->getContainer()->get(PlannedSessionLoadEstimator::class),
            plannedSessionForecastBuilder: $this->getContainer()->get(PlannedSessionForecastBuilder::class),
            commandBus: $this->commandBus = $this->createMock(CommandBus::class),
            clock: $this->getContainer()->get(\App\Infrastructure\Time\Clock\Clock::class),
            twig: $this->getContainer()->get(Environment::class),
        );
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
