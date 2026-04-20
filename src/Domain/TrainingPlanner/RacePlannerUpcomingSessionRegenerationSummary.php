<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class RacePlannerUpcomingSessionRegenerationSummary
{
    public function __construct(
        private int $removedSessionCount,
        private int $createdSessionCount,
    ) {
    }

    public function getRemovedSessionCount(): int
    {
        return $this->removedSessionCount;
    }

    public function getCreatedSessionCount(): int
    {
        return $this->createdSessionCount;
    }

    public function hasChanges(): bool
    {
        return $this->removedSessionCount > 0 || $this->createdSessionCount > 0;
    }
}