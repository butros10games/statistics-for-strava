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
use App\Domain\TrainingPlanner\PlannedSessionForecastBuilder;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

final class PlannedSessionForecastBuilderTest extends ContainerTestCase
{
    private PlannedSessionRepository $plannedSessionRepository;
    private PlannedSessionLoadEstimator $plannedSessionLoadEstimator;
    private PlannedSessionForecastBuilder $plannedSessionForecastBuilder;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAthlete();
        $this->seedRideActivities();

        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $this->plannedSessionLoadEstimator = $this->getContainer()->get(PlannedSessionLoadEstimator::class);
        $this->plannedSessionForecastBuilder = $this->getContainer()->get(PlannedSessionForecastBuilder::class);
    }

    public function testItAggregatesEstimatedLoadsByForecastDay(): void
    {
        $manualTomorrow = $this->createPlannedSession(
            day: '2026-04-08 00:00:00',
            title: 'Manual ride',
            targetLoad: 48.0,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
        );
        $durationTomorrow = $this->createPlannedSession(
            day: '2026-04-08 00:00:00',
            title: 'Tempo ride',
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::HARD,
            estimationSource: PlannedSessionEstimationSource::DURATION_INTENSITY,
        );
        $templateInThreeDays = $this->createPlannedSession(
            day: '2026-04-10 00:00:00',
            title: 'Zwift template',
            templateActivityId: ActivityId::fromUnprefixed('900001'),
            estimationSource: PlannedSessionEstimationSource::TEMPLATE,
        );

        $this->plannedSessionRepository->upsert($manualTomorrow);
        $this->plannedSessionRepository->upsert($durationTomorrow);
        $this->plannedSessionRepository->upsert($templateInThreeDays);

        $manualEstimate = $this->plannedSessionLoadEstimator->estimate($manualTomorrow);
        $durationEstimate = $this->plannedSessionLoadEstimator->estimate($durationTomorrow);
        $templateEstimate = $this->plannedSessionLoadEstimator->estimate($templateInThreeDays);

        $forecast = $this->plannedSessionForecastBuilder->build(
            SerializableDateTime::fromString('2026-04-07 09:00:00'),
            5,
        );
        $projectedLoads = $forecast->getProjectedLoads();

        self::assertNotNull($manualEstimate);
        self::assertNotNull($durationEstimate);
        self::assertNotNull($templateEstimate);
        self::assertCount(3, $forecast->getEstimates());
        self::assertSame(
            round($manualEstimate->getEstimatedLoad() + $durationEstimate->getEstimatedLoad(), 1),
            $projectedLoads[1],
        );
        self::assertSame(0.0, $projectedLoads[2]);
        self::assertSame($templateEstimate->getEstimatedLoad(), $projectedLoads[3]);
        self::assertSame(0.0, $projectedLoads[4]);
        self::assertSame(0.0, $projectedLoads[5]);
    }

    private function createPlannedSession(
        string $day,
        string $title,
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
            title: $title,
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
}
