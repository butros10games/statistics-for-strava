<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

enum PlanAdaptationRecommendationType: string
{
    case ADD_BLOCK = 'addBlock';
    case MODIFY_BLOCK = 'modifyBlock';
    case REMOVE_BLOCK = 'removeBlock';
    case REDUCE_LOAD = 'reduceLoad';
    case INCREASE_LOAD = 'increaseLoad';
    case SHIFT_TAPER = 'shiftTaper';
    case INSERT_RECOVERY = 'insertRecovery';
    case ADJUST_FOR_B_RACE = 'adjustForBRace';
    case EXTEND_BASE = 'extendBase';
}
