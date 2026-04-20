<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum TrainingSessionObjective: string
{
    case ENDURANCE = 'endurance';
    case THRESHOLD = 'threshold';
    case HIGH_INTENSITY = 'highIntensity';
    case RACE_SPECIFIC = 'raceSpecific';
    case RECOVERY = 'recovery';
    case TECHNIQUE = 'technique';

    public function getLabel(): string
    {
        return match ($this) {
            self::ENDURANCE => 'Endurance',
            self::THRESHOLD => 'Threshold',
            self::HIGH_INTENSITY => 'High intensity',
            self::RACE_SPECIFIC => 'Race specific',
            self::RECOVERY => 'Recovery',
            self::TECHNIQUE => 'Technique',
        };
    }
}