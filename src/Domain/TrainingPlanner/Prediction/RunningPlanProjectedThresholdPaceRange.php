<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

final readonly class RunningPlanProjectedThresholdPaceRange
{
    private int $optimisticPaceInSeconds;
    private int $expectedPaceInSeconds;
    private int $conservativePaceInSeconds;

    public function __construct(
        int $optimisticPaceInSeconds,
        int $expectedPaceInSeconds,
        int $conservativePaceInSeconds,
    ) {
        $this->expectedPaceInSeconds = max(150, min(600, $expectedPaceInSeconds));
        $this->optimisticPaceInSeconds = max(150, min($this->expectedPaceInSeconds, $optimisticPaceInSeconds));
        $this->conservativePaceInSeconds = min(600, max($this->expectedPaceInSeconds, $conservativePaceInSeconds));
    }

    public function getOptimisticPaceInSeconds(): int
    {
        return $this->optimisticPaceInSeconds;
    }

    public function getExpectedPaceInSeconds(): int
    {
        return $this->expectedPaceInSeconds;
    }

    public function getConservativePaceInSeconds(): int
    {
        return $this->conservativePaceInSeconds;
    }

    public function getSpreadInSeconds(): int
    {
        return $this->conservativePaceInSeconds - $this->optimisticPaceInSeconds;
    }
}
