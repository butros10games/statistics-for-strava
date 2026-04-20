<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RunningWorkoutTargetMode: string implements TranslatableInterface
{
    case TIME = 'time';
    case DISTANCE = 'distance';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(match ($this) {
            self::TIME => 'Time-based',
            self::DISTANCE => 'Distance-based',
        }, locale: $locale);
    }
}