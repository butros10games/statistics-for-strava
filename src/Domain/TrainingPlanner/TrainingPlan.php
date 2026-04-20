<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Auth\AppUserId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'TrainingPlan')]
#[ORM\Index(name: 'TrainingPlan_startDay', columns: ['startDay'])]
#[ORM\Index(name: 'TrainingPlan_endDay', columns: ['endDay'])]
final readonly class TrainingPlan
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private TrainingPlanId $trainingPlanId,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?AppUserId $ownerUserId,
        #[ORM\Column(type: 'string')]
        private TrainingPlanType $type,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $startDay,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $endDay,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?RaceEventId $targetRaceEventId,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $title,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $notes,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?TrainingPlanDiscipline $discipline,
        /** @var array<string, mixed>|null */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $sportSchedule,
        /** @var array<string, mixed>|null */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $performanceMetrics,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?RaceEventProfile $targetRaceProfile,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?TrainingFocus $trainingFocus,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?TrainingBlockStyle $trainingBlockStyle,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?RunningWorkoutTargetMode $runningWorkoutTargetMode,
        #[ORM\Column(type: 'boolean')]
        private bool $runHillSessionsEnabled,
        #[ORM\Column(type: 'string')]
        private TrainingPlanVisibility $visibility,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function create(
        TrainingPlanId $trainingPlanId,
        TrainingPlanType $type,
        SerializableDateTime $startDay,
        SerializableDateTime $endDay,
        ?RaceEventId $targetRaceEventId,
        ?string $title,
        ?string $notes,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
        ?AppUserId $ownerUserId = null,
        ?TrainingPlanDiscipline $discipline = null,
        ?array $sportSchedule = null,
        ?array $performanceMetrics = null,
        ?RaceEventProfile $targetRaceProfile = null,
        ?TrainingFocus $trainingFocus = null,
        ?TrainingBlockStyle $trainingBlockStyle = null,
        ?RunningWorkoutTargetMode $runningWorkoutTargetMode = null,
        bool $runHillSessionsEnabled = false,
        TrainingPlanVisibility $visibility = TrainingPlanVisibility::FRIENDS,
    ): self {
        $normalizedStartDay = $startDay->setTime(0, 0);
        $normalizedEndDay = $endDay->setTime(0, 0);
        if ($normalizedEndDay < $normalizedStartDay) {
            $normalizedEndDay = $normalizedStartDay;
        }

        return new self(
            trainingPlanId: $trainingPlanId,
            ownerUserId: $ownerUserId,
            type: $type,
            startDay: $normalizedStartDay,
            endDay: $normalizedEndDay,
            targetRaceEventId: $targetRaceEventId,
            title: self::normalizeNullableString($title),
            notes: self::normalizeNullableString($notes),
            discipline: $discipline,
            sportSchedule: $sportSchedule,
            performanceMetrics: $performanceMetrics,
            targetRaceProfile: $targetRaceProfile,
            trainingFocus: $trainingFocus,
            trainingBlockStyle: $trainingBlockStyle,
            runningWorkoutTargetMode: $runningWorkoutTargetMode,
            runHillSessionsEnabled: $runHillSessionsEnabled,
            visibility: $visibility,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function getId(): TrainingPlanId
    {
        return $this->trainingPlanId;
    }

    public function getType(): TrainingPlanType
    {
        return $this->type;
    }

    public function getOwnerUserId(): ?AppUserId
    {
        return $this->ownerUserId;
    }

    public function getStartDay(): SerializableDateTime
    {
        return $this->startDay;
    }

    public function getEndDay(): SerializableDateTime
    {
        return $this->endDay;
    }

    public function getTargetRaceEventId(): ?RaceEventId
    {
        return $this->targetRaceEventId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }

    public function getDiscipline(): ?TrainingPlanDiscipline
    {
        return $this->discipline;
    }

    /** @return array<string, mixed>|null */
    public function getSportSchedule(): ?array
    {
        return $this->sportSchedule;
    }

    /** @return array<string, mixed>|null */
    public function getPerformanceMetrics(): ?array
    {
        return $this->performanceMetrics;
    }

    public function getTargetRaceProfile(): ?RaceEventProfile
    {
        return $this->targetRaceProfile;
    }

    public function getTrainingFocus(): ?TrainingFocus
    {
        return $this->trainingFocus;
    }

    public function getTrainingBlockStyle(): ?TrainingBlockStyle
    {
        return $this->trainingBlockStyle;
    }

    public function getRunningWorkoutTargetMode(): ?RunningWorkoutTargetMode
    {
        return $this->runningWorkoutTargetMode;
    }

    public function isRunHillSessionsEnabled(): bool
    {
        return $this->runHillSessionsEnabled;
    }

    public function getVisibility(): TrainingPlanVisibility
    {
        return $this->visibility;
    }

    public function containsDay(SerializableDateTime $day): bool
    {
        $day = $day->setTime(0, 0);

        return $day >= $this->startDay && $day <= $this->endDay;
    }

    public function getDurationInDays(): int
    {
        return ((int) $this->startDay->diff($this->endDay)->format('%a')) + 1;
    }

    public function getDurationInWeeks(): int
    {
        return (int) ceil($this->getDurationInDays() / 7);
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }
}
