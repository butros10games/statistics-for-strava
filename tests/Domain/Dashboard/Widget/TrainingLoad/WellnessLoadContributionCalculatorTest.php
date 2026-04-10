<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\WellnessLoadContributionCalculator;
use PHPUnit\Framework\TestCase;

final class WellnessLoadContributionCalculatorTest extends TestCase
{
    private WellnessLoadContributionCalculator $calculator;

    public function testItReturnsZeroWhenNoSignalsExist(): void
    {
        $this->assertSame(0, $this->calculator->calculateForRecord([
            'day' => '2026-04-07',
            'stepsCount' => null,
            'sleepDurationInSeconds' => null,
            'sleepScore' => null,
            'hrv' => null,
        ]));
    }

    public function testItAddsSmallLifestyleLoadForHighSteps(): void
    {
        $this->assertSame(4, $this->calculator->calculateForRecord([
            'day' => '2026-04-07',
            'stepsCount' => 12000,
            'sleepDurationInSeconds' => null,
            'sleepScore' => null,
            'hrv' => null,
        ]));
    }

    public function testItAddsLoadForShortSleepAndLowSleepScore(): void
    {
        $this->assertSame(8, $this->calculator->calculateForRecord([
            'day' => '2026-04-07',
            'stepsCount' => 5000,
            'sleepDurationInSeconds' => 19800,
            'sleepScore' => 60,
            'hrv' => 45.0,
        ]));
    }

    public function testItKeepsContributionWithinSafeRangeWithoutBaseline(): void
    {
        $this->assertSame(18, $this->calculator->calculateForRecord([
            'day' => '2026-04-07',
            'stepsCount' => 32000,
            'sleepDurationInSeconds' => 10800,
            'sleepScore' => 20,
            'hrv' => 35.0,
        ]));
    }

    public function testItCanMapRecordsByDay(): void
    {
        $this->assertSame([
            '2026-04-06' => 0,
            '2026-04-07' => 8,
        ], $this->calculator->calculateForRecords([
            [
                'day' => '2026-04-06',
                'stepsCount' => 4000,
                'sleepDurationInSeconds' => 28800,
                'sleepScore' => 82,
                'hrv' => 55.0,
            ],
            [
                'day' => '2026-04-07',
                'stepsCount' => 10000,
                'sleepDurationInSeconds' => 21600,
                'sleepScore' => 68,
                'hrv' => 49.0,
            ],
        ]));
    }

    public function testItUsesIndividualizedBaselineForHabitualStepsAndSleep(): void
    {
        $contributions = $this->calculator->calculateForRecords([
            [
                'day' => '2026-04-04',
                'stepsCount' => 12000,
                'sleepDurationInSeconds' => 28800,
                'sleepScore' => 82,
                'hrv' => 58.0,
            ],
            [
                'day' => '2026-04-05',
                'stepsCount' => 11800,
                'sleepDurationInSeconds' => 29100,
                'sleepScore' => 81,
                'hrv' => 57.5,
            ],
            [
                'day' => '2026-04-06',
                'stepsCount' => 12200,
                'sleepDurationInSeconds' => 28500,
                'sleepScore' => 80,
                'hrv' => 58.5,
            ],
            [
                'day' => '2026-04-07',
                'stepsCount' => 12100,
                'sleepDurationInSeconds' => 28800,
                'sleepScore' => 82,
                'hrv' => 58.0,
            ],
        ]);

        $this->assertLessThanOrEqual(1, $contributions['2026-04-07']);
    }

    public function testItCanUseHistoricalHrvDataWhenEnoughBaselineExists(): void
    {
        $this->assertSame([
            '2026-04-04' => 0,
            '2026-04-05' => 0,
            '2026-04-06' => 0,
            '2026-04-07' => 6,
        ], $this->calculator->calculateForRecords([
            [
                'day' => '2026-04-04',
                'stepsCount' => 4000,
                'sleepDurationInSeconds' => 28800,
                'sleepScore' => 82,
                'hrv' => 58.0,
            ],
            [
                'day' => '2026-04-05',
                'stepsCount' => 4500,
                'sleepDurationInSeconds' => 28200,
                'sleepScore' => 80,
                'hrv' => 57.0,
            ],
            [
                'day' => '2026-04-06',
                'stepsCount' => 5000,
                'sleepDurationInSeconds' => 27900,
                'sleepScore' => 79,
                'hrv' => 56.0,
            ],
            [
                'day' => '2026-04-07',
                'stepsCount' => 5200,
                'sleepDurationInSeconds' => 28200,
                'sleepScore' => 78,
                'hrv' => 45.0,
            ],
        ]));
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->calculator = new WellnessLoadContributionCalculator();
    }
}