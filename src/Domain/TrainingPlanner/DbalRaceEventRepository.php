<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalRaceEventRepository extends DbalRepository implements RaceEventRepository
{
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        private ?CurrentAppUser $currentAppUser = null,
    ) {
        parent::__construct($connection);
    }

    public function upsert(RaceEvent $raceEvent): void
    {
        $ownerUserId = $this->resolveOwnerUserId($raceEvent->getOwnerUserId());

        $sql = 'INSERT INTO RaceEvent (
                    raceEventId, ownerUserId, day, type, family, profile, title, location, notes, priority, targetFinishTimeInSeconds, createdAt, updatedAt
                ) VALUES (
                    :raceEventId, :ownerUserId, :day, :type, :family, :profile, :title, :location, :notes, :priority, :targetFinishTimeInSeconds, :createdAt, :updatedAt
                )
                ON CONFLICT(`raceEventId`) DO UPDATE SET
                    ownerUserId = excluded.ownerUserId,
                    day = excluded.day,
                    type = excluded.type,
                    family = excluded.family,
                    profile = excluded.profile,
                    title = excluded.title,
                    location = excluded.location,
                    notes = excluded.notes,
                    priority = excluded.priority,
                    targetFinishTimeInSeconds = excluded.targetFinishTimeInSeconds,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'raceEventId' => (string) $raceEvent->getId(),
            'ownerUserId' => $ownerUserId?->__toString(),
            'day' => $raceEvent->getDay(),
            'type' => $raceEvent->getType()->value,
            'family' => $raceEvent->getFamily()->value,
            'profile' => $raceEvent->getProfile()->value,
            'title' => $raceEvent->getTitle(),
            'location' => $raceEvent->getLocation(),
            'notes' => $raceEvent->getNotes(),
            'priority' => $raceEvent->getPriority()->value,
            'targetFinishTimeInSeconds' => $raceEvent->getTargetFinishTimeInSeconds(),
            'createdAt' => $raceEvent->getCreatedAt(),
            'updatedAt' => $raceEvent->getUpdatedAt(),
        ]);
    }

    public function delete(RaceEventId $raceEventId): void
    {
        $this->connection->delete('RaceEvent', [
            'raceEventId' => (string) $raceEventId,
        ]);
    }

    public function findById(RaceEventId $raceEventId, ?AppUserId $ownerUserId = null): ?RaceEvent
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('RaceEvent')
            ->andWhere('raceEventId = :raceEventId')
            ->setParameter('raceEventId', (string) $raceEventId)
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByDateRange(DateRange $dateRange, ?AppUserId $ownerUserId = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('RaceEvent')
            ->andWhere('day >= :from')
            ->andWhere('day <= :till')
            ->setParameter('from', $dateRange->getFrom())
            ->setParameter('till', $dateRange->getTill())
            ->orderBy('day', 'ASC')
            ->addOrderBy('priority', 'ASC');
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findUpcoming(SerializableDateTime $from, int $limit = 4, ?AppUserId $ownerUserId = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('RaceEvent')
            ->andWhere('day >= :from')
            ->setParameter('from', $from->setTime(0, 0))
            ->orderBy('day', 'ASC')
            ->addOrderBy('priority', 'ASC')
            ->setMaxResults($limit);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findEarliest(?AppUserId $ownerUserId = null): ?RaceEvent
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('RaceEvent')
            ->orderBy('day', 'ASC')
            ->addOrderBy('createdAt', 'ASC')
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findLatest(?AppUserId $ownerUserId = null): ?RaceEvent
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('RaceEvent')
            ->orderBy('day', 'DESC')
            ->addOrderBy('updatedAt', 'DESC')
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): RaceEvent
    {
        $profileValue = isset($result['profile']) && is_string($result['profile']) && '' !== $result['profile']
            ? $result['profile']
            : null;

        if (null !== $profileValue) {
            $profile = RaceEventProfile::from($profileValue);
            $familyValue = isset($result['family']) && is_string($result['family']) && '' !== $result['family']
                ? $result['family']
                : $profile->getFamily()->value;

            return RaceEvent::createWithClassification(
                raceEventId: RaceEventId::fromString($result['raceEventId']),
                ownerUserId: null === ($result['ownerUserId'] ?? null) ? null : AppUserId::fromString((string) $result['ownerUserId']),
                day: SerializableDateTime::fromString($result['day']),
                family: RaceEventFamily::from($familyValue),
                profile: $profile,
                title: $result['title'],
                location: $result['location'],
                notes: $result['notes'],
                priority: RaceEventPriority::from($result['priority']),
                targetFinishTimeInSeconds: null === $result['targetFinishTimeInSeconds'] ? null : (int) $result['targetFinishTimeInSeconds'],
                createdAt: SerializableDateTime::fromString($result['createdAt']),
                updatedAt: SerializableDateTime::fromString($result['updatedAt']),
            );
        }

        return RaceEvent::create(
            raceEventId: RaceEventId::fromString($result['raceEventId']),
            ownerUserId: null === ($result['ownerUserId'] ?? null) ? null : AppUserId::fromString((string) $result['ownerUserId']),
            day: SerializableDateTime::fromString($result['day']),
            type: RaceEventType::from($result['type']),
            title: $result['title'],
            location: $result['location'],
            notes: $result['notes'],
            priority: RaceEventPriority::from($result['priority']),
            targetFinishTimeInSeconds: null === $result['targetFinishTimeInSeconds'] ? null : (int) $result['targetFinishTimeInSeconds'],
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
