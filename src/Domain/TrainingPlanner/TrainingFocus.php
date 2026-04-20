<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TrainingFocus: string implements TranslatableInterface
{
    case RUN = 'run';
    case BIKE = 'bike';
    case SWIM = 'swim';
    case GENERAL = 'general';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(match ($this) {
            self::RUN => 'Run focus',
            self::BIKE => 'Bike focus',
            self::SWIM => 'Swim focus',
            self::GENERAL => 'General fitness',
        }, locale: $locale);
    }
}
