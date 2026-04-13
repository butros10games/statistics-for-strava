<?php

declare(strict_types=1);

namespace App\Tests\Domain\Performance\PerformanceAnchor;

use App\Domain\Activity\ActivityType;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use PHPUnit\Framework\TestCase;

final class PerformanceAnchorTypeTest extends TestCase
{
    public function testItMapsSupportedActivityTypes(): void
    {
        self::assertSame(PerformanceAnchorType::CYCLING_THRESHOLD_POWER, PerformanceAnchorType::fromActivityType(ActivityType::RIDE));
        self::assertSame(PerformanceAnchorType::RUNNING_THRESHOLD_POWER, PerformanceAnchorType::fromActivityType(ActivityType::RUN));
    }

    public function testItThrowsForUnsupportedActivityTypes(): void
    {
        $this->expectExceptionObject(new \RuntimeException('ActivityType "Walk" does not support performance anchors'));

        PerformanceAnchorType::fromActivityType(ActivityType::WALK);
    }
}