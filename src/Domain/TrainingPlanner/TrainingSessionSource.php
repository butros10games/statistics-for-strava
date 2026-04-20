<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum TrainingSessionSource: string
{
    case PLANNED_SESSION = 'plannedSession';
    case RESEARCH_LIBRARY = 'researchLibrary';
    case HISTORICAL_ACTIVITY = 'historicalActivity';
    case MANUAL = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::PLANNED_SESSION => 'Planned session',
            self::RESEARCH_LIBRARY => 'Research library',
            self::HISTORICAL_ACTIVITY => 'Historical activity',
            self::MANUAL => 'Manual',
        };
    }
}