<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Analysis;

use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class TrainingPlanAnalysisScenario
{
    /**
     * @param list<RaceEvent> $allRaceEvents
     */
    private function __construct(
        private string $name,
        private string $label,
        private TrainingPlanType $planType,
        private TrainingPlanDiscipline $discipline,
        private RaceEventProfile $targetRaceProfile,
        private ?TrainingFocus $trainingFocus,
        private SerializableDateTime $planStartDay,
        private SerializableDateTime $referenceDate,
        private RaceEvent $targetRace,
        private array $allRaceEvents,
        private ?TrainingPlan $linkedTrainingPlan,
    ) {
    }

    /**
     * @param list<RaceEvent> $allRaceEvents
     */
    public static function create(
        string $name,
        string $label,
        TrainingPlanType $planType,
        TrainingPlanDiscipline $discipline,
        RaceEventProfile $targetRaceProfile,
        ?TrainingFocus $trainingFocus,
        SerializableDateTime $planStartDay,
        SerializableDateTime $referenceDate,
        RaceEvent $targetRace,
        array $allRaceEvents,
        ?TrainingPlan $linkedTrainingPlan,
    ): self {
        return new self(
            name: $name,
            label: $label,
            planType: $planType,
            discipline: $discipline,
            targetRaceProfile: $targetRaceProfile,
            trainingFocus: $trainingFocus,
            planStartDay: $planStartDay,
            referenceDate: $referenceDate,
            targetRace: $targetRace,
            allRaceEvents: $allRaceEvents,
            linkedTrainingPlan: $linkedTrainingPlan,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPlanType(): TrainingPlanType
    {
        return $this->planType;
    }

    public function getDiscipline(): TrainingPlanDiscipline
    {
        return $this->discipline;
    }

    public function getTargetRaceProfile(): RaceEventProfile
    {
        return $this->targetRaceProfile;
    }

    public function getTrainingFocus(): ?TrainingFocus
    {
        return $this->trainingFocus;
    }

    public function getPlanStartDay(): SerializableDateTime
    {
        return $this->planStartDay;
    }

    public function getReferenceDate(): SerializableDateTime
    {
        return $this->referenceDate;
    }

    public function getTargetRace(): RaceEvent
    {
        return $this->targetRace;
    }

    /**
     * @return list<RaceEvent>
     */
    public function getAllRaceEvents(): array
    {
        return $this->allRaceEvents;
    }

    public function getLinkedTrainingPlan(): ?TrainingPlan
    {
        return $this->linkedTrainingPlan;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'planType' => $this->planType->value,
            'discipline' => $this->discipline->value,
            'targetRaceProfile' => $this->targetRaceProfile->value,
            'trainingFocus' => $this->trainingFocus?->value,
            'planStartDay' => $this->planStartDay->format('Y-m-d'),
            'planEndDay' => $this->linkedTrainingPlan?->getEndDay()->format('Y-m-d') ?? $this->targetRace->getDay()->format('Y-m-d'),
            'referenceDate' => $this->referenceDate->format('Y-m-d'),
            'targetRaceDay' => $this->targetRace->getDay()->format('Y-m-d'),
            'targetFinishTimeInSeconds' => $this->targetRace->getTargetFinishTimeInSeconds(),
            'performanceMetrics' => $this->linkedTrainingPlan?->getPerformanceMetrics(),
            'sportSchedule' => $this->linkedTrainingPlan?->getSportSchedule(),
        ];
    }
}
