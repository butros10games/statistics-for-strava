<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalTrainingSessionRepository extends DbalRepository implements TrainingSessionRepository
{
    public function upsert(TrainingSession $trainingSession): void
    {
        $sql = 'INSERT INTO TrainingSession (
                    trainingSessionId, sourcePlannedSessionId, activityType, title, notes, targetLoad,
                    targetDurationInSeconds, targetIntensity, templateActivityId, workoutSteps,
                    estimationSource, sessionSource, sessionPhase, sessionObjective, lastPlannedOn, createdAt, updatedAt
                ) VALUES (
                    :trainingSessionId, :sourcePlannedSessionId, :activityType, :title, :notes, :targetLoad,
                    :targetDurationInSeconds, :targetIntensity, :templateActivityId, :workoutSteps,
                    :estimationSource, :sessionSource, :sessionPhase, :sessionObjective, :lastPlannedOn, :createdAt, :updatedAt
                )
                ON CONFLICT(`trainingSessionId`) DO UPDATE SET
                    sourcePlannedSessionId = excluded.sourcePlannedSessionId,
                    activityType = excluded.activityType,
                    title = excluded.title,
                    notes = excluded.notes,
                    targetLoad = excluded.targetLoad,
                    targetDurationInSeconds = excluded.targetDurationInSeconds,
                    targetIntensity = excluded.targetIntensity,
                    templateActivityId = excluded.templateActivityId,
                    workoutSteps = excluded.workoutSteps,
                    estimationSource = excluded.estimationSource,
                    sessionSource = excluded.sessionSource,
                    sessionPhase = excluded.sessionPhase,
                    sessionObjective = excluded.sessionObjective,
                    lastPlannedOn = excluded.lastPlannedOn,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'trainingSessionId' => (string) $trainingSession->getId(),
            'sourcePlannedSessionId' => $trainingSession->getSourcePlannedSessionId()?->__toString(),
            'activityType' => $trainingSession->getActivityType()->value,
            'title' => $trainingSession->getTitle(),
            'notes' => $trainingSession->getNotes(),
            'targetLoad' => $trainingSession->getTargetLoad(),
            'targetDurationInSeconds' => $trainingSession->getTargetDurationInSeconds(),
            'targetIntensity' => $trainingSession->getTargetIntensity()?->value,
            'templateActivityId' => $trainingSession->getTemplateActivityId()?->__toString(),
            'workoutSteps' => [] === $trainingSession->getWorkoutSteps() ? null : Json::encode($trainingSession->getWorkoutSteps()),
            'estimationSource' => $trainingSession->getEstimationSource()->value,
            'sessionSource' => $trainingSession->getSessionSource()->value,
            'sessionPhase' => $trainingSession->getSessionPhase()?->value,
            'sessionObjective' => $trainingSession->getSessionObjective()?->value,
            'lastPlannedOn' => $trainingSession->getLastPlannedOn(),
            'createdAt' => $trainingSession->getCreatedAt(),
            'updatedAt' => $trainingSession->getUpdatedAt(),
        ]);
    }

    public function deleteById(TrainingSessionId $trainingSessionId): void
    {
        $this->connection->createQueryBuilder()
            ->delete('TrainingSession')
            ->andWhere('trainingSessionId = :trainingSessionId')
            ->setParameter('trainingSessionId', (string) $trainingSessionId)
            ->executeStatement();
    }

    public function findById(TrainingSessionId $trainingSessionId): ?TrainingSession
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingSession')
            ->andWhere('trainingSessionId = :trainingSessionId')
            ->setParameter('trainingSessionId', (string) $trainingSessionId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findBySourcePlannedSessionId(PlannedSessionId $plannedSessionId): ?TrainingSession
    {
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingSession')
            ->andWhere('sourcePlannedSessionId = :sourcePlannedSessionId')
            ->setParameter('sourcePlannedSessionId', (string) $plannedSessionId)
            ->orderBy('updatedAt', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findDuplicatesOf(TrainingSession $trainingSession, ?TrainingSessionId $excludeTrainingSessionId = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingSession')
            ->orderBy('lastPlannedOn', 'DESC')
            ->addOrderBy('updatedAt', 'DESC')
            ->addOrderBy('createdAt', 'ASC');

        foreach ($trainingSession->getDeduplicationValues() as $column => $value) {
            $this->applyNullableEqualityFilter($queryBuilder, $column, $value);
        }

        if (null !== $excludeTrainingSessionId) {
            $queryBuilder
                ->andWhere('trainingSessionId != :excludeTrainingSessionId')
                ->setParameter('excludeTrainingSessionId', (string) $excludeTrainingSessionId);
        }

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findRecommended(ActivityType $activityType, int $limit = 12, ?TrainingSessionRecommendationCriteria $criteria = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingSession')
            ->andWhere('activityType = :activityType')
            ->setParameter('activityType', $activityType->value);

        if (null !== $criteria?->getSessionPhase()) {
            $queryBuilder
                ->andWhere('sessionPhase = :sessionPhase')
                ->setParameter('sessionPhase', $criteria->getSessionPhase()->value);
        }

        if (null !== $criteria?->getSessionObjective()) {
            $queryBuilder
                ->andWhere('sessionObjective = :sessionObjective')
                ->setParameter('sessionObjective', $criteria->getSessionObjective()->value);
        }

        if (null !== $criteria?->getSessionSource()) {
            $queryBuilder
                ->andWhere('sessionSource = :sessionSource')
                ->setParameter('sessionSource', $criteria->getSessionSource()->value);
        }

        if (null !== $criteria?->getTargetIntensity()) {
            $queryBuilder
                ->andWhere('targetIntensity = :targetIntensity')
                ->setParameter('targetIntensity', $criteria->getTargetIntensity()->value);
        }

        if (null !== $criteria?->getMinimumTargetLoad()) {
            $queryBuilder
                ->andWhere('targetLoad >= :minimumTargetLoad')
                ->setParameter('minimumTargetLoad', $criteria->getMinimumTargetLoad());
        }

        if (null !== $criteria?->getMaximumTargetLoad()) {
            $queryBuilder
                ->andWhere('targetLoad <= :maximumTargetLoad')
                ->setParameter('maximumTargetLoad', $criteria->getMaximumTargetLoad());
        }

        if (null !== $criteria?->getMinimumTargetDurationInSeconds()) {
            $queryBuilder
                ->andWhere('targetDurationInSeconds >= :minimumTargetDurationInSeconds')
                ->setParameter('minimumTargetDurationInSeconds', $criteria->getMinimumTargetDurationInSeconds());
        }

        if (null !== $criteria?->getMaximumTargetDurationInSeconds()) {
            $queryBuilder
                ->andWhere('targetDurationInSeconds <= :maximumTargetDurationInSeconds')
                ->setParameter('maximumTargetDurationInSeconds', $criteria->getMaximumTargetDurationInSeconds());
        }

        if (true === $criteria?->requiresWorkoutSteps()) {
            $queryBuilder->andWhere('workoutSteps IS NOT NULL');
        }

        if (false === $criteria?->requiresWorkoutSteps()) {
            $queryBuilder->andWhere('workoutSteps IS NULL');
        }

        return array_map(
            $this->hydrate(...),
            $queryBuilder
                ->orderBy('lastPlannedOn', 'DESC')
                ->addOrderBy('updatedAt', 'DESC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchAllAssociative(),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): TrainingSession
    {
        return TrainingSession::create(
            trainingSessionId: TrainingSessionId::fromString($result['trainingSessionId']),
            sourcePlannedSessionId: null === $result['sourcePlannedSessionId'] ? null : PlannedSessionId::fromString($result['sourcePlannedSessionId']),
            activityType: ActivityType::from($result['activityType']),
            title: $result['title'],
            notes: $result['notes'],
            targetLoad: null === $result['targetLoad'] ? null : (float) $result['targetLoad'],
            targetDurationInSeconds: null === $result['targetDurationInSeconds'] ? null : (int) $result['targetDurationInSeconds'],
            targetIntensity: null === $result['targetIntensity'] ? null : PlannedSessionIntensity::from($result['targetIntensity']),
            templateActivityId: null === $result['templateActivityId'] ? null : ActivityId::fromString($result['templateActivityId']),
            workoutSteps: Json::decode((string) ($result['workoutSteps'] ?? '[]')),
            estimationSource: PlannedSessionEstimationSource::from($result['estimationSource']),
            sessionSource: isset($result['sessionSource']) ? TrainingSessionSource::from($result['sessionSource']) : TrainingSessionSource::PLANNED_SESSION,
            sessionPhase: isset($result['sessionPhase']) && null !== $result['sessionPhase'] ? TrainingBlockPhase::from($result['sessionPhase']) : null,
            sessionObjective: isset($result['sessionObjective']) && null !== $result['sessionObjective'] ? TrainingSessionObjective::from($result['sessionObjective']) : null,
            lastPlannedOn: null === $result['lastPlannedOn'] ? null : SerializableDateTime::fromString($result['lastPlannedOn']),
            createdAt: SerializableDateTime::fromString($result['createdAt']),
            updatedAt: SerializableDateTime::fromString($result['updatedAt']),
        );
    }

    private function applyNullableEqualityFilter($queryBuilder, string $column, mixed $value): void
    {
        if (null === $value) {
            $queryBuilder->andWhere(sprintf('%s IS NULL', $column));

            return;
        }

        $parameterName = sprintf('dedupe_%s', $column);
        $queryBuilder
            ->andWhere(sprintf('%s = :%s', $column, $parameterName))
            ->setParameter($parameterName, $value);
    }
}
