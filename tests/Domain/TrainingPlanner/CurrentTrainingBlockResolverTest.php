<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\TrainingPlanner\CurrentTrainingBlockResolver;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class CurrentTrainingBlockResolverTest extends TestCase
{
    private CurrentTrainingBlockResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CurrentTrainingBlockResolver();
    }

    public function testFindCurrentReturnsMatchingTrainingBlock(): void
    {
        $currentBlock = $this->createTrainingBlock(
            startDay: '2023-10-16 00:00:00',
            endDay: '2023-10-22 00:00:00',
            title: 'Current block',
        );

        $resolved = $this->resolver->findCurrent([
            $this->createTrainingBlock(
                startDay: '2023-10-02 00:00:00',
                endDay: '2023-10-08 00:00:00',
                title: 'Previous block',
            ),
            $currentBlock,
        ], SerializableDateTime::fromString('2023-10-17 16:15:04'));

        self::assertSame($currentBlock, $resolved);
    }

    public function testFindCurrentReturnsNullWhenNoBlockContainsReferenceDate(): void
    {
        $resolved = $this->resolver->findCurrent([
            $this->createTrainingBlock(
                startDay: '2023-10-02 00:00:00',
                endDay: '2023-10-08 00:00:00',
                title: 'Previous block',
            ),
        ], SerializableDateTime::fromString('2023-10-17 16:15:04'));

        self::assertNull($resolved);
    }

    private function createTrainingBlock(string $startDay, string $endDay, string $title): TrainingBlock
    {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString($startDay),
            endDay: SerializableDateTime::fromString($endDay),
            targetRaceEventId: null,
            phase: TrainingBlockPhase::BUILD,
            title: $title,
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }
}