<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadAnalyticsContext;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadCorrelationInsightsAnalyzer;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class TrainingLoadCorrelationInsightsAnalyzerTest extends TestCase
{
    public function testItFindsLoadAndRecoveryCorrelations(): void
    {
        $context = $this->buildContext();

        $insights = (new TrainingLoadCorrelationInsightsAnalyzer())->analyze($context, 10);
        $keys = array_map(static fn ($insight): string => $insight->getKey(), $insights);

        self::assertContains('loadToNextDayHrv', $keys);
        self::assertContains('loadToNextDaySleepScore', $keys);
    }

    private function buildContext(): TrainingLoadAnalyticsContext
    {
        $loads = [40, 60, 80, 100, 120, 140, 160, 180, 200, 220];
        $start = SerializableDateTime::fromString('2026-03-25 00:00:00');

        $intensities = [];
        $wellnessByDay = [];
        foreach ($loads as $offset => $load) {
            $day = $start->modify(sprintf('+%d days', $offset))->format('Y-m-d');
            $intensities[$day] = $load;
            $wellnessByDay[$day] = [
                'day' => $day,
                'stepsCount' => 8000,
                'sleepDurationInSeconds' => 28800,
                'sleepScore' => 86 - ($offset * 2),
                'hrv' => 72.0 - ($offset * 2.0),
            ];
        }

        $trainingMetrics = TrainingMetrics::create($intensities);
        $wellnessRecords = array_values($wellnessByDay);

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
                'wellness' => $wellnessByDay[$day],
                'recoveryCheckIn' => null,
            ];
        }

        return new TrainingLoadAnalyticsContext(
            dateRange: DateRange::fromDates(from: $start, till: $start->modify('+9 days')),
            integratedLoads: $intensities,
            trainingMetrics: $trainingMetrics,
            wellnessMetrics: new FindWellnessMetricsResponse(records: $wellnessRecords, latestDay: $start->modify('+9 days')),
            recoveryCheckIns: new FindDailyRecoveryCheckInsResponse(records: [], latestDay: null),
            rows: $rows,
        );
    }
}