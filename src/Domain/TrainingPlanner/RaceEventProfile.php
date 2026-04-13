<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RaceEventProfile: string implements TranslatableInterface
{
    case SPRINT_TRIATHLON = 'sprintTriathlon';
    case OLYMPIC_TRIATHLON = 'olympicTriathlon';
    case HALF_DISTANCE_TRIATHLON = 'halfDistanceTriathlon';
    case FULL_DISTANCE_TRIATHLON = 'fullDistanceTriathlon';
    case DUATHLON = 'duathlon';
    case AQUATHLON = 'aquathlon';
    case SWIM = 'swim';
    case OPEN_WATER_SWIM = 'openWaterSwim';
    case RIDE = 'ride';
    case TIME_TRIAL = 'timeTrial';
    case GRAVEL_RACE = 'gravelRace';
    case RUN_5K = 'run5k';
    case RUN_10K = 'run10k';
    case HALF_MARATHON = 'halfMarathon';
    case MARATHON = 'marathon';
    case RUN = 'run';
    case CUSTOM = 'custom';

    public function getFamily(): RaceEventFamily
    {
        return match ($this) {
            self::SPRINT_TRIATHLON,
            self::OLYMPIC_TRIATHLON,
            self::HALF_DISTANCE_TRIATHLON,
            self::FULL_DISTANCE_TRIATHLON => RaceEventFamily::TRIATHLON,
            self::DUATHLON,
            self::AQUATHLON => RaceEventFamily::MULTISPORT,
            self::SWIM,
            self::OPEN_WATER_SWIM => RaceEventFamily::SWIM,
            self::RIDE,
            self::TIME_TRIAL,
            self::GRAVEL_RACE => RaceEventFamily::RIDE,
            self::RUN_5K,
            self::RUN_10K,
            self::HALF_MARATHON,
            self::MARATHON,
            self::RUN => RaceEventFamily::RUN,
            self::CUSTOM => RaceEventFamily::OTHER,
        };
    }

    public function isCompatibleWithFamily(RaceEventFamily $family): bool
    {
        return $this->getFamily() === $family;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::SPRINT_TRIATHLON => $translator->trans('Sprint triathlon', locale: $locale),
            self::OLYMPIC_TRIATHLON => $translator->trans('Olympic triathlon', locale: $locale),
            self::HALF_DISTANCE_TRIATHLON => $translator->trans('70.3 triathlon', locale: $locale),
            self::FULL_DISTANCE_TRIATHLON => $translator->trans('Full-distance triathlon', locale: $locale),
            self::DUATHLON => $translator->trans('Duathlon', locale: $locale),
            self::AQUATHLON => $translator->trans('Aquathlon', locale: $locale),
            self::SWIM => $translator->trans('Pool swim race', locale: $locale),
            self::OPEN_WATER_SWIM => $translator->trans('Open-water swim race', locale: $locale),
            self::RIDE => $translator->trans('Bike race', locale: $locale),
            self::TIME_TRIAL => $translator->trans('Time trial', locale: $locale),
            self::GRAVEL_RACE => $translator->trans('Gravel race', locale: $locale),
            self::RUN_5K => $translator->trans('5K run', locale: $locale),
            self::RUN_10K => $translator->trans('10K run', locale: $locale),
            self::HALF_MARATHON => $translator->trans('Half marathon (21.1K)', locale: $locale),
            self::MARATHON => $translator->trans('Marathon (42.2K)', locale: $locale),
            self::RUN => $translator->trans('Run race', locale: $locale),
            self::CUSTOM => $translator->trans('Custom event', locale: $locale),
        };
    }
}
