<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Infrastructure\ValueObject\Measurement\Velocity\KmPerHour;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

final class PlannedSessionLoadEstimatorTest extends ContainerTestCase
{
    private PlannedSessionLoadEstimator $plannedSessionLoadEstimator;
    private AthleteRepository $athleteRepository;
    private ActivityRepository $activityRepository;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAthlete();
        $this->seedRideActivities();
        $this->seedRunActivities();

        $this->plannedSessionLoadEstimator = $this->getContainer()->get(PlannedSessionLoadEstimator::class);
        $this->athleteRepository = $this->getContainer()->get(AthleteRepository::class);
        $this->activityRepository = $this->getContainer()->get(ActivityRepository::class);
    }

    public function testItUsesManualTargetLoadWhenPresent(): void
    {
        $plannedSession = $this->createPlannedSession(
            day: '2026-04-08 00:00:00',
            targetLoad: 72.5,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
        );

        $estimate = $this->plannedSessionLoadEstimator->estimate($plannedSession);

        self::assertNotNull($estimate);
        self::assertSame(72.5, $estimate->getEstimatedLoad());
        self::assertSame(PlannedSessionEstimationSource::MANUAL_TARGET_LOAD, $estimate->getEstimationSource());
    }

    public function testItUsesTemplateActivityLoadWhenTemplateExists(): void
    {
        $templateActivity = $this->activityRepository->find(ActivityId::fromUnprefixed('900001'));
        $expectedLoad = $this->calculateExpectedLoad($templateActivity->getAverageHeartRate(), $templateActivity->getMovingTimeInSeconds(), $templateActivity->getStartDate());
        $plannedSession = $this->createPlannedSession(
            day: '2026-04-09 00:00:00',
            templateActivityId: ActivityId::fromUnprefixed('900001'),
            estimationSource: PlannedSessionEstimationSource::TEMPLATE,
        );

        $estimate = $this->plannedSessionLoadEstimator->estimate($plannedSession);

        self::assertNotNull($estimate);
        self::assertSame($expectedLoad, $estimate->getEstimatedLoad());
        self::assertSame(PlannedSessionEstimationSource::TEMPLATE, $estimate->getEstimationSource());
    }

    public function testDurationAndIntensityEstimatesScaleWithIntensity(): void
    {
        $easySession = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::EASY,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
        );
        $hardSession = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::HARD,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
        );

        $easyEstimate = $this->plannedSessionLoadEstimator->estimate($easySession);
        $hardEstimate = $this->plannedSessionLoadEstimator->estimate($hardSession);

        self::assertNotNull($easyEstimate);
        self::assertNotNull($hardEstimate);
        self::assertSame(PlannedSessionEstimationSource::DURATION_INTENSITY, $easyEstimate->getEstimationSource());
        self::assertSame(PlannedSessionEstimationSource::DURATION_INTENSITY, $hardEstimate->getEstimationSource());
        self::assertGreaterThan($easyEstimate->getEstimatedLoad(), $hardEstimate->getEstimatedLoad());
    }

    public function testWorkoutRidePowerTargetsIncreaseEstimatedLoad(): void
    {
        $easyRide = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            activityType: ActivityType::RIDE,
            workoutSteps: [[
                'itemId' => 'ride-easy-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 3600,
                'distanceInMeters' => null,
                'targetPace' => null,
                'targetPower' => 150,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );
        $hardRide = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            activityType: ActivityType::RIDE,
            workoutSteps: [[
                'itemId' => 'ride-hard-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 3600,
                'distanceInMeters' => null,
                'targetPace' => null,
                'targetPower' => 260,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );

        $easyEstimate = $this->plannedSessionLoadEstimator->estimate($easyRide);
        $hardEstimate = $this->plannedSessionLoadEstimator->estimate($hardRide);

        self::assertNotNull($easyEstimate);
        self::assertNotNull($hardEstimate);
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $easyEstimate->getEstimationSource());
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $hardEstimate->getEstimationSource());
        self::assertGreaterThan($easyEstimate->getEstimatedLoad(), $hardEstimate->getEstimatedLoad());
    }

    public function testWorkoutRunPaceTargetsIncreaseEstimatedLoad(): void
    {
        $easyRun = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            activityType: ActivityType::RUN,
            workoutSteps: [[
                'itemId' => 'run-easy-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 1800,
                'distanceInMeters' => null,
                'targetPace' => '6:00/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );
        $hardRun = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            activityType: ActivityType::RUN,
            workoutSteps: [[
                'itemId' => 'run-hard-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 1800,
                'distanceInMeters' => null,
                'targetPace' => '4:00/km',
                'targetPower' => null,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );

        $easyEstimate = $this->plannedSessionLoadEstimator->estimate($easyRun);
        $hardEstimate = $this->plannedSessionLoadEstimator->estimate($hardRun);

        self::assertNotNull($easyEstimate);
        self::assertNotNull($hardEstimate);
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $easyEstimate->getEstimationSource());
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $hardEstimate->getEstimationSource());
        self::assertGreaterThan($easyEstimate->getEstimatedLoad(), $hardEstimate->getEstimatedLoad());
    }

    public function testWorkoutRunPowerTargetsCanUseRunningThresholdAnchorsWithoutPowerSamples(): void
    {
        $estimator = new PlannedSessionLoadEstimator(
            activityRepository: $this->activityRepository,
            athleteRepository: $this->athleteRepository,
            performanceAnchorHistory: PerformanceAnchorHistory::fromArray([
                'running_threshold_power' => [
                    '2026-03-01' => 250,
                ],
            ]),
        );

        self::assertSame([], $estimator->getPowerLoadPerHourSamplesForActivityType(ActivityType::RUN));

        $runPowerSession = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            activityType: ActivityType::RUN,
            workoutSteps: [[
                'itemId' => 'run-power-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 1800,
                'distanceInMeters' => null,
                'targetPace' => null,
                'targetPower' => 300,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );

        $estimate = $estimator->estimate($runPowerSession);

        self::assertNotNull($estimate);
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $estimate->getEstimationSource());

        $expectedLoadPerHour = round(($this->clamp(300 / 250, 0.35, 1.8) ** 2) * 100, 1);

        self::assertSame(round((1800 / 3600) * $expectedLoadPerHour, 1), $estimate->getEstimatedLoad());
    }

    public function testEstimatedTargetLoadDoesNotBlockWorkoutTargetEstimation(): void
    {
        $plannedSession = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            activityType: ActivityType::RIDE,
            targetLoad: 74.2,
            estimationSource: PlannedSessionEstimationSource::WORKOUT_TARGETS,
            workoutSteps: [[
                'itemId' => 'ride-hard-step',
                'parentBlockId' => null,
                'type' => 'steady',
                'repetitions' => 1,
                'targetType' => 'time',
                'conditionType' => null,
                'durationInSeconds' => 3600,
                'distanceInMeters' => null,
                'targetPace' => null,
                'targetPower' => 260,
                'targetHeartRate' => null,
                'recoveryAfterInSeconds' => null,
            ]],
        );

        $estimate = $this->plannedSessionLoadEstimator->estimate($plannedSession);

        self::assertNotNull($estimate);
        self::assertSame(PlannedSessionEstimationSource::WORKOUT_TARGETS, $estimate->getEstimationSource());
        self::assertNotSame(74.2, $estimate->getEstimatedLoad());
    }

    private function createPlannedSession(
        string $day,
        ?float $targetLoad = null,
        ?int $targetDurationInSeconds = null,
        ?PlannedSessionIntensity $targetIntensity = null,
        ?ActivityId $templateActivityId = null,
        ActivityType $activityType = ActivityType::RIDE,
        PlannedSessionEstimationSource $estimationSource = PlannedSessionEstimationSource::UNKNOWN,
        array $workoutSteps = [],
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: $activityType,
            title: 'Planned ride',
            notes: null,
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: $templateActivityId,
            estimationSource: $estimationSource,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
            workoutSteps: $workoutSteps,
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

    private function seedRideActivities(): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);

        $activities = [
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('900001'))
                ->withName('Template ride')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2026-03-28 10:00:00'))
                ->withMovingTimeInSeconds(5400)
                ->withAveragePower(180)
                ->withAverageHeartRate(146)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('900002'))
                ->withName('Steady ride')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2026-03-30 10:00:00'))
                ->withMovingTimeInSeconds(3600)
                ->withAveragePower(155)
                ->withAverageHeartRate(138)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('900003'))
                ->withName('Hard ride')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2026-04-01 10:00:00'))
                ->withMovingTimeInSeconds(4500)
                ->withAveragePower(245)
                ->withAverageHeartRate(152)
                ->build(),
        ];

        foreach ($activities as $activity) {
            $activityRepository->add(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: [],
            ));
        }
    }

    private function seedRunActivities(): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);

        $activities = [
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910001'))
                ->withName('Easy run')
                ->withSportType(SportType::RUN)
                ->withStartDateTime(SerializableDateTime::fromString('2026-03-27 07:00:00'))
                ->withMovingTimeInSeconds(3600)
                ->withAverageSpeed(KmPerHour::from(10.0))
                ->withAverageHeartRate(132)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910002'))
                ->withName('Steady run')
                ->withSportType(SportType::RUN)
                ->withStartDateTime(SerializableDateTime::fromString('2026-03-31 07:00:00'))
                ->withMovingTimeInSeconds(3000)
                ->withAverageSpeed(KmPerHour::from(12.0))
                ->withAverageHeartRate(150)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910003'))
                ->withName('Hard run')
                ->withSportType(SportType::RUN)
                ->withStartDateTime(SerializableDateTime::fromString('2026-04-02 07:00:00'))
                ->withMovingTimeInSeconds(2400)
                ->withAverageSpeed(KmPerHour::from(15.0))
                ->withAverageHeartRate(170)
                ->build(),
        ];

        foreach ($activities as $activity) {
            $activityRepository->add(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: [],
            ));
        }
    }

    private function calculateExpectedLoad(int $averageHeartRate, int $movingTimeInSeconds, SerializableDateTime $on): float
    {
        $athlete = $this->athleteRepository->find();
        $restingHeartRate = $athlete->getRestingHeartRateFormula($on);
        $maxHeartRate = $athlete->getMaxHeartRate($on);
        $intensity = ($averageHeartRate - $restingHeartRate) / ($maxHeartRate - $restingHeartRate);
        $intensity = max(0.0, min(1.5, $intensity));

        return round(($movingTimeInSeconds / 60) * $intensity * exp(1.92 * $intensity), 1);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
