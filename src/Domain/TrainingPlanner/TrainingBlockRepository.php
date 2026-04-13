<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface TrainingBlockRepository
{
    public function upsert(TrainingBlock $trainingBlock): void;

    public function delete(TrainingBlockId $trainingBlockId): void;

    public function findById(TrainingBlockId $trainingBlockId): ?TrainingBlock;

    /**
     * @return list<TrainingBlock>
     */
    public function findByDateRange(DateRange $dateRange): array;

    /**
     * @return list<TrainingBlock>
     */
    public function findCurrentAndUpcoming(SerializableDateTime $from, int $limit = 4): array;

    public function findEarliest(): ?TrainingBlock;

    public function findLatest(): ?TrainingBlock;
}
