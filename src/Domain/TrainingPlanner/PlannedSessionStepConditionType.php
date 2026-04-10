<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionStepConditionType: string
{
    case HOLD_TARGET = 'holdTarget';
    case UNTIL_BELOW = 'untilBelow';
    case UNTIL_ABOVE = 'untilAbove';
    case LAP_BUTTON = 'lapButton';

    public function getLabel(): string
    {
        return match ($this) {
            self::HOLD_TARGET => 'Hold target',
            self::UNTIL_BELOW => 'Until below threshold',
            self::UNTIL_ABOVE => 'Until above threshold',
            self::LAP_BUTTON => 'Until button press',
        };
    }
}
