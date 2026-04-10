<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessScore;
use App\Domain\Dashboard\Widget\TrainingLoad\RecoveryTrendAnalyzer;
use App\Domain\Dashboard\Widget\TrainingLoad\RecoveryTrendWarningType;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadAnalyticsContext;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class RecoveryTrendAnalyzerTest extends TestCase
{
    private RecoveryTrendAnalyzer $analyzer;

    public function testItFlagsAccumulatingFatigueAndSuppressedRecovery(): void
    {
        $context = $this->buildContext(
            intensities: $this->buildFatigueHeavyIntensities(),
            wellnessRecords: [
                ['day' => '2026-04-01', 'stepsCount' => 9000, 'sleepDurationInSeconds' => 28800, 'sleepScore' => 82, 'hrv' => 60.0],
                ['day' => '2026-04-02', 'stepsCount' => 9200, 'sleepDurationInSeconds' => 28500, 'sleepScore' => 81, 'hrv' => 59.0],
                ['day' => '2026-04-03', 'stepsCount' => 9100, 'sleepDurationInSeconds' => 28200, 'sleepScore' => 80, 'hrv' => 58.0],
                ['day' => '2026-04-04', 'stepsCount' => 14000, 'sleepDurationInSeconds' => 23400, 'sleepScore' => 68, 'hrv' => 47.0],
            ],
            recoveryCheckIns: [
                ['day' => '2026-04-04', 'fatigue' => 5, 'soreness' => 4, 'stress' => 4, 'motivation' => 2, 'sleepQuality' => 2],
            ],
        );

        $warnings = $this->analyzer->analyze($context, ReadinessScore::of(48));

        self::assertContains(RecoveryTrendWarningType::ACCUMULATING_FATIGUE, $warnings);
        self::assertContains(RecoveryTrendWarningType::MONOTONY_RISK, $warnings);
        self::assertContains(RecoveryTrendWarningType::SUPPRESSED_RECOVERY, $warnings);
    }

    public function testItFlagsRecoveryReboundWhenSignalsImprove(): void
    {
        $context = $this->buildContext(
            intensities: $this->buildRecoveryReboundIntensities(),
            wellnessRecords: [
                ['day' => '2026-03-31', 'stepsCount' => 8000, 'sleepDurationInSeconds' => 27000, 'sleepScore' => 75, 'hrv' => 48.0],
                ['day' => '2026-04-01', 'stepsCount' => 8200, 'sleepDurationInSeconds' => 27300, 'sleepScore' => 76, 'hrv' => 49.0],
                ['day' => '2026-04-02', 'stepsCount' => 8100, 'sleepDurationInSeconds' => 27600, 'sleepScore' => 77, 'hrv' => 50.0],
                ['day' => '2026-04-03', 'stepsCount' => 7800, 'sleepDurationInSeconds' => 29100, 'sleepScore' => 83, 'hrv' => 56.0],
            ],
            recoveryCheckIns: [
                ['day' => '2026-04-03', 'fatigue' => 2, 'soreness' => 2, 'stress' => 2, 'motivation' => 4, 'sleepQuality' => 4],
            ],
        );

        $warnings = $this->analyzer->analyze($context, ReadinessScore::of(82));

        self::assertContains(RecoveryTrendWarningType::RECOVERY_REBOUND, $warnings);
        self::assertNotContains(RecoveryTrendWarningType::SUPPRESSED_RECOVERY, $warnings);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->analyzer = new RecoveryTrendAnalyzer();
    }

    /**
     * @param array<string, int> $intensities
     * @param list<array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $wellnessRecords
     * @param list<array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}> $recoveryCheckIns
     */
    private function buildContext(array $intensities, array $wellnessRecords, array $recoveryCheckIns): TrainingLoadAnalyticsContext
    {
        $trainingMetrics = TrainingMetrics::create($intensities);
        $wellnessResponse = new FindWellnessMetricsResponse(
            records: $wellnessRecords,
            latestDay: SerializableDateTime::fromString(end($wellnessRecords)['day'].' 00:00:00'),
        );
        $recoveryResponse = new FindDailyRecoveryCheckInsResponse(
            records: $recoveryCheckIns,
            latestDay: SerializableDateTime::fromString(end($recoveryCheckIns)['day'].' 00:00:00'),
        );

        $wellnessByDay = [];
        foreach ($wellnessRecords as $record) {
            $wellnessByDay[$record['day']] = $record;
        }

        $checkInsByDay = [];
        foreach ($recoveryCheckIns as $record) {
            $checkInsByDay[$record['day']] = $record;
        }

        $rows = [];
        foreach ($intensities as $day => $load) {
            $rows[] = [
                'day' => $day,
                'load' => $load,
                'atl' => (float) ($trainingMetrics->getAtlValues()[$day] ?? 0.0),
                'ctl' => (float) ($trainingMetrics->getCtlValues()[$day] ?? 0.0),
                'tsb' => (float) ($trainingMetrics->getTsbValues()[$day] ?? 0.0),
                'acRatio' => (float) ($trainingMetrics->getAcRatioValues()[$day] ?? 0.0),
                'monotony' => isset($trainingMetrics->getMonotonyValues()[$day]) ? (null === $trainingMetrics->getMonotonyValues()[$day] ? null : (float) $trainingMetrics->getMonotonyValues()[$day]) : null,
                'strain' => isset($trainingMetrics->getStrainValues()[$day]) ? (null === $trainingMetrics->getStrainValues()[$day] ? null : (int) $trainingMetrics->getStrainValues()[$day]) : null,
                'wellness' => $wellnessByDay[$day] ?? null,
                'recoveryCheckIn' => $checkInsByDay[$day] ?? null,
            ];
        }

        return new TrainingLoadAnalyticsContext(
            dateRange: DateRange::fromDates(
                from: SerializableDateTime::fromString(array_key_first($intensities).' 00:00:00'),
                till: SerializableDateTime::fromString(array_key_last($intensities).' 00:00:00'),
            ),
            integratedLoads: $intensities,
            trainingMetrics: $trainingMetrics,
            wellnessMetrics: $wellnessResponse,
            recoveryCheckIns: $recoveryResponse,
            rows: $rows,
        );
    }

    /**
     * @return array<string, int>
     */
    private function buildFatigueHeavyIntensities(): array
    {
        $intensities = [];
        for ($day = 13; $day >= 7; --$day) {
            $date = SerializableDateTime::fromString('2026-04-04 00:00:00')->modify(sprintf('-%d days', $day));
            $intensities[$date->format('Y-m-d')] = 70;
        }

        for ($day = 6; $day >= 0; --$day) {
            $date = SerializableDateTime::fromString('2026-04-04 00:00:00')->modify(sprintf('-%d days', $day));
            $intensities[$date->format('Y-m-d')] = 160;
        }

        ksort($intensities);

        return $intensities;
    }

    /**
     * @return array<string, int>
     */
    private function buildRecoveryReboundIntensities(): array
    {
        $intensities = [];
        foreach ([120, 130, 125, 115, 50, 20, 10] as $offset => $value) {
            $date = SerializableDateTime::fromString('2026-04-03 00:00:00')->modify(sprintf('-%d days', 6 - $offset));
            $intensities[$date->format('Y-m-d')] = $value;
        }

        ksort($intensities);

        return $intensities;
    }
}