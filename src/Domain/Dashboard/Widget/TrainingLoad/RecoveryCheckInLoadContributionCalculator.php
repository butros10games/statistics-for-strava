<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final class RecoveryCheckInLoadContributionCalculator
{
    /**
     * @param list<array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}> $records
     *
     * @return array<string, int>
     */
    public function calculateForRecords(array $records): array
    {
        $contributions = [];

        foreach ($records as $record) {
            $contributions[$record['day']] = $this->calculateForRecord($record);
        }

        return $contributions;
    }

    /**
     * @param array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int} $record
     */
    public function calculateForRecord(array $record): int
    {
        $load = 0.0;
        $load += ($record['fatigue'] - 1) * 1.5;
        $load += ($record['soreness'] - 1) * 1.0;
        $load += ($record['stress'] - 1) * 1.25;
        $load += (5 - $record['motivation']) * 1.0;
        $load += (5 - $record['sleepQuality']) * 1.0;

        return (int) round(min($load, 12.0));
    }
}