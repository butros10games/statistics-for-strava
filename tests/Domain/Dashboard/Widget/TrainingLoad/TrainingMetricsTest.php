<?php

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\TrainingMetrics;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

class TrainingMetricsTest extends TestCase
{
    public function testMetricsWhenEmpty(): void
    {
        $metrics = TrainingMetrics::create([]);

        $this->assertNull($metrics->getCurrentAtl());
        $this->assertNull($metrics->getCurrentCtl());
        $this->assertNull($metrics->getCurrentTsb());
        $this->assertNull($metrics->getWeeklyTrimp());
        $this->assertNull($metrics->getCurrentMonotony());
        $this->assertNull($metrics->getCurrentStrain());
        $this->assertNull($metrics->getCurrentAcRatio());
    }

    public function testStableLoadKeepsFitnessFatigueAndRiskSignalsFlat(): void
    {
        $metrics = TrainingMetrics::create($this->buildConstantIntensities(100, 7));

        $this->assertSame(100.0, $metrics->getCurrentAtl());
        $this->assertSame(100.0, $metrics->getCurrentCtl());
        $this->assertSame(0.0, $metrics->getCurrentTsb()?->getValue());
        $this->assertSame(700, $metrics->getWeeklyTrimp());
        $this->assertSame(0.0, $metrics->getCurrentMonotony());
        $this->assertSame(0.0, $metrics->getCurrentStrain());
        $this->assertSame(1.0, $metrics->getCurrentAcRatio()?->getValue());
    }

    public function testVariableLoadPatternProducesExpectedRoundedSeries(): void
    {
        $metrics = TrainingMetrics::create($this->buildTrailingWeekPattern());

        $this->assertSame([91.7, 91.4, 93.9], $metrics->getAtlValuesForXLastDays(3));
        $this->assertSame([82.3, 82.5, 83.1], $metrics->getCtlValuesForXLastDays(3));
        $this->assertSame([-9.4, -9.0, -10.8], $metrics->getTsbValuesForXLastDays(3));

        $this->assertSame(93.9, $metrics->getCurrentAtl());
        $this->assertSame(83.1, $metrics->getCurrentCtl());
        $this->assertSame(-10.8, $metrics->getCurrentTsb()?->getValue());
        $this->assertSame(700, $metrics->getWeeklyTrimp());
        $this->assertSame(7.64, $metrics->getCurrentMonotony());
        $this->assertSame(5346.0, $metrics->getCurrentStrain());
        $this->assertSame(1.13, $metrics->getCurrentAcRatio()?->getValue());
    }

    public function testMonotonyAndStrainStayUnavailableUntilAFullWeekExists(): void
    {
        $metrics = TrainingMetrics::create($this->buildConstantIntensities(100, 6));

        $this->assertNull($metrics->getWeeklyTrimp());
        $this->assertNull($metrics->getCurrentMonotony());
        $this->assertNull($metrics->getCurrentStrain());
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
