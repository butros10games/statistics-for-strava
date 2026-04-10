<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Infrastructure\ValueObject\Time\DateRange;

final readonly class TrainingLoadAnalyticsContext
{
    /**
     * @param array<string, int> $integratedLoads
     * @param list<array{
     *     day: string,
     *     load: int,
     *     atl: float,
     *     ctl: float,
     *     tsb: float,
     *     acRatio: float,
     *     monotony: ?float,
     *     strain: ?int,
     *     wellness: array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}|null,
     *     recoveryCheckIn: array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     * }> $rows
     */
    public function __construct(
        private DateRange $dateRange,
        private array $integratedLoads,
        private TrainingMetrics $trainingMetrics,
        private FindWellnessMetricsResponse $wellnessMetrics,
        private FindDailyRecoveryCheckInsResponse $recoveryCheckIns,
        private array $rows,
    ) {
    }

    public function getDateRange(): DateRange
    {
        return $this->dateRange;
    }

    /**
     * @return array<string, int>
     */
    public function getIntegratedLoads(): array
    {
        return $this->integratedLoads;
    }

    public function getTrainingMetrics(): TrainingMetrics
    {
        return $this->trainingMetrics;
    }

    public function getWellnessMetrics(): FindWellnessMetricsResponse
    {
        return $this->wellnessMetrics;
    }

    public function getRecoveryCheckIns(): FindDailyRecoveryCheckInsResponse
    {
        return $this->recoveryCheckIns;
    }

    /**
     * @return list<array{
     *     day: string,
     *     load: int,
     *     atl: float,
     *     ctl: float,
     *     tsb: float,
     *     acRatio: float,
     *     monotony: ?float,
     *     strain: ?int,
     *     wellness: array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}|null,
     *     recoveryCheckIn: array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     * }>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return array{
     *     day: string,
     *     load: int,
     *     atl: float,
     *     ctl: float,
     *     tsb: float,
     *     acRatio: float,
     *     monotony: ?float,
     *     strain: ?int,
     *     wellness: array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}|null,
     *     recoveryCheckIn: array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     * }|null
     */
    public function getLatestRow(): ?array
    {
        if ([] === $this->rows) {
            return null;
        }

        return $this->rows[array_key_last($this->rows)];
    }

    /**
     * @return list<array{
     *     day: string,
     *     load: int,
     *     atl: float,
     *     ctl: float,
     *     tsb: float,
     *     acRatio: float,
     *     monotony: ?float,
     *     strain: ?int,
     *     wellness: array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}|null,
     *     recoveryCheckIn: array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     * }>
     */
    public function getLastRows(int $days): array
    {
        return array_values(array_slice($this->rows, -$days));
    }

    /**
     * @return list<array{
     *     day: string,
     *     load: int,
     *     atl: float,
     *     ctl: float,
     *     tsb: float,
     *     acRatio: float,
     *     monotony: ?float,
     *     strain: ?int,
     *     wellness: array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}|null,
     *     recoveryCheckIn: array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null
     * }>
     */
    public function getRowsBeforeLast(int $days, int $skipLatestDays = 0): array
    {
        $rows = $this->rows;
        if ($skipLatestDays > 0) {
            $rows = array_slice($rows, 0, max(0, count($rows) - $skipLatestDays));
        }

        return array_values(array_slice($rows, -$days));
    }

    /**
     * @return list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}>
     */
    public function getWellnessBaselineRecords(int $window = 21): array
    {
        $records = $this->wellnessMetrics->getRecords();
        if (count($records) <= 1) {
            return $records;
        }

        return array_slice($records, max(0, count($records) - ($window + 1)), -1);
    }
}