<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TrainingBlockStyle: string implements TranslatableInterface
{
    case BALANCED = 'balanced';
    case SPEED_ENDURANCE = 'speedEndurance';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(match ($this) {
            self::BALANCED => 'Balanced build',
            self::SPEED_ENDURANCE => 'Speed-endurance build',
        }, locale: $locale);
    }
}
