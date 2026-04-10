<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionStepType: string
{
    case WARMUP = 'warmup';
    case INTERVAL = 'interval';
    case REPEAT_BLOCK = 'repeatBlock';
    case STEADY = 'steady';
    case RECOVERY = 'recovery';
    case COOLDOWN = 'cooldown';

    public function getLabel(): string
    {
        return match ($this) {
            self::WARMUP => 'Warm-up',
            self::INTERVAL => 'Interval',
            self::REPEAT_BLOCK => 'Repeat block',
            self::STEADY => 'Steady',
            self::RECOVERY => 'Recovery',
            self::COOLDOWN => 'Cool-down',
        };
    }

    public function isContainer(): bool
    {
        return self::REPEAT_BLOCK === $this;
    }
}
