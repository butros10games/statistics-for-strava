<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

final readonly class PlanAdaptationWarning
{
    private function __construct(
        private PlanAdaptationWarningType $type,
        private string $title,
        private string $body,
        private PlanAdaptationWarningSeverity $severity,
    ) {
    }

    public static function create(
        PlanAdaptationWarningType $type,
        string $title,
        string $body,
        PlanAdaptationWarningSeverity $severity = PlanAdaptationWarningSeverity::INFO,
    ): self {
        return new self(
            type: $type,
            title: $title,
            body: $body,
            severity: $severity,
        );
    }

    public function getType(): PlanAdaptationWarningType
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
}
