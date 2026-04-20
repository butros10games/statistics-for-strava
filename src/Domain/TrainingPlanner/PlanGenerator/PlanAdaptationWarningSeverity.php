<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

enum PlanAdaptationWarningSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
}
