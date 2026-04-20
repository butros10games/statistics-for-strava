<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\TrainingPlanner\RaceEvent;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class TrainingPlanProposal
{
    /**
     * @param list<ProposedTrainingBlock> $proposedBlocks
     * @param list<PlanAdaptationWarning> $warnings
     */
    private function __construct(
        private RaceEvent $targetRace,
        private RaceProfileTrainingRules $rules,
        private SerializableDateTime $planStartDay,
        private SerializableDateTime $planEndDay,
        private int $totalWeeks,
        private array $proposedBlocks,
        private array $warnings,
    ) {
    }

    /**
     * @param list<ProposedTrainingBlock> $proposedBlocks
     * @param list<PlanAdaptationWarning> $warnings
     */
    public static function create(
        RaceEvent $targetRace,
        RaceProfileTrainingRules $rules,
        SerializableDateTime $planStartDay,
        SerializableDateTime $planEndDay,
        int $totalWeeks,
        array $proposedBlocks,
        array $warnings = [],
    ): self {
        return new self(
            targetRace: $targetRace,
            rules: $rules,
            planStartDay: $planStartDay,
            planEndDay: $planEndDay,
            totalWeeks: $totalWeeks,
            proposedBlocks: $proposedBlocks,
            warnings: $warnings,
        );
    }

    public function getTargetRace(): RaceEvent
    {
        return $this->targetRace;
    }

    public function getRules(): RaceProfileTrainingRules
    {
        return $this->rules;
    }

    public function getPlanStartDay(): SerializableDateTime
    {
        return $this->planStartDay;
    }

    public function getPlanEndDay(): SerializableDateTime
    {
        return $this->planEndDay;
    }

    public function getTotalWeeks(): int
    {
        return $this->totalWeeks;
    }

    /**
     * @return list<ProposedTrainingBlock>
     */
    public function getProposedBlocks(): array
    {
        return $this->proposedBlocks;
    }

    /**
     * @return list<PlanAdaptationWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    public function getTotalProposedSessions(): int
    {
        $count = 0;

        foreach ($this->proposedBlocks as $block) {
            foreach ($block->getWeekSkeletons() as $week) {
                $count += $week->getSessionCount();
            }
        }

        return $count;
    }
}
