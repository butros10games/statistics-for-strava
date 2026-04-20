<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;

interface TrainingSessionRepository
{
    public function upsert(TrainingSession $trainingSession): void;

    public function deleteById(TrainingSessionId $trainingSessionId): void;

    public function findById(TrainingSessionId $trainingSessionId): ?TrainingSession;

    public function findBySourcePlannedSessionId(PlannedSessionId $plannedSessionId): ?TrainingSession;

    /**
     * @return list<TrainingSession>
     */
    public function findDuplicatesOf(TrainingSession $trainingSession, ?TrainingSessionId $excludeTrainingSessionId = null): array;

    /**
     * @return list<TrainingSession>
     */
    public function findRecommended(ActivityType $activityType, int $limit = 12, ?TrainingSessionRecommendationCriteria $criteria = null): array;
}
