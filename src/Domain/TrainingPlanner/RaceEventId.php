<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Identifier\Identifier;

final readonly class RaceEventId extends Identifier
{
    public static function getPrefix(): string
    {
        return 'race-event-';
    }
}
