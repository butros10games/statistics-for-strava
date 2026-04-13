<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RaceEventPriority: string implements TranslatableInterface
{
    case A = 'a';
    case B = 'b';
    case C = 'c';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::A => $translator->trans('A race', locale: $locale),
            self::B => $translator->trans('B race', locale: $locale),
            self::C => $translator->trans('C race', locale: $locale),
        };
    }
}
