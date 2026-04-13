<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RaceEventType: string implements TranslatableInterface
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

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->toProfile()->trans($translator, $locale);
    }

    public function getGroupLabel(): string
    {
        return match ($this->toFamily()) {
            RaceEventFamily::TRIATHLON => 'Triathlon',
            RaceEventFamily::MULTISPORT => 'Multisport',
            RaceEventFamily::RUN => 'Run',
            RaceEventFamily::RIDE => 'Ride',
            RaceEventFamily::SWIM => 'Swim',
            RaceEventFamily::OTHER => 'Other',
        };
    }

    public function toFamily(): RaceEventFamily
    {
        return $this->toProfile()->getFamily();
    }

    public function toProfile(): RaceEventProfile
    {
        return RaceEventProfile::from($this->value);
    }

    public static function fromProfile(RaceEventProfile $profile): self
    {
        return self::from($profile->value);
    }
}
