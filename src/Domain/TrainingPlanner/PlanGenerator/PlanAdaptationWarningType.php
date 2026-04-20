<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

enum PlanAdaptationWarningType: string
{
    case PLAN_TOO_SHORT = 'planTooShort';
    case PLAN_TOO_LONG = 'planTooLong';
    case B_RACE_NEAR_A_RACE = 'bRaceNearARace';
    case B_RACE_IN_TAPER = 'bRaceInTaper';
    case C_RACE_IN_PEAK = 'cRaceInPeak';
    case MULTIPLE_A_RACES = 'multipleARaces';
    case BLOCK_OVERLAP = 'blockOverlap';
    case INSUFFICIENT_BASE = 'insufficientBase';
    case HIGH_SESSION_DENSITY = 'highSessionDensity';
}
