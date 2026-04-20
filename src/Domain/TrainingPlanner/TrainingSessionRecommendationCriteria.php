<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class TrainingSessionRecommendationCriteria
{
    public function __construct(
        private ?TrainingBlockPhase $sessionPhase = null,
        private ?TrainingSessionObjective $sessionObjective = null,
        private ?TrainingSessionSource $sessionSource = null,
        private ?PlannedSessionIntensity $targetIntensity = null,
        private ?float $minimumTargetLoad = null,
        private ?float $maximumTargetLoad = null,
        private ?int $minimumTargetDurationInSeconds = null,
        private ?int $maximumTargetDurationInSeconds = null,
        private ?bool $requiresWorkoutSteps = null,
    ) {
    }

    public function getSessionPhase(): ?TrainingBlockPhase
    {
        return $this->sessionPhase;
    }

    public function getSessionObjective(): ?TrainingSessionObjective
    {
        return $this->sessionObjective;
    }

    public function getSessionSource(): ?TrainingSessionSource
    {
        return $this->sessionSource;
    }

    public function getTargetIntensity(): ?PlannedSessionIntensity
    {
        return $this->targetIntensity;
    }

    public function getMinimumTargetLoad(): ?float
    {
        return $this->minimumTargetLoad;
    }

    public function getMaximumTargetLoad(): ?float
    {
        return $this->maximumTargetLoad;
    }

    public function getMinimumTargetDurationInSeconds(): ?int
    {
        return $this->minimumTargetDurationInSeconds;
    }

    public function getMaximumTargetDurationInSeconds(): ?int
    {
        return $this->maximumTargetDurationInSeconds;
    }

    public function requiresWorkoutSteps(): ?bool
    {
        return $this->requiresWorkoutSteps;
    }
}