<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface PlannedSessionRepository
{
    public function upsert(PlannedSession $plannedSession): void;

    public function delete(PlannedSessionId $plannedSessionId): void;

    public function findById(PlannedSessionId $plannedSessionId): ?PlannedSession;

    /**
     * @return list<PlannedSession>
     */
    public function findByDateRange(DateRange $dateRange): array;

    /**
     * @return list<PlannedSession>
     */
    public function findByDay(SerializableDateTime $day): array;

    public function findEarliest(): ?PlannedSession;

    public function findLatest(): ?PlannedSession;
}
