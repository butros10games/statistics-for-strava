<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface TrainingBlockRepository
{
    public function upsert(TrainingBlock $trainingBlock): void;

    public function delete(TrainingBlockId $trainingBlockId): void;

    public function findById(TrainingBlockId $trainingBlockId, ?AppUserId $ownerUserId = null): ?TrainingBlock;

    /**
     * @return list<TrainingBlock>
     */
    public function findByDateRange(DateRange $dateRange, ?AppUserId $ownerUserId = null): array;

    /**
     * @return list<TrainingBlock>
     */
    public function findCurrentAndUpcoming(SerializableDateTime $from, int $limit = 4, ?AppUserId $ownerUserId = null): array;

    public function findEarliest(?AppUserId $ownerUserId = null): ?TrainingBlock;

    public function findLatest(?AppUserId $ownerUserId = null): ?TrainingBlock;
}
