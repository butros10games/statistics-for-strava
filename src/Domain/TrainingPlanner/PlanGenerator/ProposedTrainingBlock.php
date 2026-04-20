<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class ProposedTrainingBlock
{
    /**
     * @param list<ProposedWeekSkeleton> $weekSkeletons
     */
    private function __construct(
        private SerializableDateTime $startDay,
        private SerializableDateTime $endDay,
        private TrainingBlockPhase $phase,
        private ?RaceEventId $targetRaceEventId,
        private string $title,
        private ?string $focus,
        private array $weekSkeletons,
    ) {
    }

    /**
     * @param list<ProposedWeekSkeleton> $weekSkeletons
     */
    public static function create(
        SerializableDateTime $startDay,
        SerializableDateTime $endDay,
        TrainingBlockPhase $phase,
        ?RaceEventId $targetRaceEventId,
        string $title,
        ?string $focus,
        array $weekSkeletons = [],
    ): self {
        return new self(
            startDay: $startDay,
            endDay: $endDay,
            phase: $phase,
            targetRaceEventId: $targetRaceEventId,
            title: $title,
            focus: $focus,
            weekSkeletons: $weekSkeletons,
        );
    }

    public function getStartDay(): SerializableDateTime
    {
        return $this->startDay;
    }

    public function getEndDay(): SerializableDateTime
    {
        return $this->endDay;
    }

    public function getPhase(): TrainingBlockPhase
    {
        return $this->phase;
    }

    public function getTargetRaceEventId(): ?RaceEventId
    {
        return $this->targetRaceEventId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getFocus(): ?string
    {
        return $this->focus;
    }

    /**
     * @return list<ProposedWeekSkeleton>
     */
    public function getWeekSkeletons(): array
    {
        return $this->weekSkeletons;
    }

    public function getDurationInWeeks(): int
    {
        return max(1, (int) ceil($this->startDay->diff($this->endDay)->days / 7));
    }
}
