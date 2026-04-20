<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TrainingPlanType: string implements TranslatableInterface
{
    case RACE = 'race';
    case TRAINING = 'training';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::RACE => $translator->trans('Race plan', locale: $locale),
            self::TRAINING => $translator->trans('Training plan', locale: $locale),
        };
    }
}