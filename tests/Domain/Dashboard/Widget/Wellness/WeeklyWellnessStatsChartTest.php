<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\Wellness;

use App\Domain\Dashboard\Widget\Wellness\WeeklyWellnessStatsChart;
use App\Domain\Dashboard\Widget\Wellness\WellnessMetric;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WeeklyWellnessStatsChartTest extends TestCase
{
    public function testItAggregatesWeeklyStepTotals(): void
    {
        $chart = WeeklyWellnessStatsChart::create(
            records: [
                ['day' => '2026-03-30', 'stepsCount' => 8000, 'sleepDurationInSeconds' => 27000, 'sleepScore' => 79, 'hrv' => 52.0],
                ['day' => '2026-04-01', 'stepsCount' => 12000, 'sleepDurationInSeconds' => 28200, 'sleepScore' => 82, 'hrv' => 54.0],
                ['day' => '2026-04-07', 'stepsCount' => 9000, 'sleepDurationInSeconds' => 27900, 'sleepScore' => 80, 'hrv' => 53.0],
            ],
            metric: WellnessMetric::STEPS,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            translator: $this->createTranslator(),
        )->build();

        $this->assertSame([20000, 9000], $chart['series'][0]['data']);
        $this->assertSame('{value} steps', $chart['yAxis'][0]['axisLabel']['formatter']);
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