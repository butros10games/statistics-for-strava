<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final class WellnessLoadContributionCalculator
{
    private const int BASELINE_WINDOW = 21;
    private const int MINIMUM_BASELINE_RECORDS = 3;
    private const float MAX_DAILY_WELLNESS_LOAD = 20.0;

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     *
     * @return array<string, int>
     */
    public function calculateForRecords(array $records): array
    {
        $contributions = [];
        $historicalRecords = [];

        foreach ($records as $record) {
            $contributions[$record['day']] = $this->calculateForRecord($record, $historicalRecords);
            $historicalRecords[] = $record;
        }

        return $contributions;
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $record
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $historicalRecords
     */
    public function calculateForRecord(array $record, array $historicalRecords = []): int
    {
        $load = 0.0;
        $load += $this->calculateStepsContribution($record, $historicalRecords);
        $load += $this->calculateSleepContribution($record, $historicalRecords);
        $load += $this->calculateSleepScoreContribution($record, $historicalRecords);
        $load += $this->calculateHrvContribution($record, $historicalRecords);

        return (int) round(min($load, self::MAX_DAILY_WELLNESS_LOAD));
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $record
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $historicalRecords
     */
    private function calculateStepsContribution(array $record, array $historicalRecords): float
    {
        if (null === $record['stepsCount']) {
            return 0.0;
        }

        $threshold = 6000.0;
        if (null !== ($baseline = $this->baselineStats($historicalRecords, 'stepsCount'))) {
            $threshold = max($threshold, $baseline['mean'] + ($baseline['std'] * 0.5));
        }

        if ($record['stepsCount'] <= $threshold) {
            return 0.0;
        }

        return min(5.0, ($record['stepsCount'] - $threshold) / 1500);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $record
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $historicalRecords
     */
    private function calculateSleepContribution(array $record, array $historicalRecords): float
    {
        if (null === $record['sleepDurationInSeconds']) {
            return 0.0;
        }

        $sleepDurationInHours = $record['sleepDurationInSeconds'] / 3600;
        $targetSleepInHours = 7.5;
        $baseline = $this->baselineStats($historicalRecords, 'sleepDurationInSeconds');
        if (null !== $baseline) {
            $targetSleepInHours = max($targetSleepInHours, $baseline['mean'] / 3600);
        }

        $sleepDebtInHours = $targetSleepInHours - $sleepDurationInHours;
        if ($sleepDebtInHours <= 0) {
            return 0.0;
        }

        $load = min(8.0, $sleepDebtInHours * 2.5);
        if (null !== $baseline && $baseline['std'] > 0) {
            $smallestWorthwhileChangeFloor = ($baseline['mean'] - (0.5 * $baseline['std'])) / 3600;
            if ($sleepDurationInHours < $smallestWorthwhileChangeFloor) {
                $load += min(2.0, ($smallestWorthwhileChangeFloor - $sleepDurationInHours) * 2.0);
            }
        }

        return min(10.0, $load);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $record
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $historicalRecords
     */
    private function calculateSleepScoreContribution(array $record, array $historicalRecords): float
    {
        if (null === $record['sleepScore']) {
            return 0.0;
        }

        $threshold = 75.0;
        if (null !== ($baseline = $this->baselineStats($historicalRecords, 'sleepScore'))) {
            $threshold = max($threshold, $baseline['mean'] - ($baseline['std'] * 0.5));
        }

        if ($record['sleepScore'] >= $threshold) {
            return 0.0;
        }

        return min(5.0, ($threshold - $record['sleepScore']) / 5);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $record
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $historicalRecords
     */
    private function calculateHrvContribution(array $record, array $historicalRecords): float
    {
        if (null === $record['hrv']) {
            return 0.0;
        }

        $baseline = $this->baselineStats($historicalRecords, 'hrv');
        if (null === $baseline || $baseline['mean'] <= 0) {
            return 0.0;
        }

        $smallestWorthwhileChange = max($baseline['mean'] * 0.05, $baseline['std'] * 0.5);
        $suppressionFloor = $baseline['mean'] - $smallestWorthwhileChange;
        if ($record['hrv'] >= $suppressionFloor) {
            return 0.0;
        }

        return min(6.0, (($suppressionFloor - $record['hrv']) / $baseline['mean']) * 60);
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     *
     * @return array{mean: float, std: float}|null
     */
    private function baselineStats(array $records, string $field): ?array
    {
        $windowedRecords = array_slice($records, -self::BASELINE_WINDOW);
        $values = $this->values($windowedRecords, $field);
        if (count($values) < self::MINIMUM_BASELINE_RECORDS) {
            return null;
        }

        return [
            'mean' => array_sum($values) / count($values),
            'std' => $this->standardDeviation($values),
        ];
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function average(array $records, string $field): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $record): int|float|null => $record[$field], $records),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        if ([] === $values) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     *
     * @return list<int|float>
     */
    private function values(array $records, string $field): array
    {
        return array_values(array_filter(
            array_map(static fn (array $record): int|float|null => $record[$field], $records),
            static fn (int|float|null $value): bool => null !== $value,
        ));
    }

    /**
     * @param list<int|float> $values
     */
    private function standardDeviation(array $values): float
    {
        $count = count($values);
        if ($count <= 1) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSquares = 0.0;
        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / $count);
    }
}