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
        private string $confidenceLabel,
        private float $confidenceScore,
        private string $methodDetails,
        private ?int $sampleCount,
        private float $lowerEstimatedLoad,
        private float $upperEstimatedLoad,
    ) {
    }

    public static function create(
        PlannedSession $plannedSession,
        float $estimatedLoad,
        PlannedSessionEstimationSource $estimationSource,
        string $confidenceLabel = 'Medium confidence',
        float $confidenceScore = 0.5,
        string $methodDetails = 'Estimated from planned session details.',
        ?int $sampleCount = null,
        ?float $lowerEstimatedLoad = null,
        ?float $upperEstimatedLoad = null,
    ): self {
        $estimatedLoad = round(max(0.0, $estimatedLoad), 1);
        $lowerEstimatedLoad = round(max(0.0, min($estimatedLoad, $lowerEstimatedLoad ?? $estimatedLoad)), 1);
        $upperEstimatedLoad = round(max($estimatedLoad, $upperEstimatedLoad ?? $estimatedLoad), 1);

        return new self(
            plannedSession: $plannedSession,
            estimatedLoad: $estimatedLoad,
            estimationSource: $estimationSource,
            confidenceLabel: trim($confidenceLabel),
            confidenceScore: round(max(0.0, min(1.0, $confidenceScore)), 2),
            methodDetails: trim($methodDetails),
            sampleCount: null === $sampleCount ? null : max(0, $sampleCount),
            lowerEstimatedLoad: $lowerEstimatedLoad,
            upperEstimatedLoad: $upperEstimatedLoad,
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

    public function getConfidenceLabel(): string
    {
        return $this->confidenceLabel;
    }

    public function getConfidenceScore(): float
    {
        return $this->confidenceScore;
    }

    public function getMethodDetails(): string
    {
        return $this->methodDetails;
    }

    public function getSampleCount(): ?int
    {
        return $this->sampleCount;
    }

    public function getLowerEstimatedLoad(): float
    {
        return $this->lowerEstimatedLoad;
    }

    public function getUpperEstimatedLoad(): float
    {
        return $this->upperEstimatedLoad;
    }

    /**
     * @return array{lower: float, upper: float}
     */
    public function getEstimatedLoadRange(): array
    {
        return [
            'lower' => $this->lowerEstimatedLoad,
            'upper' => $this->upperEstimatedLoad,
        ];
    }
}
