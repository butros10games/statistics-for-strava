<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionSource: string
{
    case MANUAL = 'manual';
    case TRAINING_PLAN = 'trainingPlan';
    case RACE_PLANNER = 'racePlanner';

    public function getLabel(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::TRAINING_PLAN => 'Training plan',
            self::RACE_PLANNER => 'Race planner',
        };
    }
}
