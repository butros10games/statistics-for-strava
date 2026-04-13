<?php

declare(strict_types=1);

namespace App\Domain\Performance\PerformanceAnchor;

use App\Domain\Activity\ActivityType;

enum PerformanceAnchorType: string
{
    case CYCLING_THRESHOLD_POWER = 'cycling_threshold_power';
    case RUNNING_THRESHOLD_POWER = 'running_threshold_power';
    case SWIMMING_CRITICAL_SPEED = 'swimming_critical_speed';

    public static function fromActivityType(ActivityType $activityType): self
    {
        return match ($activityType) {
            ActivityType::RIDE => self::CYCLING_THRESHOLD_POWER,
            ActivityType::RUN => self::RUNNING_THRESHOLD_POWER,
            default => throw new \RuntimeException(sprintf('ActivityType "%s" does not support performance anchors', $activityType->value)),
        };
    }

    public function supportsActivityType(ActivityType $activityType): bool
    {
        return match ($activityType) {
            ActivityType::RIDE => self::CYCLING_THRESHOLD_POWER === $this,
            ActivityType::RUN => self::RUNNING_THRESHOLD_POWER === $this,
            default => false,
        };
    }

    public function isPowerBased(): bool
    {
        return match ($this) {
            self::CYCLING_THRESHOLD_POWER, self::RUNNING_THRESHOLD_POWER => true,
            self::SWIMMING_CRITICAL_SPEED => false,
        };
    }

    public function getUnit(): string
    {
        return match ($this) {
            self::CYCLING_THRESHOLD_POWER, self::RUNNING_THRESHOLD_POWER => 'W',
            self::SWIMMING_CRITICAL_SPEED => 'm/s',
        };
    }

    public function getLegacyKey(): string
    {
        return match ($this) {
            self::CYCLING_THRESHOLD_POWER => 'cycling',
            self::RUNNING_THRESHOLD_POWER => 'running',
            self::SWIMMING_CRITICAL_SPEED => 'swimming',
        };
    }
}