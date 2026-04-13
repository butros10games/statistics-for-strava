<?php

declare(strict_types=1);

namespace App\Tests\Domain\Performance\PerformanceAnchor;

use App\Domain\Performance\PerformanceAnchor\PerformanceAnchor;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorConfidence;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorSource;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Infrastructure\ValueObject\Measurement\Mass\Kilogram;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class PerformanceAnchorTest extends TestCase
{
    public function testItCanCalculateRelativePowerWhenWeightIsAvailable(): void
    {
        $anchor = PerformanceAnchor::fromState(
            setOn: SerializableDateTime::fromString('2026-04-01'),
            type: PerformanceAnchorType::CYCLING_THRESHOLD_POWER,
            value: 320.0,
            source: PerformanceAnchorSource::POWER_DURATION_MODEL,
            confidence: PerformanceAnchorConfidence::HIGH,
            sampleSize: 9,
        )->withAthleteWeight(Kilogram::from(80));

        self::assertSame(4.0, $anchor->getRelativeValue());
    }

    public function testItDoesNotExposeRelativeValueForNonPowerAnchors(): void
    {
        $anchor = PerformanceAnchor::fromState(
            setOn: SerializableDateTime::fromString('2026-04-01'),
            type: PerformanceAnchorType::SWIMMING_CRITICAL_SPEED,
            value: 1.45,
        )->withAthleteWeight(Kilogram::from(80));

        self::assertNull($anchor->getRelativeValue());
    }
}