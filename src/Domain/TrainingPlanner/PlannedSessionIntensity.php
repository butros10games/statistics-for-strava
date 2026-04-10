<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionIntensity: string
{
    case EASY = 'easy';
    case MODERATE = 'moderate';
    case HARD = 'hard';
    case RACE = 'race';

    public function getLabel(): string
    {
        return match ($this) {
            self::EASY => 'Easy',
            self::MODERATE => 'Moderate',
            self::HARD => 'Hard',
            self::RACE => 'Race effort',
        };
    }
}
