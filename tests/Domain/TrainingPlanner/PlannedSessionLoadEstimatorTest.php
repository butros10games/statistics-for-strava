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
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
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

    private function createPlannedSession(
        string $day,
        ?float $targetLoad = null,
        ?int $targetDurationInSeconds = null,
        ?PlannedSessionIntensity $targetIntensity = null,
        ?ActivityId $templateActivityId = null,
        PlannedSessionEstimationSource $estimationSource = PlannedSessionEstimationSource::UNKNOWN,
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: ActivityType::RIDE,
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
                ->withAverageHeartRate(146)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('900002'))
                ->withName('Steady ride')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2026-03-30 10:00:00'))
                ->withMovingTimeInSeconds(3600)
                ->withAverageHeartRate(138)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('900003'))
                ->withName('Hard ride')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2026-04-01 10:00:00'))
                ->withMovingTimeInSeconds(4500)
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

    private function calculateExpectedLoad(int $averageHeartRate, int $movingTimeInSeconds, SerializableDateTime $on): float
    {
        $athlete = $this->athleteRepository->find();
        $restingHeartRate = $athlete->getRestingHeartRateFormula($on);
        $maxHeartRate = $athlete->getMaxHeartRate($on);
        $intensity = ($averageHeartRate - $restingHeartRate) / ($maxHeartRate - $restingHeartRate);
        $intensity = max(0.0, min(1.5, $intensity));

        return round(($movingTimeInSeconds / 60) * $intensity * exp(1.92 * $intensity), 1);
    }
}
