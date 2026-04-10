<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

enum PlannedSessionLinkStatus: string
{
    case UNLINKED = 'unlinked';
    case LINKED = 'linked';
    case SUGGESTED = 'suggested';

    public function getLabel(): string
    {
        return match ($this) {
            self::UNLINKED => 'Unlinked',
            self::LINKED => 'Linked',
            self::SUGGESTED => 'Suggested match',
        };
    }
}
