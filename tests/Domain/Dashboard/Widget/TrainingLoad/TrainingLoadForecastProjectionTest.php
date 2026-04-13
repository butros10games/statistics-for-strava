<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastConfidence;
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

    public function testCompleteRestScenarioTracksRecoveryMilestones(): void
    {
        $projection = TrainingLoadForecastProjection::create(
            metrics: TrainingMetrics::create($this->buildFatigueHeavyIntensities()),
            now: SerializableDateTime::fromString('2026-04-04 00:00:00'),
            scenario: TrainingLoadForecastScenario::COMPLETE_REST,
        );

        self::assertSame(4, $projection->getDaysUntilTsbHealthy());
        self::assertSame(2, $projection->getDaysUntilAcRatioHealthy());
    }

    public function testStableRecentLoadProducesHigherForecastConfidence(): void
    {
        $projection = TrainingLoadForecastProjection::create(
            metrics: TrainingMetrics::create($this->buildConstantIntensities(100, 42)),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            scenario: TrainingLoadForecastScenario::COMPLETE_REST,
        );

        self::assertSame(TrainingLoadForecastConfidence::HIGH, $projection->getConfidence());
    }

    public function testSparseAndVolatileHistoryLowersForecastConfidence(): void
    {
        $projection = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: TrainingMetrics::create($this->buildSparseVolatileIntensities()),
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 160, 2 => 0, 3 => 150, 4 => 20, 5 => 180],
            horizon: 5,
        );

        self::assertSame(TrainingLoadForecastConfidence::LOW, $projection->getConfidence());
    }

    public function testCurrentDayProjectedLoadAdjustsTheForecastBaseline(): void
    {
        $metrics = TrainingMetrics::create($this->buildConstantIntensities(100, 42));
        $alphaATL = 1 - exp(-1 / 7);
        $alphaCTL = 1 - exp(-1 / 42);

        $withoutCurrentDayLoad = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $metrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 0, 2 => 0, 3 => 0],
            horizon: 3,
        );
        $withCurrentDayLoad = TrainingLoadForecastProjection::createWithProjectedLoads(
            metrics: $metrics,
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            projectedLoads: [1 => 0, 2 => 0, 3 => 0],
            horizon: 3,
            currentDayProjectedLoad: 160.0,
        );

        self::assertSame([0.0, 0.0, 0.0], array_map(
            static fn (array $day): float => $day['projectedLoad'],
            $withCurrentDayLoad->getProjection(),
        ));
        self::assertNotNull($withCurrentDayLoad->getCurrentDayProjection());
        self::assertSame(160.0, $withCurrentDayLoad->getCurrentDayProjection()['projectedLoad']);
        self::assertSame('2026-04-07', $withCurrentDayLoad->getCurrentDayProjection()['day']->format('Y-m-d'));
        self::assertSame(round(100 + (160 * $alphaATL), 1), $withCurrentDayLoad->getCurrentDayProjection()['atl']);
        self::assertSame(round(100 + (160 * $alphaCTL), 1), $withCurrentDayLoad->getCurrentDayProjection()['ctl']);
        self::assertGreaterThan(
            $withoutCurrentDayLoad->getProjection()[0]['atl'],
            $withCurrentDayLoad->getProjection()[0]['atl'],
        );
        self::assertGreaterThan(
            $withoutCurrentDayLoad->getProjection()[0]['ctl'],
            $withCurrentDayLoad->getProjection()[0]['ctl'],
        );
        self::assertGreaterThan(100.0, $withCurrentDayLoad->getCurrentDayProjection()['atl']);
        self::assertGreaterThan(100.0, $withCurrentDayLoad->getCurrentDayProjection()['ctl']);
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
    private function buildSparseVolatileIntensities(): array
    {
        return [
            '2026-04-03' => 40,
            '2026-04-04' => 180,
            '2026-04-05' => 20,
            '2026-04-06' => 160,
            '2026-04-07' => 10,
        ];
    }
}