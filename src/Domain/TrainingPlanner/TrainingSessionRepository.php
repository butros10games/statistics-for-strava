<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\Auth\AppUserId;

interface TrainingSessionRepository
{
    public function upsert(TrainingSession $trainingSession): void;

    public function deleteById(TrainingSessionId $trainingSessionId, ?AppUserId $ownerUserId = null): void;

    public function findById(TrainingSessionId $trainingSessionId, ?AppUserId $ownerUserId = null): ?TrainingSession;

    public function findBySourcePlannedSessionId(PlannedSessionId $plannedSessionId, ?AppUserId $ownerUserId = null): ?TrainingSession;

    /**
     * @return list<TrainingSession>
     */
    public function findDuplicatesOf(TrainingSession $trainingSession, ?TrainingSessionId $excludeTrainingSessionId = null, ?AppUserId $ownerUserId = null): array;

    /**
     * @return list<TrainingSession>
     */
    public function findRecommended(ActivityType $activityType, int $limit = 12, ?TrainingSessionRecommendationCriteria $criteria = null, ?AppUserId $ownerUserId = null): array;
}
