<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class TrainingPlanId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'training-plan-';
    }
}