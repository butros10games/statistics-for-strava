<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastProjection;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastScenario;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class TrainingLoadForecastProjectionTest extends TestCase
{
    public function testCompleteRestScenarioProjectsZeroLoadForEveryDay(): void
    {
        $projection = TrainingLoadForecastProjection::create(
            metrics: TrainingMetrics::create($this->buildConstantIntensities(100, 42)),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            scenario: TrainingLoadForecastScenario::COMPLETE_REST,
        );

        self::assertCount(7, $projection->getProjection());
        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], array_map(
            static fn (array $day): float => $day['projectedLoad'],
            $projection->getProjection(),
        ));
    }

    public function testMaintainRecentLoadScenarioUsesAverageRecentLoad(): void
    {
        $projection = TrainingLoadForecastProjection::create(
            metrics: TrainingMetrics::create($this->buildTrailingWeekPattern()),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            scenario: TrainingLoadForecastScenario::MAINTAIN_RECENT_LOAD,
        );

        self::assertSame([100.0, 100.0, 100.0, 100.0, 100.0, 100.0, 100.0], array_map(
            static fn (array $day): float => $day['projectedLoad'],
            $projection->getProjection(),
        ));
    }

    public function testOneHardSessionScenarioFrontLoadsStress(): void
    {
        $projection = TrainingLoadForecastProjection::create(
            metrics: TrainingMetrics::create($this->buildTrailingWeekPattern()),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            scenario: TrainingLoadForecastScenario::ONE_HARD_SESSION,
        );

        $loads = array_map(static fn (array $day): float => $day['projectedLoad'], $projection->getProjection());

        self::assertGreaterThan($loads[1], $loads[0]);
        self::assertGreaterThan($loads[2], $loads[0]);
        self::assertSame(135.0, $loads[0]);
    }

    public function testItCanProjectCustomLoadsAcrossACustomHorizon(): void
    {
        $projection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: TrainingMetrics::create($this->buildConstantIntensities(100, 42)),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 60, 2 => 110, 3 => 0, 4 => 90, 5 => 20],
            horizon: 5,
        );

        self::assertSame(5, $projection->getHorizon());
        self::assertCount(5, $projection->getProjection());
        self::assertSame([60.0, 110.0, 0.0, 90.0, 20.0], array_map(
            static fn (array $day): float => $day['projectedLoad'],
            $projection->getProjection(),
        ));
    }

    public function testScenarioProjectionCanExtendBeyondSevenDays(): void
    {
        $projection = TrainingLoadForecastProjection::create(
            metrics: TrainingMetrics::create($this->buildTrailingWeekPattern()),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            scenario: TrainingLoadForecastScenario::LIGHT_RECOVERY,
            horizon: 10,
        );

        self::assertSame(10, $projection->getHorizon());
        self::assertCount(10, $projection->getProjection());
        self::assertSame(array_fill(0, 10, 35.0), array_map(
            static fn (array $day): float => $day['projectedLoad'],
            $projection->getProjection(),
        ));
    }

    /**
     * @return array<string, int>
     */
    private function buildConstantIntensities(int $value, int $numberOfDays): array
    {
        $intensities = [];

        for ($day = $numberOfDays - 1; $day >= 0; --$day) {
            $date = SerializableDateTime::fromString('2026-04-07 00:00:00')->modify(sprintf('-%d days', $day));
            $intensities[$date->format('Y-m-d')] = $value;
        }

        return $intensities;
    }

    /**
     * @return array<string, int>
     */
    private function buildTrailingWeekPattern(): array
    {
        $intensities = $this->buildConstantIntensities(80, 35);

        foreach ([80, 90, 100, 110, 120, 90, 110] as $offset => $value) {
            $date = SerializableDateTime::fromString('2026-04-07 00:00:00')->modify(sprintf('-%d days', 6 - $offset));
            $intensities[$date->format('Y-m-d')] = $value;
        }

        ksort($intensities);

        return $intensities;
    }
}