<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RaceEventFamily: string implements TranslatableInterface
{
    case TRIATHLON = 'triathlon';
    case MULTISPORT = 'multisport';
    case RUN = 'run';
    case RIDE = 'ride';
    case SWIM = 'swim';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::TRIATHLON => 'Triathlon',
            self::MULTISPORT => 'Multisport',
            self::RUN => 'Run',
            self::RIDE => 'Ride',
            self::SWIM => 'Swim',
            self::OTHER => 'Other',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->getLabel(), locale: $locale);
    }
}
