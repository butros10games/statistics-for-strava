<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

enum ActivityTypeRecoveryFingerprintProfile: string
{
    case BOUNCES_BACK = 'bouncesBack';
    case MIXED_RESPONSE = 'mixedResponse';
    case NEEDS_BUFFER = 'needsBuffer';

    public function getLabel(): string
    {
        return match ($this) {
            self::BOUNCES_BACK => 'Bounces back well',
            self::MIXED_RESPONSE => 'Mixed response',
            self::NEEDS_BUFFER => 'Needs recovery buffer',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::BOUNCES_BACK => 'This activity type usually lands well for you the next day.',
            self::MIXED_RESPONSE => 'This activity type shows a mixed next-day recovery signature.',
            self::NEEDS_BUFFER => 'This activity type tends to cost you more recovery the next day.',
        };
    }

    public function getPillColors(): string
    {
        return match ($this) {
            self::BOUNCES_BACK => 'bg-green-100 text-green-800',
            self::MIXED_RESPONSE => 'bg-gray-100 text-gray-700',
            self::NEEDS_BUFFER => 'bg-amber-100 text-amber-900',
        };
    }
}