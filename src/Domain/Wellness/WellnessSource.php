<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

enum WellnessSource: string
{
    case GARMIN = 'garmin';
}