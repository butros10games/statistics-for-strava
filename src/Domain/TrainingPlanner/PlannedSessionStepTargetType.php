<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionStepTargetType: string
{
    case TIME = 'time';
    case DISTANCE = 'distance';
    case HEART_RATE = 'heartRate';

    public function getLabel(): string
    {
        return match ($this) {
            self::TIME => 'Time set',
            self::DISTANCE => 'Distance set',
            self::HEART_RATE => 'Heart-rate set',
        };
    }
}
