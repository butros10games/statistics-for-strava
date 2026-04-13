<?php

declare(strict_types=1);

namespace App\Domain\Performance\PerformanceAnchor;

enum PerformanceAnchorConfidence: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public static function fromSampleSize(int $sampleSize): self
    {
        return match (true) {
            $sampleSize >= 8 => self::HIGH,
            $sampleSize >= 4 => self::MEDIUM,
            default => self::LOW,
        };
    }
}