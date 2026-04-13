<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget;

use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadChart;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastProjection;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TrainingLoadChartTest extends TestCase
{
    public function testItAddsPlannedSessionForecastSeriesToChart(): void
    {
        $trainingMetrics = $this->buildTrainingMetrics('2026-04-07', 40);
        $forecastProjection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $trainingMetrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 55.0, 2 => 60.0, 3 => 45.0],
            horizon: 3,
        );

        $chart = TrainingLoadChart::create(
            trainingMetrics: $trainingMetrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            translator: $this->createTranslatorStub(),
            plannedSessionForecastProjection: $forecastProjection,
        )->build();

        self::assertCount(TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY + 3, $chart['xAxis'][0]['data']);
        self::assertSame('callback:formatTrainingLoadTooltip', $chart['tooltip']['formatter']);
        self::assertSame([
            'CTL (Fitness)',
            'ATL (Fatigue)',
            'TSB (Form)',
            'Daily load',
        ], $chart['legend']['data']);

        $seriesByName = [];
        foreach ($chart['series'] as $series) {
            $seriesByName[$series['name']] = $series;
        }

        self::assertArrayHasKey('__forecast_ctl', $seriesByName);
        self::assertArrayHasKey('__forecast_atl', $seriesByName);
        self::assertArrayHasKey('__forecast_tsb', $seriesByName);
        self::assertArrayHasKey('__forecast_load', $seriesByName);

        self::assertSame([55.0, 60.0, 45.0], array_slice(array_values(array_filter(
            $seriesByName['__forecast_load']['data'],
            static fn (mixed $value): bool => null !== $value,
        )), -3));
    }

    public function testItShowsCurrentDayPredictedLoadOnTodaysChartIndex(): void
    {
        $trainingMetrics = $this->buildTrainingMetrics('2026-04-07', 40);
        $forecastProjection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $trainingMetrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 55.0, 2 => 60.0, 3 => 45.0],
            horizon: 3,
            currentDayProjectedLoad: 35.0,
        );

        $chart = TrainingLoadChart::create(
            trainingMetrics: $trainingMetrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            translator: $this->createTranslatorStub(),
            plannedSessionForecastProjection: $forecastProjection,
        )->build();

        $seriesByName = [];
        foreach ($chart['series'] as $series) {
            $seriesByName[$series['name']] = $series;
        }

        $forecastLoadData = $seriesByName['__forecast_load']['data'];
        self::assertSame(35.0, $forecastLoadData[TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY - 1]);
        self::assertSame([35.0, 55.0, 60.0, 45.0], array_values(array_filter(
            $forecastLoadData,
            static fn (mixed $value): bool => null !== $value,
        )));
    }

    public function testItAnchorsCurrentDayForecastLinesAboveTodaysMetricsWhenPendingLoadExists(): void
    {
        $trainingMetrics = $this->buildTrainingMetrics('2026-04-07', 40);
        $forecastProjection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $trainingMetrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 55.0, 2 => 60.0, 3 => 45.0],
            horizon: 3,
            currentDayProjectedLoad: 35.0,
        );

        $chart = TrainingLoadChart::create(
            trainingMetrics: $trainingMetrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            translator: $this->createTranslatorStub(),
            plannedSessionForecastProjection: $forecastProjection,
        )->build();

        $seriesByName = [];
        foreach ($chart['series'] as $series) {
            $seriesByName[$series['name']] = $series;
        }

        $todayIndex = TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY - 1;

        self::assertGreaterThan(
            $seriesByName['ATL (Fatigue)']['data'][$todayIndex],
            $seriesByName['__forecast_atl']['data'][$todayIndex],
        );
        self::assertGreaterThan(
            $seriesByName['CTL (Fitness)']['data'][$todayIndex],
            $seriesByName['__forecast_ctl']['data'][$todayIndex],
        );
    }

    private function buildTrainingMetrics(string $today, int $load): TrainingMetrics
    {
        $intensities = [];
        $referenceDay = SerializableDateTime::fromString($today.' 00:00:00');

        for ($day = TrainingLoadChart::NUMBER_OF_DAYS_TO_DISPLAY - 1; $day >= 0; --$day) {
            $intensities[$referenceDay->modify(sprintf('-%d days', $day))->format('Y-m-d')] = $load;
        }

        return TrainingMetrics::create($intensities);
    }

    private function createTranslatorStub(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'en_US';
            }
        };
    }
}