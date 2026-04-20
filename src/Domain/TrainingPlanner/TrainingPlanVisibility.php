<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum TrainingPlanVisibility: string
{
    case PRIVATE = 'private';
    case FOLLOWERS = 'followers';
    case FRIENDS = 'friends';
}
