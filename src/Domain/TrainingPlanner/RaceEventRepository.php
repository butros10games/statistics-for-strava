<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface RaceEventRepository
{
    public function upsert(RaceEvent $raceEvent): void;

    public function delete(RaceEventId $raceEventId): void;

    public function findById(RaceEventId $raceEventId): ?RaceEvent;

    /**
     * @return list<RaceEvent>
     */
    public function findByDateRange(DateRange $dateRange): array;

    /**
     * @return list<RaceEvent>
     */
    public function findUpcoming(SerializableDateTime $from, int $limit = 4): array;

    public function findEarliest(): ?RaceEvent;

    public function findLatest(): ?RaceEvent;
}
