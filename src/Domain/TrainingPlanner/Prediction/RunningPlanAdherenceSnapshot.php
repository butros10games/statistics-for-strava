<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

final readonly class RunningPlanAdherenceSnapshot
{
    public function __construct(
        private int $plannedRunSessionCount,
        private int $completedRunSessionCount,
        private int $plannedKeyRunSessionCount,
        private int $completedKeyRunSessionCount,
        private int $plannedLongRunCount,
        private int $completedLongRunCount,
        private int $plannedRunningMinutes,
        private int $completedRunningMinutes,
        private int $elapsedPlanWeeks,
    ) {
    }

    public function getPlannedRunSessionCount(): int
    {
        return $this->plannedRunSessionCount;
    }

    public function getCompletedRunSessionCount(): int
    {
        return $this->completedRunSessionCount;
    }

    public function getPlannedKeyRunSessionCount(): int
    {
        return $this->plannedKeyRunSessionCount;
    }

    public function getCompletedKeyRunSessionCount(): int
    {
        return $this->completedKeyRunSessionCount;
    }

    public function getPlannedLongRunCount(): int
    {
        return $this->plannedLongRunCount;
    }

    public function getCompletedLongRunCount(): int
    {
        return $this->completedLongRunCount;
    }

    public function getPlannedRunningMinutes(): int
    {
        return $this->plannedRunningMinutes;
    }

    public function getCompletedRunningMinutes(): int
    {
        return $this->completedRunningMinutes;
    }

    public function getElapsedPlanWeeks(): int
    {
        return $this->elapsedPlanWeeks;
    }

    public function hasMeasuredProgress(): bool
    {
        return $this->plannedRunSessionCount > 0;
    }

    public function getRunCompletionRatio(): float
    {
        if (!$this->hasMeasuredProgress()) {
            return 0.0;
        }

        return min(1.0, $this->completedRunSessionCount / max(1, $this->plannedRunSessionCount));
    }

    public function getKeyRunCompletionRatio(): float
    {
        if ($this->plannedKeyRunSessionCount <= 0) {
            return $this->getRunCompletionRatio();
        }

        return min(1.0, $this->completedKeyRunSessionCount / max(1, $this->plannedKeyRunSessionCount));
    }

    public function getLongRunCompletionRatio(): float
    {
        if ($this->plannedLongRunCount <= 0) {
            return $this->getRunCompletionRatio();
        }

        return min(1.0, $this->completedLongRunCount / max(1, $this->plannedLongRunCount));
    }

    public function getRunningDurationCompletionRatio(): float
    {
        if ($this->plannedRunningMinutes <= 0) {
            return $this->getRunCompletionRatio();
        }

        return min(1.0, $this->completedRunningMinutes / max(1, $this->plannedRunningMinutes));
    }

    public function getAdherenceScore(): float
    {
        if (!$this->hasMeasuredProgress()) {
            return 0.0;
        }

        $score = (0.4 * $this->getRunCompletionRatio())
            + (0.25 * $this->getKeyRunCompletionRatio())
            + (0.2 * $this->getLongRunCompletionRatio())
            + (0.15 * $this->getRunningDurationCompletionRatio());

        return max(0.35, min(1.0, $score));
    }
}