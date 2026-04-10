<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckIns;
use App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetrics;
use App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\ValueObject\Time\DateRange;

final readonly class TrainingLoadAnalyticsContextBuilder
{
    public function __construct(
        private QueryBus $queryBus,
        private IntegratedDailyLoadCalculator $integratedDailyLoadCalculator,
    ) {
    }

    public function build(DateRange $dateRange): TrainingLoadAnalyticsContext
    {
        /** @var FindWellnessMetricsResponse $wellnessMetrics */
        $wellnessMetrics = $this->queryBus->ask(new FindWellnessMetrics(
            dateRange: $dateRange,
            source: WellnessSource::GARMIN,
        ));

        /** @var FindDailyRecoveryCheckInsResponse $recoveryCheckIns */
        $recoveryCheckIns = $this->queryBus->ask(new FindDailyRecoveryCheckIns(
            dateRange: $dateRange,
        ));

        $integratedLoads = $this->integratedDailyLoadCalculator->calculateForDateRange($dateRange);
        $trainingMetrics = TrainingMetrics::create($integratedLoads);

        /** @var array<string, array{day: string, stepsCount: ?int, sleepDurationInSeconds: ?int, sleepScore: ?int, hrv: ?float}> $wellnessByDay */
        $wellnessByDay = [];
        foreach ($wellnessMetrics->getRecords() as $record) {
            $wellnessByDay[$record['day']] = $record;
        }

        /** @var array<string, array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}> $recoveryCheckInsByDay */
        $recoveryCheckInsByDay = [];
        foreach ($recoveryCheckIns->getRecords() as $record) {
            $recoveryCheckInsByDay[$record['day']] = $record;
        }

        $atlValues = $trainingMetrics->getAtlValues();
        $ctlValues = $trainingMetrics->getCtlValues();
        $tsbValues = $trainingMetrics->getTsbValues();
        $acRatioValues = $trainingMetrics->getAcRatioValues();
        $monotonyValues = $trainingMetrics->getMonotonyValues();
        $strainValues = $trainingMetrics->getStrainValues();

        $rows = [];
        foreach ($integratedLoads as $day => $load) {
            $rows[] = [
                'day' => $day,
                'load' => $load,
                'atl' => (float) ($atlValues[$day] ?? 0.0),
                'ctl' => (float) ($ctlValues[$day] ?? 0.0),
                'tsb' => (float) ($tsbValues[$day] ?? 0.0),
                'acRatio' => (float) ($acRatioValues[$day] ?? 0.0),
                'monotony' => isset($monotonyValues[$day]) ? (null === $monotonyValues[$day] ? null : (float) $monotonyValues[$day]) : null,
                'strain' => isset($strainValues[$day]) ? (null === $strainValues[$day] ? null : (int) $strainValues[$day]) : null,
                'wellness' => $wellnessByDay[$day] ?? null,
                'recoveryCheckIn' => $recoveryCheckInsByDay[$day] ?? null,
            ];
        }

        return new TrainingLoadAnalyticsContext(
            dateRange: $dateRange,
            integratedLoads: $integratedLoads,
            trainingMetrics: $trainingMetrics,
            wellnessMetrics: $wellnessMetrics,
            recoveryCheckIns: $recoveryCheckIns,
            rows: $rows,
        );
    }
}