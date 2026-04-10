<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class PlannedSessionId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'planned-session-';
    }
}
