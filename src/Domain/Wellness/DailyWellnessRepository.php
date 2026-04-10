<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface DailyWellnessRepository
{
    public function upsert(DailyWellness $dailyWellness): void;

    /**
     * @return DailyWellness[]
     */
    public function findByDateRange(DateRange $dateRange, ?WellnessSource $source = null): array;

    public function findMostRecentDayForSource(WellnessSource $source): ?SerializableDateTime;
}