<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface RaceEventRepository
{
    public function upsert(RaceEvent $raceEvent): void;

    public function delete(RaceEventId $raceEventId): void;

    public function findById(RaceEventId $raceEventId, ?AppUserId $ownerUserId = null): ?RaceEvent;

    /**
     * @return list<RaceEvent>
     */
    public function findByDateRange(DateRange $dateRange, ?AppUserId $ownerUserId = null): array;

    /**
     * @return list<RaceEvent>
     */
    public function findUpcoming(SerializableDateTime $from, int $limit = 4, ?AppUserId $ownerUserId = null): array;

    public function findEarliest(?AppUserId $ownerUserId = null): ?RaceEvent;

    public function findLatest(?AppUserId $ownerUserId = null): ?RaceEvent;
}
