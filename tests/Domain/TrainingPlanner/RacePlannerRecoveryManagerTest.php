<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerRecoveryManager;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;

final class RacePlannerRecoveryManagerTest extends ContainerTestCase
{
    private RacePlannerRecoveryManager $racePlannerRecoveryManager;
    private RaceEventRepository $raceEventRepository;
    private TrainingBlockRepository $trainingBlockRepository;
    private PlannedSessionRepository $plannedSessionRepository;

    public function testSavePersistsMissingRecoveryBlockAndSessionsOnlyOnce(): void
    {
        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'West Friesland',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->raceEventRepository->upsert($targetRace);

        $preview = $this->racePlannerRecoveryManager->save(
            $targetRace,
            SerializableDateTime::fromString('2026-04-14 08:00:00'),
        );

        self::assertGreaterThan(0, $preview->getMissingRecoveryBlockCount());
        self::assertGreaterThan(0, $preview->getMissingRecoverySessionCount());

        $recoveryBlocks = array_values(array_filter(
            $this->trainingBlockRepository->findByDateRange(DateRange::fromDates(
                SerializableDateTime::fromString('2026-06-22 00:00:00'),
                SerializableDateTime::fromString('2026-07-20 23:59:59'),
            )),
            static fn ($block): bool => TrainingBlockPhase::RECOVERY === $block->getPhase(),
        ));
        $recoverySessions = $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-06-22 00:00:00'),
            SerializableDateTime::fromString('2026-07-20 23:59:59'),
        ));

        self::assertCount(1, $recoveryBlocks);
        self::assertSame('2026-06-22', $recoveryBlocks[0]->getStartDay()->format('Y-m-d'));
        self::assertNotEmpty($recoverySessions);

        $secondSave = $this->racePlannerRecoveryManager->save(
            $targetRace,
            SerializableDateTime::fromString('2026-04-14 08:00:00'),
        );

        self::assertSame(0, $secondSave->getMissingRecoveryBlockCount());
        self::assertSame(0, $secondSave->getMissingRecoverySessionCount());
        self::assertCount(1, array_values(array_filter(
            $this->trainingBlockRepository->findByDateRange(DateRange::fromDates(
                SerializableDateTime::fromString('2026-06-22 00:00:00'),
                SerializableDateTime::fromString('2026-07-20 23:59:59'),
            )),
            static fn ($block): bool => TrainingBlockPhase::RECOVERY === $block->getPhase(),
        )));
        self::assertCount(count($recoverySessions), $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
            SerializableDateTime::fromString('2026-06-22 00:00:00'),
            SerializableDateTime::fromString('2026-07-20 23:59:59'),
        )));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->racePlannerRecoveryManager = $this->getContainer()->get(RacePlannerRecoveryManager::class);
        $this->raceEventRepository = $this->getContainer()->get(RaceEventRepository::class);
        $this->trainingBlockRepository = $this->getContainer()->get(TrainingBlockRepository::class);
        $this->plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);
    }
}