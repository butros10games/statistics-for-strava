<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerExistingBlockSelector;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class RacePlannerExistingBlockSelectorTest extends TestCase
{
    public function testSelectReusableBlocksPrefersLinkedBlocksAndContiguousHistory(): void
    {
        $targetRace = $this->createTargetRace();
        $selector = new RacePlannerExistingBlockSelector();

        $selectedBlocks = $selector->selectReusableBlocks($targetRace, [
            $this->createBlock('2026-02-02', '2026-04-05', TrainingBlockPhase::BASE, $targetRace->getId()),
            $this->createBlock('2026-04-06', '2026-05-17', TrainingBlockPhase::BUILD, $targetRace->getId()),
            $this->createBlock('2026-05-18', '2026-06-07', TrainingBlockPhase::PEAK, null),
            $this->createBlock('2026-06-08', '2026-06-21', TrainingBlockPhase::TAPER, null),
        ]);

        self::assertCount(4, $selectedBlocks);
        self::assertSame('2026-02-02', $selectedBlocks[0]->getStartDay()->format('Y-m-d'));
        self::assertSame('2026-06-21', $selectedBlocks[3]->getEndDay()->format('Y-m-d'));
    }

    public function testSelectReusableBlocksFallsBackToContiguousSeasonWindowWithoutLinks(): void
    {
        $targetRace = $this->createTargetRace();
        $selector = new RacePlannerExistingBlockSelector();

        $selectedBlocks = $selector->selectReusableBlocks($targetRace, [
            $this->createBlock('2025-11-01', '2025-11-30', TrainingBlockPhase::BASE, null),
            $this->createBlock('2026-02-02', '2026-04-05', TrainingBlockPhase::BASE, null),
            $this->createBlock('2026-04-06', '2026-05-17', TrainingBlockPhase::BUILD, null),
            $this->createBlock('2026-05-18', '2026-06-07', TrainingBlockPhase::PEAK, null),
            $this->createBlock('2026-06-08', '2026-06-21', TrainingBlockPhase::TAPER, null),
        ]);

        self::assertCount(4, $selectedBlocks);
        self::assertSame('2026-02-02', $selectedBlocks[0]->getStartDay()->format('Y-m-d'));
        self::assertSame(TrainingBlockPhase::TAPER, $selectedBlocks[3]->getPhase());
    }

    public function testSelectReusableBlocksIncludesContiguousRecoveryButStopsBeforeNextSeason(): void
    {
        $targetRace = $this->createTargetRace();
        $selector = new RacePlannerExistingBlockSelector();

        $selectedBlocks = $selector->selectReusableBlocks(
            $targetRace,
            [
                $this->createBlock('2026-02-02', '2026-04-05', TrainingBlockPhase::BASE, $targetRace->getId()),
                $this->createBlock('2026-04-06', '2026-05-17', TrainingBlockPhase::BUILD, $targetRace->getId()),
                $this->createBlock('2026-05-18', '2026-06-07', TrainingBlockPhase::PEAK, $targetRace->getId()),
                $this->createBlock('2026-06-08', '2026-06-21', TrainingBlockPhase::TAPER, $targetRace->getId()),
                $this->createBlock('2026-06-22', '2026-07-05', TrainingBlockPhase::RECOVERY, $targetRace->getId()),
                $this->createBlock('2026-07-06', '2026-07-19', TrainingBlockPhase::BASE, null),
            ],
            SerializableDateTime::fromString('2026-07-05 23:59:59'),
        );

        self::assertCount(5, $selectedBlocks);
        self::assertSame(TrainingBlockPhase::RECOVERY, $selectedBlocks[4]->getPhase());
        self::assertSame('2026-07-05', $selectedBlocks[4]->getEndDay()->format('Y-m-d'));
    }

    private function createTargetRace(): RaceEvent
    {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString('2026-06-21 00:00:00'),
            type: RaceEventType::HALF_DISTANCE_TRIATHLON,
            title: 'A-race',
            location: 'Hoorn',
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 19_800,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
    }

    private function createBlock(
        string $startDay,
        string $endDay,
        TrainingBlockPhase $phase,
        ?\App\Domain\TrainingPlanner\RaceEventId $targetRaceEventId,
    ): TrainingBlock {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString(sprintf('%s 00:00:00', $startDay)),
            endDay: SerializableDateTime::fromString(sprintf('%s 00:00:00', $endDay)),
            targetRaceEventId: $targetRaceEventId,
            phase: $phase,
            title: ucfirst($phase->value),
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
    }
}