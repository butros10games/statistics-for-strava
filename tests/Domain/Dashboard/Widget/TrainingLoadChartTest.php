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
        $trainingMetrics = TrainingMetrics::create(array_fill_keys([
            '2026-03-31',
            '2026-04-01',
            '2026-04-02',
            '2026-04-03',
            '2026-04-04',
            '2026-04-05',
            '2026-04-06',
            '2026-04-07',
        ], 40));
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