<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TrainingPlanDiscipline: string implements TranslatableInterface
{
    case TRIATHLON = 'triathlon';
    case RUNNING = 'running';
    case CYCLING = 'cycling';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::TRIATHLON => $translator->trans('Triathlon', locale: $locale),
            self::RUNNING => $translator->trans('Running', locale: $locale),
            self::CYCLING => $translator->trans('Cycling', locale: $locale),
        };
    }
}
