<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Dashboard\Widget\TrainingLoad\RecoveryCheckInLoadContributionCalculator;
use PHPUnit\Framework\TestCase;

final class RecoveryCheckInLoadContributionCalculatorTest extends TestCase
{
    public function testItCreatesLowLoadForPositiveCheckIn(): void
    {
        $calculator = new RecoveryCheckInLoadContributionCalculator();

        self::assertSame(0, $calculator->calculateForRecord([
            'day' => '2026-04-07',
            'fatigue' => 1,
            'soreness' => 1,
            'stress' => 1,
            'motivation' => 5,
            'sleepQuality' => 5,
        ]));
    }

    public function testItCreatesHigherLoadForSuppressedCheckIn(): void
    {
        $calculator = new RecoveryCheckInLoadContributionCalculator();

        self::assertSame(12, $calculator->calculateForRecord([
            'day' => '2026-04-07',
            'fatigue' => 5,
            'soreness' => 5,
            'stress' => 5,
            'motivation' => 1,
            'sleepQuality' => 1,
        ]));
    }
}