<?php

declare(strict_types=1);

namespace App\Tests\Domain\Wellness;

use App\Domain\Wellness\DailyRecoveryCheckIn;
use App\Domain\Wellness\DailyRecoveryCheckInRepository;
use App\Domain\Wellness\DbalDailyRecoveryCheckInRepository;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;

final class DbalDailyRecoveryCheckInRepositoryTest extends ContainerTestCase
{
    private DailyRecoveryCheckInRepository $repository;

    public function testUpsertAndFindByDateRange(): void
    {
        $this->repository->upsert(DailyRecoveryCheckIn::create(
            day: SerializableDateTime::fromString('2026-04-06 06:00:00'),
            fatigue: 4,
            soreness: 3,
            stress: 2,
            motivation: 4,
            sleepQuality: 3,
            recordedAt: SerializableDateTime::fromString('2026-04-06 07:00:00'),
        ));

        $this->repository->upsert(DailyRecoveryCheckIn::create(
            day: SerializableDateTime::fromString('2026-04-06 09:00:00'),
            fatigue: 5,
            soreness: 4,
            stress: 3,
            motivation: 2,
            sleepQuality: 2,
            recordedAt: SerializableDateTime::fromString('2026-04-06 09:05:00'),
        ));

        $this->repository->upsert(DailyRecoveryCheckIn::create(
            day: SerializableDateTime::fromString('2026-04-07 06:30:00'),
            fatigue: 2,
            soreness: 2,
            stress: 2,
            motivation: 5,
            sleepQuality: 4,
            recordedAt: SerializableDateTime::fromString('2026-04-07 06:31:00'),
        ));

        $records = $this->repository->findByDateRange(DateRange::fromDates(
            from: SerializableDateTime::fromString('2026-04-06 00:00:00'),
            till: SerializableDateTime::fromString('2026-04-08 00:00:00'),
        ));

        self::assertCount(2, $records);
        self::assertSame(5, $records[0]->getFatigue());
        self::assertSame(2, $records[0]->getMotivation());
        self::assertSame('2026-04-07 00:00:00', $this->repository->findLatest()?->getDay()->format('Y-m-d H:i:s'));
        self::assertSame(4, $this->repository->findByDay(SerializableDateTime::fromString('2026-04-07 17:00:00'))?->getSleepQuality());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalDailyRecoveryCheckInRepository($this->getConnection());
    }
}