<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

final readonly class PlanAdaptationRecommendation
{
    private function __construct(
        private PlanAdaptationRecommendationType $type,
        private string $title,
        private string $body,
        private PlanAdaptationWarningSeverity $severity,
        private ?ProposedTrainingBlock $suggestedBlock,
    ) {
    }

    public static function create(
        PlanAdaptationRecommendationType $type,
        string $title,
        string $body,
        PlanAdaptationWarningSeverity $severity = PlanAdaptationWarningSeverity::INFO,
        ?ProposedTrainingBlock $suggestedBlock = null,
    ): self {
        return new self(
            type: $type,
            title: $title,
            body: $body,
            severity: $severity,
            suggestedBlock: $suggestedBlock,
        );
    }

    public function getType(): PlanAdaptationRecommendationType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSeverity(): PlanAdaptationWarningSeverity
    {
        return $this->severity;
    }

    public function getSuggestedBlock(): ?ProposedTrainingBlock
    {
        return $this->suggestedBlock;
    }
}
