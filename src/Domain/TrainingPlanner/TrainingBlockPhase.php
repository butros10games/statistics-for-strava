<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum TrainingBlockPhase: string implements TranslatableInterface
{
    case BASE = 'base';
    case BUILD = 'build';
    case PEAK = 'peak';
    case TAPER = 'taper';
    case RECOVERY = 'recovery';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::BASE => $translator->trans('Base', locale: $locale),
            self::BUILD => $translator->trans('Build', locale: $locale),
            self::PEAK => $translator->trans('Peak', locale: $locale),
            self::TAPER => $translator->trans('Taper', locale: $locale),
            self::RECOVERY => $translator->trans('Recovery', locale: $locale),
        };
    }
}
