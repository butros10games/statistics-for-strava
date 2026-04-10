<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

use App\Domain\Activity\ActivityType;

final readonly class ActivityTypeRecoveryFingerprint
{
    public function __construct(
        private ActivityType $activityType,
        private int $sampleSize,
        private float $averageLoad,
        private ?float $nextDayHrvDelta,
        private ?float $nextDaySleepScoreDelta,
        private ?float $nextDayFatigueDelta,
        private ActivityTypeRecoveryFingerprintProfile $profile,
    ) {
    }

    public function getActivityType(): ActivityType
    {
        return $this->activityType;
    }

    public function getSampleSize(): int
    {
        return $this->sampleSize;
    }

    public function getAverageLoad(): float
    {
        return $this->averageLoad;
    }

    public function getNextDayHrvDelta(): ?float
    {
        return $this->nextDayHrvDelta;
    }

    public function getNextDaySleepScoreDelta(): ?float
    {
        return $this->nextDaySleepScoreDelta;
    }

    public function getNextDayFatigueDelta(): ?float
    {
        return $this->nextDayFatigueDelta;
    }

    public function getProfile(): ActivityTypeRecoveryFingerprintProfile
    {
        return $this->profile;
    }

    public function getConfidenceLabel(): string
    {
        return match (true) {
            $this->sampleSize >= 6 => 'Higher confidence',
            default => 'Emerging signal',
        };
    }

    public function getConfidencePillColors(): string
    {
        return match (true) {
            $this->sampleSize >= 6 => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-700',
        };
    }
}