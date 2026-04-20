<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;

interface TrainingPlanRepository
{
    public function upsert(TrainingPlan $trainingPlan): void;

    public function delete(TrainingPlanId $trainingPlanId): void;

    public function findById(TrainingPlanId $trainingPlanId, ?AppUserId $ownerUserId = null): ?TrainingPlan;

    public function findByTargetRaceEventId(RaceEventId $targetRaceEventId, ?AppUserId $ownerUserId = null): ?TrainingPlan;

    /**
     * @return list<TrainingPlan>
     */
    public function findAll(?AppUserId $ownerUserId = null): array;

    public function findLatest(?AppUserId $ownerUserId = null): ?TrainingPlan;
}
