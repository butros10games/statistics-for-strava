<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface PlannedSessionRepository
{
    public function upsert(PlannedSession $plannedSession): void;

    public function delete(PlannedSessionId $plannedSessionId): void;

    public function findById(PlannedSessionId $plannedSessionId, ?AppUserId $ownerUserId = null): ?PlannedSession;

    /**
     * @return list<PlannedSession>
     */
    public function findByDateRange(DateRange $dateRange, ?AppUserId $ownerUserId = null): array;

    /**
     * @return list<PlannedSession>
     */
    public function findByDay(SerializableDateTime $day, ?AppUserId $ownerUserId = null): array;

    public function findEarliest(?AppUserId $ownerUserId = null): ?PlannedSession;

    public function findLatest(?AppUserId $ownerUserId = null): ?PlannedSession;
}
