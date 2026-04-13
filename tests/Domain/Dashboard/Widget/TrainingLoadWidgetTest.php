<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadWidget;
use App\Domain\Dashboard\Widget\WidgetConfiguration;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use League\Flysystem\FilesystemOperator;

final class TrainingLoadWidgetTest extends ContainerTestCase
{
    private TrainingLoadWidget $trainingLoadWidget;
    private PlannedSessionRepository $plannedSessionRepository;
    private FilesystemOperator $buildStorage;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAthlete();
        $this->seedRideActivities();

        $this->trainingLoadWidget = $this->getContainer()->get(TrainingLoadWidget::class);
        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
        $this->buildStorage = $this->getContainer()->get('build.storage');
    }

    public function testItRendersPlannedSessionForecastDetailsInTheModal(): void
    {
        if (!class_exists(\MessageFormatter::class)) {
            self::assertTrue(true);

            return;
        }

        $now = $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable()->setTime(0, 0);

        $this->plannedSessionRepository->upsert(PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: $now->modify('+1 day'),
            activityType: \App\Domain\Activity\ActivityType::RIDE,
            title: 'Manual ride',
            notes: null,
            targetLoad: 55.0,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-07 08:00:00'),
        ));

        $renderedWidget = $this->trainingLoadWidget->render(
            now: $now,
            configuration: WidgetConfiguration::empty(),
        );
        $trainingLoadModal = $this->buildStorage->read('training-load.html');

        self::assertStringContainsString('Training Load Analysis', $renderedWidget);
        self::assertStringContainsString('Planned sessions forecast', $trainingLoadModal);
        self::assertStringContainsString('Forecast confidence', $trainingLoadModal);
        self::assertStringContainsString('Manual ride', $trainingLoadModal);
        self::assertStringContainsString('Projects the next 7 days using the sessions currently saved in your planner.', $trainingLoadModal);
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
