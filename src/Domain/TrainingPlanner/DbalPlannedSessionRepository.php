<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalPlannedSessionRepository extends DbalRepository implements PlannedSessionRepository
{
    public function upsert(PlannedSession $plannedSession): void
    {
        $sql = 'INSERT INTO PlannedSession (
                    plannedSessionId, day, activityType, title, notes, targetLoad, targetDurationInSeconds,
                    targetIntensity, templateActivityId, workoutSteps, estimationSource, linkedActivityId, linkStatus,
                    createdAt, updatedAt
                ) VALUES (
                    :plannedSessionId, :day, :activityType, :title, :notes, :targetLoad, :targetDurationInSeconds,
                    :targetIntensity, :templateActivityId, :workoutSteps, :estimationSource, :linkedActivityId, :linkStatus,
                    :createdAt, :updatedAt
                )
                ON CONFLICT(`plannedSessionId`) DO UPDATE SET
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

    public function findById(PlannedSessionId $plannedSessionId): ?PlannedSession
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->andWhere('plannedSessionId = :plannedSessionId')
            ->setParameter('plannedSessionId', (string) $plannedSessionId)
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
                ->from('PlannedSession')
                ->andWhere('day >= :from')
                ->andWhere('day <= :till')
                ->setParameter('from', $dateRange->getFrom())
                ->setParameter('till', $dateRange->getTill())
                ->orderBy('day', 'ASC')
                ->addOrderBy('createdAt', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    public function findByDay(SerializableDateTime $day): array
    {
        return array_map(
            $this->hydrate(...),
            $this->connection->createQueryBuilder()
                ->select('*')
                ->from('PlannedSession')
                ->andWhere('day = :day')
                ->setParameter('day', $day->setTime(0, 0))
                ->orderBy('createdAt', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    public function findEarliest(): ?PlannedSession
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->orderBy('day', 'ASC')
            ->addOrderBy('createdAt', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findLatest(): ?PlannedSession
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('PlannedSession')
            ->orderBy('day', 'DESC')
            ->addOrderBy('updatedAt', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): PlannedSession
    {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::fromString($result['plannedSessionId']),
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
}
