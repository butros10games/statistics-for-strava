<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\Wellness;

use App\Domain\Dashboard\Widget\Wellness\MonthlyWellnessStatsChart;
use App\Domain\Dashboard\Widget\Wellness\WellnessMetric;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MonthlyWellnessStatsChartTest extends TestCase
{
    public function testItAggregatesMonthlyWellnessByYear(): void
    {
        $chart = MonthlyWellnessStatsChart::create(
            records: [
                ['day' => '2025-02-01', 'stepsCount' => 9000, 'sleepDurationInSeconds' => 27000, 'sleepScore' => 80, 'hrv' => 50.0],
                ['day' => '2025-02-18', 'stepsCount' => 9500, 'sleepDurationInSeconds' => 28200, 'sleepScore' => 84, 'hrv' => 52.0],
                ['day' => '2026-02-05', 'stepsCount' => 11000, 'sleepDurationInSeconds' => 28800, 'sleepScore' => 78, 'hrv' => 55.0],
            ],
            metric: WellnessMetric::SLEEP_SCORE,
            translator: $this->createTranslator(),
            enableLastXYearsByDefault: 2,
        )->build();

        $this->assertCount(2, $chart['series']);
        $this->assertSame('2026', $chart['series'][0]['name']);
        $this->assertSame(78.0, $chart['series'][0]['data'][1]);
        $this->assertSame('2025', $chart['series'][1]['name']);
        $this->assertSame(82.0, $chart['series'][1]['data'][1]);
    }

    private function createTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return (string) $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function setLocale(string $locale): void
            {
            }
        };
    }
}