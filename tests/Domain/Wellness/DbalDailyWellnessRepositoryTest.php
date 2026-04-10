<?php

declare(strict_types=1);

namespace App\Tests\Domain\Wellness;

use App\Domain\Wellness\DailyWellness;
use App\Domain\Wellness\DailyWellnessRepository;
use App\Domain\Wellness\DbalDailyWellnessRepository;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Spatie\Snapshots\MatchesSnapshots;

final class DbalDailyWellnessRepositoryTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private DailyWellnessRepository $repository;

    public function testUpsertAndFindByDateRange(): void
    {
        $this->repository->upsert(DailyWellness::create(
            day: SerializableDateTime::fromString('2026-04-01 06:30:00'),
            source: WellnessSource::GARMIN,
            stepsCount: 9000,
            sleepDurationInSeconds: 28000,
            sleepScore: 79,
            hrv: 52.4,
            payload: ['origin' => 'first-pass'],
            importedAt: SerializableDateTime::fromString('2026-04-07 09:00:00'),
        ));

        $this->repository->upsert(DailyWellness::create(
            day: SerializableDateTime::fromString('2026-04-01 23:59:59'),
            source: WellnessSource::GARMIN,
            stepsCount: 12345,
            sleepDurationInSeconds: 27900,
            sleepScore: 81,
            hrv: 54.7,
            payload: ['origin' => 'updated'],
            importedAt: SerializableDateTime::fromString('2026-04-07 10:00:00'),
        ));

        $this->repository->upsert(DailyWellness::create(
            day: SerializableDateTime::fromString('2026-04-02 06:30:00'),
            source: WellnessSource::GARMIN,
            stepsCount: 9876,
            sleepDurationInSeconds: 25200,
            sleepScore: 75,
            hrv: 49.2,
            payload: ['origin' => 'second-day'],
            importedAt: SerializableDateTime::fromString('2026-04-07 10:00:00'),
        ));

        $this->assertSame(
            '2026-04-02 00:00:00',
            $this->repository->findMostRecentDayForSource(WellnessSource::GARMIN)?->format('Y-m-d H:i:s')
        );

        $this->assertMatchesJsonSnapshot(Json::encode($this->repository->findByDateRange(
            DateRange::fromDates(
                from: SerializableDateTime::fromString('2026-04-01 00:00:00'),
                till: SerializableDateTime::fromString('2026-04-03 00:00:00'),
            ),
            WellnessSource::GARMIN,
        )));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalDailyWellnessRepository($this->getConnection());
    }
}