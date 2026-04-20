<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalTrainingBlockRepository extends DbalRepository implements TrainingBlockRepository
{
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        private ?CurrentAppUser $currentAppUser = null,
    ) {
        parent::__construct($connection);
    }

    public function upsert(TrainingBlock $trainingBlock): void
    {
        $ownerUserId = $this->resolveOwnerUserId($trainingBlock->getOwnerUserId());

        $sql = 'INSERT INTO TrainingBlock (
                    trainingBlockId, ownerUserId, startDay, endDay, targetRaceEventId, phase, title, focus, notes, createdAt, updatedAt
                ) VALUES (
                    :trainingBlockId, :ownerUserId, :startDay, :endDay, :targetRaceEventId, :phase, :title, :focus, :notes, :createdAt, :updatedAt
                )
                ON CONFLICT(`trainingBlockId`) DO UPDATE SET
                    ownerUserId = excluded.ownerUserId,
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
            'ownerUserId' => $ownerUserId?->__toString(),
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

    public function findById(TrainingBlockId $trainingBlockId, ?AppUserId $ownerUserId = null): ?TrainingBlock
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->andWhere('trainingBlockId = :trainingBlockId')
            ->setParameter('trainingBlockId', (string) $trainingBlockId)
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByDateRange(DateRange $dateRange, ?AppUserId $ownerUserId = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->andWhere('endDay >= :from')
            ->andWhere('startDay <= :till')
            ->setParameter('from', $dateRange->getFrom())
            ->setParameter('till', $dateRange->getTill())
            ->orderBy('startDay', 'ASC')
            ->addOrderBy('endDay', 'ASC');
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findCurrentAndUpcoming(SerializableDateTime $from, int $limit = 4, ?AppUserId $ownerUserId = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->andWhere('endDay >= :from')
            ->setParameter('from', $from->setTime(0, 0))
            ->orderBy('startDay', 'ASC')
            ->addOrderBy('endDay', 'ASC')
            ->setMaxResults($limit);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findEarliest(?AppUserId $ownerUserId = null): ?TrainingBlock
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->orderBy('startDay', 'ASC')
            ->addOrderBy('createdAt', 'ASC')
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findLatest(?AppUserId $ownerUserId = null): ?TrainingBlock
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingBlock')
            ->orderBy('endDay', 'DESC')
            ->addOrderBy('updatedAt', 'DESC')
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): TrainingBlock
    {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::fromString($result['trainingBlockId']),
            ownerUserId: null === ($result['ownerUserId'] ?? null) ? null : AppUserId::fromString((string) $result['ownerUserId']),
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

    private function applyOwnerScope(QueryBuilder $queryBuilder, ?AppUserId $ownerUserId): void
    {
        $ownerUserId = $this->resolveOwnerUserId($ownerUserId);
        if (null === $ownerUserId) {
            return;
        }

        $queryBuilder
            ->andWhere('ownerUserId = :ownerUserId')
            ->setParameter('ownerUserId', (string) $ownerUserId);
    }

    private function resolveOwnerUserId(?AppUserId $ownerUserId): ?AppUserId
    {
        return $ownerUserId ?? $this->currentAppUser?->getId();
    }
}
