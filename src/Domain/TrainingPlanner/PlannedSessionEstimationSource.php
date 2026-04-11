<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionEstimationSource: string
{
    case UNKNOWN = 'unknown';
    case MANUAL_TARGET_LOAD = 'manualTargetLoad';
    case DURATION_INTENSITY = 'durationIntensity';
    case WORKOUT_TARGETS = 'workoutTargets';
    case TEMPLATE = 'template';

    public function getLabel(): string
    {
        return match ($this) {
            self::UNKNOWN => 'Not estimated yet',
            self::MANUAL_TARGET_LOAD => 'Manual target load',
            self::DURATION_INTENSITY => 'Duration and intensity estimate',
            self::WORKOUT_TARGETS => 'Workout target estimate',
            self::TEMPLATE => 'Template activity',
        };
    }
}
