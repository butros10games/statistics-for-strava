<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class PlannedSessionLoadEstimate
{
    private function __construct(
        private PlannedSession $plannedSession,
        private float $estimatedLoad,
        private PlannedSessionEstimationSource $estimationSource,
    ) {
    }

    public static function create(
        PlannedSession $plannedSession,
        float $estimatedLoad,
        PlannedSessionEstimationSource $estimationSource,
    ): self {
        return new self(
            plannedSession: $plannedSession,
            estimatedLoad: round(max(0.0, $estimatedLoad), 1),
            estimationSource: $estimationSource,
        );
    }

    public function getPlannedSession(): PlannedSession
    {
        return $this->plannedSession;
    }

    public function getDay(): SerializableDateTime
    {
        return $this->plannedSession->getDay();
    }

    public function getActivityType(): ActivityType
    {
        return $this->plannedSession->getActivityType();
    }

    public function getTitle(): ?string
    {
        return $this->plannedSession->getTitle();
    }

    public function getEstimatedLoad(): float
    {
        return $this->estimatedLoad;
    }

    public function getEstimationSource(): PlannedSessionEstimationSource
    {
        return $this->estimationSource;
    }
}
