<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class TrainingSessionId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'training-session-';
    }
}
