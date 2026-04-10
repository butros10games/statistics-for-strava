<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

interface DailyRecoveryCheckInRepository
{
    public function upsert(DailyRecoveryCheckIn $dailyRecoveryCheckIn): void;

    /**
     * @return DailyRecoveryCheckIn[]
     */
    public function findByDateRange(DateRange $dateRange): array;

    public function findLatest(): ?DailyRecoveryCheckIn;

    public function findByDay(SerializableDateTime $day): ?DailyRecoveryCheckIn;
}