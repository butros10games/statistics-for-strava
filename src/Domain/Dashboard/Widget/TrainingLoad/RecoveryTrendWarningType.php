<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

enum RecoveryTrendWarningType: string
{
    case ACCUMULATING_FATIGUE = 'accumulatingFatigue';
    case MONOTONY_RISK = 'monotonyRisk';
    case SUPPRESSED_RECOVERY = 'suppressedRecovery';
    case RECOVERY_REBOUND = 'recoveryRebound';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACCUMULATING_FATIGUE => 'Accumulating fatigue',
            self::MONOTONY_RISK => 'Monotony risk',
            self::SUPPRESSED_RECOVERY => 'Suppressed recovery',
            self::RECOVERY_REBOUND => 'Recovery rebound',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ACCUMULATING_FATIGUE => 'Your recent load is rising faster than your recovery signals are improving. A few easier days may help absorb the work.',
            self::MONOTONY_RISK => 'Training variety has been low lately. Repeating similar stress day after day can amplify fatigue even when total load looks manageable.',
            self::SUPPRESSED_RECOVERY => 'Recent wellness and check-in signals suggest recovery is lagging behind the load you are carrying right now.',
            self::RECOVERY_REBOUND => 'Your recent recovery signals are moving in the right direction. The last few days look more absorbable than the week before.',
        };
    }

    public function getPillColors(): string
    {
        return match ($this) {
            self::ACCUMULATING_FATIGUE => 'bg-orange-100 text-orange-800',
            self::MONOTONY_RISK => 'bg-amber-100 text-amber-800',
            self::SUPPRESSED_RECOVERY => 'bg-red-100 text-red-800',
            self::RECOVERY_REBOUND => 'bg-emerald-100 text-emerald-800',
        };
    }
}