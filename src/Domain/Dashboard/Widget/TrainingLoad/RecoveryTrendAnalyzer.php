<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final class RecoveryTrendAnalyzer
{
    /**
     * @return list<RecoveryTrendWarningType>
     */
    public function analyze(TrainingLoadAnalyticsContext $analyticsContext, ?ReadinessScore $readinessScore): array
    {
        $warnings = [];
        $latestRow = $analyticsContext->getLatestRow();
        if (null === $latestRow) {
            return $warnings;
        }

        $last7Rows = $analyticsContext->getLastRows(7);
        $previous7Rows = $analyticsContext->getRowsBeforeLast(7, 7);

        $currentTsb = $latestRow['tsb'];
        $currentAcRatio = $latestRow['acRatio'];
        $currentMonotony = $latestRow['monotony'] ?? 0.0;
        $currentReadiness = $readinessScore?->getValue();

        $last7AverageLoad = $this->averageNumericField($last7Rows, 'load');
        $previous7AverageLoad = $this->averageNumericField($previous7Rows, 'load');
        $last7AverageTsb = $this->averageNumericField($last7Rows, 'tsb');
        $previous7AverageTsb = $this->averageNumericField($previous7Rows, 'tsb');

        if (
            $currentAcRatio > 1.3
            || $currentTsb <= -10.0
            || ($last7AverageLoad > ($previous7AverageLoad * 1.15) && $last7AverageTsb < ($previous7AverageTsb - 4.0))
            || (null !== $currentReadiness && $currentReadiness < 60 && $last7AverageLoad >= $previous7AverageLoad)
        ) {
            $warnings[] = RecoveryTrendWarningType::ACCUMULATING_FATIGUE;
        }

        $recentMonotonyAverage = $this->averageNumericField(
            array_values(array_filter($last7Rows, static fn (array $row): bool => null !== $row['monotony'])),
            'monotony'
        );
        if ($currentMonotony >= 1.75 || $recentMonotonyAverage >= 1.75) {
            $warnings[] = RecoveryTrendWarningType::MONOTONY_RISK;
        }

        $latestWellnessRecord = $analyticsContext->getWellnessMetrics()->getLatestRecord();
        $wellnessBaselineRecords = $analyticsContext->getWellnessBaselineRecords();
        $latestRecoveryCheckIn = $analyticsContext->getRecoveryCheckIns()->getLatestRecord();

        $hrvBaseline = $this->averageMetric($wellnessBaselineRecords, 'hrv');
        $sleepScoreBaseline = $this->averageMetric($wellnessBaselineRecords, 'sleepScore');
        $sleepDurationBaseline = $this->averageMetric($wellnessBaselineRecords, 'sleepDurationInSeconds');

        $hrvSuppressed = null !== ($latestWellnessRecord['hrv'] ?? null)
            && null !== $hrvBaseline
            && $latestWellnessRecord['hrv'] < ($hrvBaseline * 0.93);
        $sleepScoreSuppressed = null !== ($latestWellnessRecord['sleepScore'] ?? null)
            && null !== $sleepScoreBaseline
            && $latestWellnessRecord['sleepScore'] < ($sleepScoreBaseline - 6.0);
        $sleepDurationSuppressed = null !== ($latestWellnessRecord['sleepDurationInSeconds'] ?? null)
            && null !== $sleepDurationBaseline
            && $latestWellnessRecord['sleepDurationInSeconds'] < ($sleepDurationBaseline - 2700.0);
        $checkInSuppressed = null !== $latestRecoveryCheckIn
            && ($latestRecoveryCheckIn['fatigue'] >= 4 || $latestRecoveryCheckIn['stress'] >= 4 || $latestRecoveryCheckIn['sleepQuality'] <= 2);

        if ($hrvSuppressed || $sleepScoreSuppressed || $sleepDurationSuppressed || $checkInSuppressed || (null !== $currentReadiness && $currentReadiness < 55)) {
            $warnings[] = RecoveryTrendWarningType::SUPPRESSED_RECOVERY;
        }

        $last3Rows = $analyticsContext->getLastRows(3);
        $previous3Rows = $analyticsContext->getRowsBeforeLast(3, 3);
        $last3AverageTsb = $this->averageNumericField($last3Rows, 'tsb');
        $previous3AverageTsb = $this->averageNumericField($previous3Rows, 'tsb');
        $hrvRebounding = null !== ($latestWellnessRecord['hrv'] ?? null)
            && null !== $hrvBaseline
            && $latestWellnessRecord['hrv'] > ($hrvBaseline * 1.03);

        if (
            (null !== $currentReadiness && $currentReadiness >= 75 && $currentTsb > 0)
            || ($last3AverageTsb > ($previous3AverageTsb + 3.0) && $currentTsb > 0)
            || ($hrvRebounding && $currentTsb > -5.0)
        ) {
            $warnings[] = RecoveryTrendWarningType::RECOVERY_REBOUND;
        }

        $uniqueWarnings = [];
        foreach ($warnings as $warning) {
            $uniqueWarnings[$warning->value] = $warning;
        }

        return array_values($uniqueWarnings);
    }

    /**
     * @param list<array<string, int|float|null|array|null>> $rows
     */
    private function averageNumericField(array $rows, string $field): float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $row): int|float|null => is_numeric($row[$field] ?? null) ? $row[$field] : null, $rows),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        if ([] === $values) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $records
     */
    private function averageMetric(array $records, string $field): ?float
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
}