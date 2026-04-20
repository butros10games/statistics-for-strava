<?php

declare(strict_types=1);

namespace App\Infrastructure\KeyValue;

enum Key: string
{
    case ATHLETE = 'athlete';
    case THEME = 'theme';
    case RACE_PLANNER_PLAN_START_DAY = 'race_planner_plan_start_day';
}
