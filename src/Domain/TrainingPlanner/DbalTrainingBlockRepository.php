<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalTrainingBlockRepository extends DbalRepository implements TrainingBlockRepository
{
    public function upsert(TrainingBlock $trainingBlock): void
    {
        $sql = 'INSERT INTO TrainingBlock (
                    trainingBlockId, startDay, endDay, targetRaceEventId, phase, title, focus, notes, createdAt, updatedAt
                ) VALUES (
                    :trainingBlockId, :startDay, :endDay, :targetRaceEventId, :phase, :title, :focus, :notes, :createdAt, :updatedAt
                )
                ON CONFLICT(`trainingBlockId`) DO UPDATE SET
                    startDay = excluded.startDay,
                    endDay = excluded.endDay,
                    targetRaceEventId = excluded.targetRaceEventId,
                    phase = excluded.phase,
                    title = excluded.title,
                    focus = excluded.focus,
                    notes = excluded.notes,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'trainingBlockId' => (string) $trainingBlock->getId(),
            'startDay' => $trainingBlock->getStartDay(),
            'endDay' => $trainingBlock->getEndDay(),
            'targetRaceEventId' => $trainingBlock->getTargetRaceEventId()?->__toString(),
            'phase' => $trainingBlock->getPhase()->value,
            'title' => $trainingBlock->getTitle(),
            'focus' => $trainingBlock->getFocus(),
            'notes' => $trainingBlock->getNotes(),
            'createdAt' => $trainingBlock->getCreatedAt(),
            'updatedAt' => $trainingBlock->getUpdatedAt(),
        ]);
    }

    public function delete(TrainingBlockId $trainingBlockId): void
    {
        $this->connection->delete('TrainingBlock', [
            'trainingBlockId' => (string) $trainingBlockId,
        ]);
    }

    public function findById(TrainingBlockId $trainingBlockId): ?TrainingBlock
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->andWhere('trainingBlockId = :trainingBlockId')
            ->setParameter('trainingBlockId', (string) $trainingBlockId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByDateRange(DateRange $dateRange): array
    {
        return array_map(
            $this->hydrate(...),
            $this->connection->createQueryBuilder()
                ->select('*')
                ->from('TrainingBlock')
                ->andWhere('endDay >= :from')
                ->andWhere('startDay <= :till')
                ->setParameter('from', $dateRange->getFrom())
                ->setParameter('till', $dateRange->getTill())
                ->orderBy('startDay', 'ASC')
                ->addOrderBy('endDay', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    public function findCurrentAndUpcoming(SerializableDateTime $from, int $limit = 4): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_map(
            $this->hydrate(...),
            $this->connection->createQueryBuilder()
                ->select('*')
                ->from('TrainingBlock')
                ->andWhere('endDay >= :from')
                ->setParameter('from', $from->setTime(0, 0))
                ->orderBy('startDay', 'ASC')
                ->addOrderBy('endDay', 'ASC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    public function findEarliest(): ?TrainingBlock
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->orderBy('startDay', 'ASC')
            ->addOrderBy('createdAt', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findLatest(): ?TrainingBlock
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->orderBy('endDay', 'DESC')
            ->addOrderBy('updatedAt', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): TrainingBlock
    {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::fromString($result['trainingBlockId']),
            startDay: SerializableDateTime::fromString($result['startDay']),
            endDay: SerializableDateTime::fromString($result['endDay']),
            targetRaceEventId: null === $result['targetRaceEventId'] ? null : RaceEventId::fromString((string) $result['targetRaceEventId']),
            phase: TrainingBlockPhase::from($result['phase']),
            title: $result['title'],
            focus: $result['focus'],
            notes: $result['notes'],
            createdAt: SerializableDateTime::fromString($result['createdAt']),
            updatedAt: SerializableDateTime::fromString($result['updatedAt']),
        );
    }
}
