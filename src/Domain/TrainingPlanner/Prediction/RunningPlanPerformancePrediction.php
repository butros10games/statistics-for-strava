<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

final readonly class RunningPlanPerformancePrediction
{
    /**
     * @param list<RunningRaceBenchmarkPrediction> $benchmarkPredictions
     * @param array<string, int> $projectedThresholdPaceByWeekStartDate
     */
    public function __construct(
        private int $currentThresholdPaceInSeconds,
        private int $projectedThresholdPaceInSeconds,
        private ?int $trajectoryThresholdPaceInSeconds,
        private string $confidenceLabel,
        private array $benchmarkPredictions,
        private array $projectedThresholdPaceByWeekStartDate,
        private ?RunningPlanAdherenceSnapshot $adherenceSnapshot,
    ) {
    }

    public function getCurrentThresholdPaceInSeconds(): int
    {
        return $this->currentThresholdPaceInSeconds;
    }

    public function getProjectedThresholdPaceInSeconds(): int
    {
        return $this->projectedThresholdPaceInSeconds;
    }

    public function getTrajectoryThresholdPaceInSeconds(): ?int
    {
        return $this->trajectoryThresholdPaceInSeconds;
    }

    public function getProjectedGainInSecondsPerKm(): int
    {
        return max(0, $this->currentThresholdPaceInSeconds - $this->projectedThresholdPaceInSeconds);
    }

    public function getTrajectoryGainInSecondsPerKm(): ?int
    {
        if (null === $this->trajectoryThresholdPaceInSeconds) {
            return null;
        }

        return max(0, $this->currentThresholdPaceInSeconds - $this->trajectoryThresholdPaceInSeconds);
    }

    public function getConfidenceLabel(): string
    {
        return $this->confidenceLabel;
    }

    /**
     * @return list<RunningRaceBenchmarkPrediction>
     */
    public function getBenchmarkPredictions(): array
    {
        return $this->benchmarkPredictions;
    }

    /**
     * @return array<string, int>
     */
    public function getProjectedThresholdPaceByWeekStartDate(): array
    {
        return $this->projectedThresholdPaceByWeekStartDate;
    }

    public function getAdherenceSnapshot(): ?RunningPlanAdherenceSnapshot
    {
        return $this->adherenceSnapshot;
    }
}