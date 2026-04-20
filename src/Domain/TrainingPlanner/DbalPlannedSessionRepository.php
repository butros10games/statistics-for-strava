<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalPlannedSessionRepository extends DbalRepository implements PlannedSessionRepository
{
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        private ?CurrentAppUser $currentAppUser = null,
    ) {
        parent::__construct($connection);
    }

    public function upsert(PlannedSession $plannedSession): void
    {
        $ownerUserId = $this->resolveOwnerUserId($plannedSession->getOwnerUserId());

        $sql = 'INSERT INTO PlannedSession (
                    plannedSessionId, ownerUserId, day, activityType, title, notes, targetLoad, targetDurationInSeconds,
                    targetIntensity, templateActivityId, workoutSteps, estimationSource, linkedActivityId, linkStatus,
                    createdAt, updatedAt
                ) VALUES (
                    :plannedSessionId, :ownerUserId, :day, :activityType, :title, :notes, :targetLoad, :targetDurationInSeconds,
                    :targetIntensity, :templateActivityId, :workoutSteps, :estimationSource, :linkedActivityId, :linkStatus,
                    :createdAt, :updatedAt
                )
                ON CONFLICT(`plannedSessionId`) DO UPDATE SET
                    ownerUserId = excluded.ownerUserId,
                    day = excluded.day,
                    activityType = excluded.activityType,
                    title = excluded.title,
                    notes = excluded.notes,
                    targetLoad = excluded.targetLoad,
                    targetDurationInSeconds = excluded.targetDurationInSeconds,
                    targetIntensity = excluded.targetIntensity,
                    templateActivityId = excluded.templateActivityId,
                    workoutSteps = excluded.workoutSteps,
                    estimationSource = excluded.estimationSource,
                    linkedActivityId = excluded.linkedActivityId,
                    linkStatus = excluded.linkStatus,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'plannedSessionId' => (string) $plannedSession->getId(),
            'ownerUserId' => $ownerUserId?->__toString(),
            'day' => $plannedSession->getDay(),
            'activityType' => $plannedSession->getActivityType()->value,
            'title' => $plannedSession->getTitle(),
            'notes' => $plannedSession->getNotes(),
            'targetLoad' => $plannedSession->getTargetLoad(),
            'targetDurationInSeconds' => $plannedSession->getTargetDurationInSeconds(),
            'targetIntensity' => $plannedSession->getTargetIntensity()?->value,
            'templateActivityId' => $plannedSession->getTemplateActivityId()?->__toString(),
            'workoutSteps' => [] === $plannedSession->getWorkoutSteps() ? null : Json::encode($plannedSession->getWorkoutSteps()),
            'estimationSource' => $plannedSession->getEstimationSource()->value,
            'linkedActivityId' => $plannedSession->getLinkedActivityId()?->__toString(),
            'linkStatus' => $plannedSession->getLinkStatus()->value,
            'createdAt' => $plannedSession->getCreatedAt(),
            'updatedAt' => $plannedSession->getUpdatedAt(),
        ]);
    }

    public function delete(PlannedSessionId $plannedSessionId): void
    {
        $this->connection->delete('PlannedSession', [
            'plannedSessionId' => (string) $plannedSessionId,
        ]);
    }

    public function findById(PlannedSessionId $plannedSessionId, ?AppUserId $ownerUserId = null): ?PlannedSession
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->andWhere('plannedSessionId = :plannedSessionId')
            ->setParameter('plannedSessionId', (string) $plannedSessionId)
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByDateRange(DateRange $dateRange, ?AppUserId $ownerUserId = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->andWhere('day >= :from')
            ->andWhere('day <= :till')
            ->setParameter('from', $dateRange->getFrom())
            ->setParameter('till', $dateRange->getTill())
            ->orderBy('day', 'ASC')
            ->addOrderBy('createdAt', 'ASC');
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findByDay(SerializableDateTime $day, ?AppUserId $ownerUserId = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->andWhere('day = :day')
            ->setParameter('day', $day->setTime(0, 0))
            ->orderBy('createdAt', 'ASC');
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findEarliest(?AppUserId $ownerUserId = null): ?PlannedSession
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->orderBy('day', 'ASC')
            ->addOrderBy('createdAt', 'ASC')
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findLatest(?AppUserId $ownerUserId = null): ?PlannedSession
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
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
    private function hydrate(array $result): PlannedSession
    {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::fromString($result['plannedSessionId']),
            ownerUserId: null === ($result['ownerUserId'] ?? null) ? null : AppUserId::fromString((string) $result['ownerUserId']),
            day: SerializableDateTime::fromString($result['day']),
            activityType: ActivityType::from($result['activityType']),
            title: $result['title'],
            notes: $result['notes'],
            targetLoad: null === $result['targetLoad'] ? null : (float) $result['targetLoad'],
            targetDurationInSeconds: null === $result['targetDurationInSeconds'] ? null : (int) $result['targetDurationInSeconds'],
            targetIntensity: null === $result['targetIntensity'] ? null : PlannedSessionIntensity::from($result['targetIntensity']),
            templateActivityId: null === $result['templateActivityId'] ? null : ActivityId::fromString($result['templateActivityId']),
            workoutSteps: Json::decode((string) ($result['workoutSteps'] ?? '[]')),
            estimationSource: PlannedSessionEstimationSource::from($result['estimationSource']),
            linkedActivityId: null === $result['linkedActivityId'] ? null : ActivityId::fromString($result['linkedActivityId']),
            linkStatus: PlannedSessionLinkStatus::from($result['linkStatus']),
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
