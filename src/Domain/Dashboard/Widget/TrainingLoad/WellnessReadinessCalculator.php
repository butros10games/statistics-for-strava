<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;

final class WellnessReadinessCalculator
{
    private const int BASELINE_WINDOW = 21;

    /**
     * @param array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null $latestRecoveryCheckIn
     */
    public function calculate(TrainingMetrics $trainingMetrics, FindWellnessMetricsResponse $wellnessMetrics, ?array $latestRecoveryCheckIn = null): ?ReadinessScore
    {
        if ($wellnessMetrics->isEmpty()) {
            return null;
        }

        $latestRecord = $wellnessMetrics->getLatestRecord();
        if (null === $latestRecord) {
            return null;
        }

        if (null === $latestRecord['hrv'] && null === $latestRecord['sleepDurationInSeconds'] && null === $latestRecord['sleepScore']) {
            return null;
        }

        $records = $wellnessMetrics->getRecords();
        $baselineRecords = count($records) > 1 ? array_slice($records, 0, -1) : $records;

        $score = 55.0;
        $score += $this->calculateHrvComponent($latestRecord, $baselineRecords);
        $score += $this->calculateSleepDurationComponent($latestRecord, $baselineRecords);
        $score += $this->calculateSleepScoreComponent($latestRecord, $baselineRecords);
        $score += $this->calculateStepsComponent($latestRecord, $baselineRecords);

        if (($currentTsb = $trainingMetrics->getCurrentTsb()?->getValue()) !== null) {
            $score += $this->clamp($currentTsb * 0.45, -10, 10);
        }

        if (($currentAcRatio = $trainingMetrics->getCurrentAcRatio()) !== null) {
            $score += match ($currentAcRatio->getStatus()) {
                AcRatioStatus::LOW_RISK => 6,
                AcRatioStatus::LOW_TRAINING_LOAD => -3,
                AcRatioStatus::HIGH_RISK => -12,
            };
        }

        if (($currentMonotony = $trainingMetrics->getCurrentMonotony()) !== null) {
            $score += match (true) {
                $currentMonotony > 2.25 => -10,
                $currentMonotony > 1.75 => -5,
                default => 0,
            };
        }

        $score += $this->calculateRecoveryQuestionnaireComponent($latestRecoveryCheckIn);

        return ReadinessScore::of((int) round($this->clamp($score, 0, 100)));
    }

    /**
     * @param array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null $latestRecoveryCheckIn
     */
    private function calculateRecoveryQuestionnaireComponent(?array $latestRecoveryCheckIn): float
    {
        if (null === $latestRecoveryCheckIn) {
            return 0.0;
        }

        $score = 0.0;
        $score -= ($latestRecoveryCheckIn['fatigue'] - 1) * 3.0;
        $score -= ($latestRecoveryCheckIn['soreness'] - 1) * 2.0;
        $score -= ($latestRecoveryCheckIn['stress'] - 1) * 2.5;
        $score += ($latestRecoveryCheckIn['motivation'] - 3) * 2.0;
        $score += ($latestRecoveryCheckIn['sleepQuality'] - 3) * 2.0;

        return $this->clamp($score, -20, 12);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $latestRecord
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $baselineRecords
     */
    private function calculateHrvComponent(array $latestRecord, array $baselineRecords): float
    {
        if (null === $latestRecord['hrv']) {
            return 0.0;
        }

        $baseline = $this->average($baselineRecords, 'hrv');
        if (null === $baseline || $baseline <= 0) {
            return 0.0;
        }

        $delta = $latestRecord['hrv'] - $baseline;
        if (null !== ($stats = $this->baselineStats($baselineRecords, 'hrv'))) {
            $smallestWorthwhileChange = max($stats['mean'] * 0.05, $stats['std'] * 0.5, 1.0);
            if ($delta >= $smallestWorthwhileChange) {
                return min(12.0, 4.0 + (($delta - $smallestWorthwhileChange) / $stats['mean']) * 60);
            }
            if ($delta <= -$smallestWorthwhileChange) {
                return -min(16.0, 4.0 + ((abs($delta) - $smallestWorthwhileChange) / $stats['mean']) * 80);
            }

            return ($delta / $smallestWorthwhileChange) * 4.0;
        }

        return $this->clamp((($delta) / $baseline) * 100 * 0.9, -16, 12);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $latestRecord
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $baselineRecords
     */
    private function calculateSleepDurationComponent(array $latestRecord, array $baselineRecords): float
    {
        if (null === $latestRecord['sleepDurationInSeconds']) {
            return 0.0;
        }

        $baselineHours = ($this->average($baselineRecords, 'sleepDurationInSeconds') ?? 0.0) / 3600;
        $targetSleepInHours = max(7.5, $baselineHours);
        $sleepDurationInHours = $latestRecord['sleepDurationInSeconds'] / 3600;

        return $this->clamp(($sleepDurationInHours - $targetSleepInHours) * 6.0, -14, 8);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $latestRecord
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $baselineRecords
     */
    private function calculateSleepScoreComponent(array $latestRecord, array $baselineRecords): float
    {
        if (null === $latestRecord['sleepScore']) {
            return 0.0;
        }

        $sleepScoreBaseline = $this->average($baselineRecords, 'sleepScore') ?? 75.0;
        $targetSleepScore = max(75.0, $sleepScoreBaseline);

        return $this->clamp(($latestRecord['sleepScore'] - $targetSleepScore) * 0.5, -12, 8);
    }

    /**
     * @param array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float} $latestRecord
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $baselineRecords
     */
    private function calculateStepsComponent(array $latestRecord, array $baselineRecords): float
    {
        if (null === $latestRecord['stepsCount']) {
            return 0.0;
        }

        $stepsBaseline = $this->average($baselineRecords, 'stepsCount') ?? 6000.0;
        $stepsThreshold = max(6000.0, $stepsBaseline);
        if ($latestRecord['stepsCount'] <= $stepsThreshold) {
            return 0.0;
        }

        return -$this->clamp(($latestRecord['stepsCount'] - $stepsThreshold) / 1500, 0, 6);
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
        if (count($values) < 3) {
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

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($value, $max));
    }
}