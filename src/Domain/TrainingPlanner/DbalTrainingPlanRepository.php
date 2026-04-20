<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalTrainingPlanRepository extends DbalRepository implements TrainingPlanRepository
{
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        private ?CurrentAppUser $currentAppUser = null,
    ) {
        parent::__construct($connection);
    }

    public function upsert(TrainingPlan $trainingPlan): void
    {
        $ownerUserId = $this->resolveOwnerUserId($trainingPlan->getOwnerUserId());

        $sql = 'INSERT INTO TrainingPlan (
                    trainingPlanId, ownerUserId, type, startDay, endDay, targetRaceEventId, title, notes, discipline, sportSchedule, performanceMetrics, targetRaceProfile, trainingFocus, trainingBlockStyle, runningWorkoutTargetMode, runHillSessionsEnabled, visibility, createdAt, updatedAt
                ) VALUES (
                    :trainingPlanId, :ownerUserId, :type, :startDay, :endDay, :targetRaceEventId, :title, :notes, :discipline, :sportSchedule, :performanceMetrics, :targetRaceProfile, :trainingFocus, :trainingBlockStyle, :runningWorkoutTargetMode, :runHillSessionsEnabled, :visibility, :createdAt, :updatedAt
                )
                ON CONFLICT(`trainingPlanId`) DO UPDATE SET
                    ownerUserId = excluded.ownerUserId,
                    type = excluded.type,
                    startDay = excluded.startDay,
                    endDay = excluded.endDay,
                    targetRaceEventId = excluded.targetRaceEventId,
                    title = excluded.title,
                    notes = excluded.notes,
                    discipline = excluded.discipline,
                    sportSchedule = excluded.sportSchedule,
                    performanceMetrics = excluded.performanceMetrics,
                    targetRaceProfile = excluded.targetRaceProfile,
                    trainingFocus = excluded.trainingFocus,
                    trainingBlockStyle = excluded.trainingBlockStyle,
                    runningWorkoutTargetMode = excluded.runningWorkoutTargetMode,
                    runHillSessionsEnabled = excluded.runHillSessionsEnabled,
                    visibility = excluded.visibility,
                    createdAt = excluded.createdAt,
                    updatedAt = excluded.updatedAt';

        $this->connection->executeStatement($sql, [
            'trainingPlanId' => (string) $trainingPlan->getId(),
            'ownerUserId' => $ownerUserId?->__toString(),
            'type' => $trainingPlan->getType()->value,
            'startDay' => $trainingPlan->getStartDay(),
            'endDay' => $trainingPlan->getEndDay(),
            'targetRaceEventId' => $trainingPlan->getTargetRaceEventId()?->__toString(),
            'title' => $trainingPlan->getTitle(),
            'notes' => $trainingPlan->getNotes(),
            'discipline' => $trainingPlan->getDiscipline()?->value,
            'sportSchedule' => null !== $trainingPlan->getSportSchedule() ? json_encode($trainingPlan->getSportSchedule()) : null,
            'performanceMetrics' => null !== $trainingPlan->getPerformanceMetrics() ? json_encode($trainingPlan->getPerformanceMetrics()) : null,
            'targetRaceProfile' => $trainingPlan->getTargetRaceProfile()?->value,
            'trainingFocus' => $trainingPlan->getTrainingFocus()?->value,
            'trainingBlockStyle' => $trainingPlan->getTrainingBlockStyle()?->value,
            'runningWorkoutTargetMode' => $trainingPlan->getRunningWorkoutTargetMode()?->value,
            'runHillSessionsEnabled' => $trainingPlan->isRunHillSessionsEnabled(),
            'visibility' => $trainingPlan->getVisibility()->value,
            'createdAt' => $trainingPlan->getCreatedAt(),
            'updatedAt' => $trainingPlan->getUpdatedAt(),
        ]);
    }

    public function delete(TrainingPlanId $trainingPlanId): void
    {
        $this->connection->delete('TrainingPlan', [
            'trainingPlanId' => (string) $trainingPlanId,
        ]);
    }

    public function findById(TrainingPlanId $trainingPlanId, ?AppUserId $ownerUserId = null): ?TrainingPlan
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingPlan')
            ->andWhere('trainingPlanId = :trainingPlanId')
            ->setParameter('trainingPlanId', (string) $trainingPlanId)
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findByTargetRaceEventId(RaceEventId $targetRaceEventId, ?AppUserId $ownerUserId = null): ?TrainingPlan
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingPlan')
            ->andWhere('targetRaceEventId = :targetRaceEventId')
            ->setParameter('targetRaceEventId', (string) $targetRaceEventId)
            ->orderBy('updatedAt', 'DESC')
            ->addOrderBy('startDay', 'ASC')
            ->setMaxResults(1);
        $this->applyOwnerScope($queryBuilder, $ownerUserId);
        $result = $queryBuilder->executeQuery()->fetchAssociative();

        return false === $result ? null : $this->hydrate($result);
    }

    public function findAll(?AppUserId $ownerUserId = null): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingPlan')
            ->orderBy('startDay', 'ASC')
            ->addOrderBy('endDay', 'ASC')
            ->addOrderBy('createdAt', 'ASC');
        $this->applyOwnerScope($queryBuilder, $ownerUserId);

        return array_map(
            $this->hydrate(...),
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findLatest(?AppUserId $ownerUserId = null): ?TrainingPlan
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('TrainingPlan')
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
    private function hydrate(array $result): TrainingPlan
    {
        return TrainingPlan::create(
            trainingPlanId: TrainingPlanId::fromString($result['trainingPlanId']),
            ownerUserId: null === ($result['ownerUserId'] ?? null) ? null : AppUserId::fromString((string) $result['ownerUserId']),
            type: TrainingPlanType::from($result['type']),
            startDay: SerializableDateTime::fromString($result['startDay']),
            endDay: SerializableDateTime::fromString($result['endDay']),
            targetRaceEventId: null === $result['targetRaceEventId'] ? null : RaceEventId::fromString((string) $result['targetRaceEventId']),
            title: $result['title'],
            notes: $result['notes'],
            discipline: isset($result['discipline']) ? TrainingPlanDiscipline::from($result['discipline']) : null,
            sportSchedule: isset($result['sportSchedule']) ? json_decode((string) $result['sportSchedule'], true) : null,
            performanceMetrics: isset($result['performanceMetrics']) ? json_decode((string) $result['performanceMetrics'], true) : null,
            targetRaceProfile: isset($result['targetRaceProfile']) ? RaceEventProfile::from($result['targetRaceProfile']) : null,
            trainingFocus: isset($result['trainingFocus']) && null !== $result['trainingFocus'] ? TrainingFocus::tryFrom($result['trainingFocus']) : null,
            trainingBlockStyle: isset($result['trainingBlockStyle']) && null !== $result['trainingBlockStyle'] ? TrainingBlockStyle::tryFrom($result['trainingBlockStyle']) : null,
            runningWorkoutTargetMode: isset($result['runningWorkoutTargetMode']) && null !== $result['runningWorkoutTargetMode'] ? RunningWorkoutTargetMode::tryFrom($result['runningWorkoutTargetMode']) : null,
            runHillSessionsEnabled: (bool) ($result['runHillSessionsEnabled'] ?? false),
            visibility: isset($result['visibility']) && is_string($result['visibility']) ? TrainingPlanVisibility::from($result['visibility']) : TrainingPlanVisibility::FRIENDS,
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
