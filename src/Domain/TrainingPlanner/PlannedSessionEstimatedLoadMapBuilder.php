<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class PlannedSessionEstimatedLoadMapBuilder
{
    public function __construct(
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
    ) {
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return array<string, float|null>
     */
    public function build(array $plannedSessions): array
    {
        $estimatesById = [];

        foreach ($plannedSessions as $plannedSession) {
            $estimate = $this->plannedSessionLoadEstimator->estimate($plannedSession);
            $estimatesById[(string) $plannedSession->getId()] = $estimate?->getEstimatedLoad();
        }

        return $estimatesById;
    }
}