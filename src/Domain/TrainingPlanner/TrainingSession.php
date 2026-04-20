<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'TrainingSession')]
#[ORM\Index(name: 'TrainingSession_sourcePlannedSessionId', columns: ['sourcePlannedSessionId'])]
#[ORM\Index(name: 'TrainingSession_lastPlannedOn', columns: ['lastPlannedOn'])]
#[ORM\Index(name: 'TrainingSession_activityType_updatedAt', columns: ['activityType', 'updatedAt'])]
#[ORM\Index(name: 'TrainingSession_activityType_phase_objective_updatedAt', columns: ['activityType', 'sessionPhase', 'sessionObjective', 'updatedAt'])]
final readonly class TrainingSession
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private TrainingSessionId $trainingSessionId,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?PlannedSessionId $sourcePlannedSessionId,
        #[ORM\Column(type: 'string')]
        private ActivityType $activityType,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?string $title,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $notes,
        #[ORM\Column(type: 'float', nullable: true)]
        private ?float $targetLoad,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $targetDurationInSeconds,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?PlannedSessionIntensity $targetIntensity,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?ActivityId $templateActivityId,
        #[ORM\Column(type: 'json', nullable: true)]
        private array $workoutSteps,
        #[ORM\Column(type: 'string')]
        private PlannedSessionEstimationSource $estimationSource,
        #[ORM\Column(type: 'string')]
        private TrainingSessionSource $sessionSource,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?TrainingBlockPhase $sessionPhase,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?TrainingSessionObjective $sessionObjective,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?SerializableDateTime $lastPlannedOn,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function create(
        TrainingSessionId $trainingSessionId,
        ?PlannedSessionId $sourcePlannedSessionId,
        ActivityType $activityType,
        ?string $title,
        ?string $notes,
        ?float $targetLoad,
        ?int $targetDurationInSeconds,
        ?PlannedSessionIntensity $targetIntensity,
        ?ActivityId $templateActivityId,
        PlannedSessionEstimationSource $estimationSource,
        ?SerializableDateTime $lastPlannedOn,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
        array $workoutSteps = [],
        TrainingSessionSource $sessionSource = TrainingSessionSource::PLANNED_SESSION,
        ?TrainingBlockPhase $sessionPhase = null,
        ?TrainingSessionObjective $sessionObjective = null,
    ): self {
        return new self(
            trainingSessionId: $trainingSessionId,
            sourcePlannedSessionId: $sourcePlannedSessionId,
            activityType: $activityType,
            title: self::normalizeNullableString($title),
            notes: self::normalizeNullableString($notes),
            targetLoad: null === $targetLoad ? null : round($targetLoad, 1),
            targetDurationInSeconds: null === $targetDurationInSeconds ? null : max(0, $targetDurationInSeconds),
            targetIntensity: $targetIntensity,
            templateActivityId: $templateActivityId,
            workoutSteps: self::normalizeWorkoutSteps($workoutSteps),
            estimationSource: $estimationSource,
            sessionSource: $sessionSource,
            sessionPhase: $sessionPhase,
            sessionObjective: $sessionObjective,
            lastPlannedOn: $lastPlannedOn?->setTime(0, 0),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public static function createFromPlannedSession(PlannedSession $plannedSession, ?self $existingTrainingSession = null): self
    {
        return self::create(
            trainingSessionId: $existingTrainingSession?->getId() ?? TrainingSessionId::random(),
            sourcePlannedSessionId: $plannedSession->getId(),
            activityType: $plannedSession->getActivityType(),
            title: $plannedSession->getTitle(),
            notes: $plannedSession->getNotes(),
            targetLoad: $plannedSession->getTargetLoad(),
            targetDurationInSeconds: $plannedSession->getTargetDurationInSeconds(),
            targetIntensity: $plannedSession->getTargetIntensity(),
            templateActivityId: $plannedSession->getTemplateActivityId(),
            workoutSteps: $plannedSession->getWorkoutSteps(),
            estimationSource: $plannedSession->getEstimationSource(),
            sessionSource: TrainingSessionSource::PLANNED_SESSION,
            sessionPhase: self::inferSessionPhase($plannedSession->getTitle(), $plannedSession->getNotes(), $plannedSession->getTargetIntensity()),
            sessionObjective: self::inferSessionObjective($plannedSession->getTitle(), $plannedSession->getNotes(), $plannedSession->getTargetIntensity()),
            lastPlannedOn: $plannedSession->getDay(),
            createdAt: $existingTrainingSession?->getCreatedAt() ?? $plannedSession->getCreatedAt(),
            updatedAt: $plannedSession->getUpdatedAt(),
        );
    }

    public function getId(): TrainingSessionId
    {
        return $this->trainingSessionId;
    }

    public function getSourcePlannedSessionId(): ?PlannedSessionId
    {
        return $this->sourcePlannedSessionId;
    }

    public function getActivityType(): ActivityType
    {
        return $this->activityType;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getTargetLoad(): ?float
    {
        return $this->targetLoad;
    }

    public function getTargetDurationInSeconds(): ?int
    {
        return $this->targetDurationInSeconds;
    }

    public function getTargetIntensity(): ?PlannedSessionIntensity
    {
        return $this->targetIntensity;
    }

    public function getTemplateActivityId(): ?ActivityId
    {
        return $this->templateActivityId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWorkoutSteps(): array
    {
        return $this->workoutSteps;
    }

    public function hasWorkoutSteps(): bool
    {
        return [] !== $this->workoutSteps;
    }

    public function getEstimationSource(): PlannedSessionEstimationSource
    {
        return $this->estimationSource;
    }

    public function getSessionSource(): TrainingSessionSource
    {
        return $this->sessionSource;
    }

    public function getSessionPhase(): ?TrainingBlockPhase
    {
        return $this->sessionPhase;
    }

    public function getSessionObjective(): ?TrainingSessionObjective
    {
        return $this->sessionObjective;
    }

    public function getLastPlannedOn(): ?SerializableDateTime
    {
        return $this->lastPlannedOn;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }

    public function withPersistedIdentity(self $persistedTrainingSession, bool $preserveExistingSourcePlannedSessionId = false): self
    {
        $lastPlannedOn = match (true) {
            null === $this->getLastPlannedOn() => $persistedTrainingSession->getLastPlannedOn(),
            null === $persistedTrainingSession->getLastPlannedOn() => $this->getLastPlannedOn(),
            $this->getLastPlannedOn()->isAfter($persistedTrainingSession->getLastPlannedOn()) => $this->getLastPlannedOn(),
            default => $persistedTrainingSession->getLastPlannedOn(),
        };
        $updatedAt = $this->getUpdatedAt()->isAfter($persistedTrainingSession->getUpdatedAt())
            ? $this->getUpdatedAt()
            : $persistedTrainingSession->getUpdatedAt();

        return self::create(
            trainingSessionId: $persistedTrainingSession->getId(),
            sourcePlannedSessionId: $preserveExistingSourcePlannedSessionId
                ? $persistedTrainingSession->getSourcePlannedSessionId()
                : $this->getSourcePlannedSessionId(),
            activityType: $this->getActivityType(),
            title: $this->getTitle(),
            notes: $this->getNotes(),
            targetLoad: $this->getTargetLoad(),
            targetDurationInSeconds: $this->getTargetDurationInSeconds(),
            targetIntensity: $this->getTargetIntensity(),
            templateActivityId: $this->getTemplateActivityId(),
            workoutSteps: $this->getWorkoutSteps(),
            estimationSource: $this->getEstimationSource(),
            sessionSource: $this->getSessionSource(),
            sessionPhase: $this->getSessionPhase(),
            sessionObjective: $this->getSessionObjective(),
            lastPlannedOn: $lastPlannedOn,
            createdAt: $persistedTrainingSession->getCreatedAt(),
            updatedAt: $updatedAt,
        );
    }

    public function getDeduplicationValues(): array
    {
        return [
            'activityType' => $this->getActivityType()->value,
            'title' => $this->getTitle(),
            'notes' => $this->getNotes(),
            'targetLoad' => $this->getTargetLoad(),
            'targetDurationInSeconds' => $this->getTargetDurationInSeconds(),
            'targetIntensity' => $this->getTargetIntensity()?->value,
            'templateActivityId' => $this->getTemplateActivityId()?->__toString(),
            'workoutSteps' => [] === $this->getWorkoutSteps() ? null : Json::encode($this->getWorkoutSteps()),
            'estimationSource' => $this->getEstimationSource()->value,
        ];
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeWorkoutSteps(array $workoutSteps): array
    {
        return array_values(array_filter(
            $workoutSteps,
            static fn (mixed $workoutStep): bool => is_array($workoutStep),
        ));
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalizedValue = trim($value);

        return '' === $normalizedValue ? null : $normalizedValue;
    }

    private static function inferSessionPhase(
        ?string $title,
        ?string $notes,
        ?PlannedSessionIntensity $targetIntensity,
    ): ?TrainingBlockPhase {
        $searchText = self::buildSearchText($title, $notes);

        return match (true) {
            self::containsAny($searchText, ['recovery', 'shakeout', 'flush']) => TrainingBlockPhase::RECOVERY,
            self::containsAny($searchText, ['taper', 'sharpener', 'opener']) => TrainingBlockPhase::TAPER,
            self::containsAny($searchText, ['race-pace', 'race pace', 'tune-up']) => TrainingBlockPhase::PEAK,
            self::containsAny($searchText, ['interval', 'threshold', 'tempo', 'hill', 'vo2']) => TrainingBlockPhase::BUILD,
            self::containsAny($searchText, ['base', 'aerobic', 'endurance', 'long', 'easy']) => TrainingBlockPhase::BASE,
            PlannedSessionIntensity::HARD === $targetIntensity => TrainingBlockPhase::BUILD,
            PlannedSessionIntensity::EASY === $targetIntensity => TrainingBlockPhase::BASE,
            default => null,
        };
    }

    private static function inferSessionObjective(
        ?string $title,
        ?string $notes,
        ?PlannedSessionIntensity $targetIntensity,
    ): ?TrainingSessionObjective {
        $searchText = self::buildSearchText($title, $notes);

        return match (true) {
            self::containsAny($searchText, ['recovery', 'shakeout', 'flush']) => TrainingSessionObjective::RECOVERY,
            self::containsAny($searchText, ['drill', 'technique', 'skills', 'cadence', 'form']) => TrainingSessionObjective::TECHNIQUE,
            self::containsAny($searchText, ['race-pace', 'race pace', 'brick', 'opener', 'sharpener']) => TrainingSessionObjective::RACE_SPECIFIC,
            self::containsAny($searchText, ['vo2', 'interval', 'reps', 'speed', 'hill']) => TrainingSessionObjective::HIGH_INTENSITY,
            self::containsAny($searchText, ['threshold', 'tempo', 'sweet spot', 'steady']) => TrainingSessionObjective::THRESHOLD,
            self::containsAny($searchText, ['long', 'aerobic', 'endurance', 'easy']) => TrainingSessionObjective::ENDURANCE,
            PlannedSessionIntensity::HARD === $targetIntensity => TrainingSessionObjective::HIGH_INTENSITY,
            PlannedSessionIntensity::MODERATE === $targetIntensity => TrainingSessionObjective::THRESHOLD,
            PlannedSessionIntensity::EASY === $targetIntensity => TrainingSessionObjective::ENDURANCE,
            default => null,
        };
    }

    private static function buildSearchText(?string $title, ?string $notes): string
    {
        return strtolower(trim(implode(' ', array_filter([$title, $notes]))));
    }

    /**
     * @param list<string> $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
