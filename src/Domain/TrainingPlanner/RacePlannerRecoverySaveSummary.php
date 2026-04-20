<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class RacePlannerRecoverySaveSummary
{
    public function __construct(
        private int $missingRecoveryBlockCount,
        private int $missingRecoverySessionCount,
    ) {
    }

    public function getMissingRecoveryBlockCount(): int
    {
        return $this->missingRecoveryBlockCount;
    }

    public function getMissingRecoverySessionCount(): int
    {
        return $this->missingRecoverySessionCount;
    }

    public function hasAnythingToSave(): bool
    {
        return $this->missingRecoveryBlockCount > 0 || $this->missingRecoverySessionCount > 0;
    }
}