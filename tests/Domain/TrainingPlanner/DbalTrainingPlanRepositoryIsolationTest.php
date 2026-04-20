<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Domain\TrainingPlanner\DbalTrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Tests\ContainerTestCase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class DbalTrainingPlanRepositoryIsolationTest extends ContainerTestCase
{
    public function testFindAllCanBeScopedToOwner(): void
    {
        $repository = new DbalTrainingPlanRepository($this->getConnection());
        $ownerA = AppUserId::random();
        $ownerB = AppUserId::random();

        $repository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            ownerUserId: $ownerA,
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-05-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-05-31 00:00:00'),
            targetRaceEventId: null,
            title: 'Owner A',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-01 08:00:00'),
        ));
        $repository->upsert(TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            ownerUserId: $ownerB,
            type: TrainingPlanType::TRAINING,
            startDay: SerializableDateTime::fromString('2026-06-01 00:00:00'),
            endDay: SerializableDateTime::fromString('2026-06-30 00:00:00'),
            targetRaceEventId: null,
            title: 'Owner B',
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-04-02 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-02 08:00:00'),
        ));

        self::assertCount(1, $repository->findAll($ownerA));
        self::assertSame('Owner A', $repository->findAll($ownerA)[0]->getTitle());
        self::assertCount(1, $repository->findAll($ownerB));
        self::assertSame('Owner B', $repository->findAll($ownerB)[0]->getTitle());
    }
}
