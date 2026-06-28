<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Prediction;

final readonly class RunningPlanPerformancePrediction
{
    public const string DEFAULT_MODEL_VERSION = 'running-plan-performance-threshold-v1.1.0';

    private string $modelVersion;
    private int $confidenceScore;
    /** @var list<RunningPlanConfidenceFactor> */
    private array $confidenceFactors;
    private RunningPlanProjectedThresholdPaceRange $projectedThresholdPaceRange;

    /**
     * @param list<RunningRaceBenchmarkPrediction> $benchmarkPredictions
     * @param array<string, int>                   $projectedThresholdPaceByWeekStartDate
     * @param list<RunningPlanConfidenceFactor>    $confidenceFactors
     */
    public function __construct(
        private int $currentThresholdPaceInSeconds,
        private int $projectedThresholdPaceInSeconds,
        private ?int $trajectoryThresholdPaceInSeconds,
        private string $confidenceLabel,
        private array $benchmarkPredictions,
        private array $projectedThresholdPaceByWeekStartDate,
        private ?RunningPlanAdherenceSnapshot $adherenceSnapshot,
        ?string $modelVersion = null,
        ?int $confidenceScore = null,
        array $confidenceFactors = [],
        ?RunningPlanProjectedThresholdPaceRange $projectedThresholdPaceRange = null,
    ) {
        $this->modelVersion = $modelVersion ?? self::DEFAULT_MODEL_VERSION;
        $this->confidenceScore = max(0, min(100, $confidenceScore ?? $this->resolveDefaultConfidenceScore($this->confidenceLabel)));
        $this->confidenceFactors = array_values($confidenceFactors);
        $this->projectedThresholdPaceRange = $projectedThresholdPaceRange ?? new RunningPlanProjectedThresholdPaceRange(
            optimisticPaceInSeconds: $this->projectedThresholdPaceInSeconds,
            expectedPaceInSeconds: $this->projectedThresholdPaceInSeconds,
            conservativePaceInSeconds: $this->projectedThresholdPaceInSeconds,
        );
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

    public function getModelVersion(): string
    {
        return $this->modelVersion;
    }

    public function getConfidenceScore(): int
    {
        return $this->confidenceScore;
    }

    /**
     * @return list<RunningPlanConfidenceFactor>
     */
    public function getConfidenceFactors(): array
    {
        return $this->confidenceFactors;
    }

    /**
     * @return list<string>
     */
    public function getConfidenceReasons(): array
    {
        return array_map(
            fn (RunningPlanConfidenceFactor $factor): string => $factor->getReason(),
            $this->confidenceFactors,
        );
    }

    public function getProjectedThresholdPaceRange(): RunningPlanProjectedThresholdPaceRange
    {
        return $this->projectedThresholdPaceRange;
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

    private function resolveDefaultConfidenceScore(string $confidenceLabel): int
    {
        return match ($confidenceLabel) {
            'High confidence' => 85,
            'Medium confidence' => 65,
            'Low confidence' => 40,
            default => 50,
        };
    }
}
