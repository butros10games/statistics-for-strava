<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'PlannedSession')]
#[ORM\Index(name: 'PlannedSession_day', columns: ['day'])]
#[ORM\Index(name: 'PlannedSession_linkedActivityId', columns: ['linkedActivityId'])]
final readonly class PlannedSession
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private PlannedSessionId $plannedSessionId,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $day,
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
        #[ORM\Column(type: 'string', nullable: true)]
        private ?ActivityId $linkedActivityId,
        #[ORM\Column(type: 'string')]
        private PlannedSessionLinkStatus $linkStatus,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $updatedAt,
    ) {
    }

    public static function create(
        PlannedSessionId $plannedSessionId,
        SerializableDateTime $day,
        ActivityType $activityType,
        ?string $title,
        ?string $notes,
        ?float $targetLoad,
        ?int $targetDurationInSeconds,
        ?PlannedSessionIntensity $targetIntensity,
        ?ActivityId $templateActivityId,
        PlannedSessionEstimationSource $estimationSource,
        ?ActivityId $linkedActivityId,
        PlannedSessionLinkStatus $linkStatus,
        SerializableDateTime $createdAt,
        SerializableDateTime $updatedAt,
        array $workoutSteps = [],
    ): self {
        return new self(
            plannedSessionId: $plannedSessionId,
            day: $day->setTime(0, 0),
            activityType: $activityType,
            title: self::normalizeNullableString($title),
            notes: self::normalizeNullableString($notes),
            targetLoad: null === $targetLoad ? null : round($targetLoad, 1),
            targetDurationInSeconds: null === $targetDurationInSeconds ? null : max(0, $targetDurationInSeconds),
            targetIntensity: $targetIntensity,
            templateActivityId: $templateActivityId,
            workoutSteps: self::normalizeWorkoutSteps($activityType, $workoutSteps),
            estimationSource: $estimationSource,
            linkedActivityId: $linkedActivityId,
            linkStatus: $linkStatus,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function getId(): PlannedSessionId
    {
        return $this->plannedSessionId;
    }

    public function getDay(): SerializableDateTime
    {
        return $this->day;
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
    * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}>
     */
    public function getWorkoutSteps(): array
    {
        return $this->workoutSteps;
    }

    public function hasWorkoutSteps(): bool
    {
        return [] !== $this->workoutSteps;
    }

    public function getWorkoutDurationInSeconds(): ?int
    {
        if (!$this->hasWorkoutSteps()) {
            return null;
        }

        return self::calculateWorkoutSequenceDuration($this->workoutSteps);
    }

    public function getEstimationSource(): PlannedSessionEstimationSource
    {
        return $this->estimationSource;
    }

    public function getLinkedActivityId(): ?ActivityId
    {
        return $this->linkedActivityId;
    }

    public function getLinkStatus(): PlannedSessionLinkStatus
    {
        return $this->linkStatus;
    }

    public function getCreatedAt(): SerializableDateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): SerializableDateTime
    {
        return $this->updatedAt;
    }

    public function withSuggestedLink(ActivityId $linkedActivityId, SerializableDateTime $updatedAt): self
    {
        return self::create(
            plannedSessionId: $this->plannedSessionId,
            day: $this->day,
            activityType: $this->activityType,
            title: $this->title,
            notes: $this->notes,
            targetLoad: $this->targetLoad,
            targetDurationInSeconds: $this->targetDurationInSeconds,
            targetIntensity: $this->targetIntensity,
            templateActivityId: $this->templateActivityId,
            workoutSteps: $this->workoutSteps,
            estimationSource: $this->estimationSource,
            linkedActivityId: $linkedActivityId,
            linkStatus: PlannedSessionLinkStatus::SUGGESTED,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function withConfirmedLink(ActivityId $linkedActivityId, SerializableDateTime $updatedAt): self
    {
        return self::create(
            plannedSessionId: $this->plannedSessionId,
            day: $this->day,
            activityType: $this->activityType,
            title: $this->title,
            notes: $this->notes,
            targetLoad: $this->targetLoad,
            targetDurationInSeconds: $this->targetDurationInSeconds,
            targetIntensity: $this->targetIntensity,
            templateActivityId: $this->templateActivityId,
            workoutSteps: $this->workoutSteps,
            estimationSource: $this->estimationSource,
            linkedActivityId: $linkedActivityId,
            linkStatus: PlannedSessionLinkStatus::LINKED,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function withoutLink(SerializableDateTime $updatedAt): self
    {
        return self::create(
            plannedSessionId: $this->plannedSessionId,
            day: $this->day,
            activityType: $this->activityType,
            title: $this->title,
            notes: $this->notes,
            targetLoad: $this->targetLoad,
            targetDurationInSeconds: $this->targetDurationInSeconds,
            targetIntensity: $this->targetIntensity,
            templateActivityId: $this->templateActivityId,
            workoutSteps: $this->workoutSteps,
            estimationSource: $this->estimationSource,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
    * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}>
     */
    private static function normalizeWorkoutSteps(ActivityType $activityType, array $workoutSteps): array
    {
        $normalizedSteps = [];

        foreach (array_values($workoutSteps) as $index => $workoutStep) {
            if (!is_array($workoutStep)) {
                continue;
            }

            $type = $workoutStep['type'] ?? PlannedSessionStepType::INTERVAL->value;
            if (!$type instanceof PlannedSessionStepType) {
                $type = PlannedSessionStepType::tryFrom((string) $type) ?? PlannedSessionStepType::INTERVAL;
            }

            $itemId = self::normalizeItemId($workoutStep['itemId'] ?? null, $index);
            $parentBlockId = self::normalizeNullableString(is_string($workoutStep['parentBlockId'] ?? null) ? $workoutStep['parentBlockId'] : null);
            $durationInSeconds = max(0, (int) ($workoutStep['durationInSeconds'] ?? 0));
            $durationInSeconds = $durationInSeconds > 0 ? $durationInSeconds : null;
            $distanceInMeters = max(0, (int) ($workoutStep['distanceInMeters'] ?? 0));
            $distanceInMeters = $distanceInMeters > 0 ? $distanceInMeters : null;
            $targetHeartRate = max(0, (int) ($workoutStep['targetHeartRate'] ?? 0));
            $targetHeartRate = $targetHeartRate > 0 ? $targetHeartRate : null;
            $targetPace = self::normalizeNullableString(is_string($workoutStep['targetPace'] ?? null) ? $workoutStep['targetPace'] : null);
            $targetPower = max(0, (int) ($workoutStep['targetPower'] ?? 0));
            $targetPower = $targetPower > 0 ? $targetPower : null;
            [$targetPace, $targetPower] = self::normalizeWorkoutStepEffortTargets($activityType, $targetPace, $targetPower);
            $targetType = $workoutStep['targetType'] ?? null;
            if (!$targetType instanceof PlannedSessionStepTargetType) {
                $targetType = PlannedSessionStepTargetType::tryFrom((string) $targetType);
            }
            $targetType ??= self::inferTargetType($durationInSeconds, $distanceInMeters, $targetHeartRate);
            $conditionType = $workoutStep['conditionType'] ?? null;
            if (!$conditionType instanceof PlannedSessionStepConditionType) {
                $conditionType = PlannedSessionStepConditionType::tryFrom((string) $conditionType);
            }
            $conditionType ??= self::inferConditionType($targetType);

            $recoveryAfterInSeconds = null === ($workoutStep['recoveryAfterInSeconds'] ?? null)
                ? null
                : max(0, (int) $workoutStep['recoveryAfterInSeconds']);
            if (0 === $recoveryAfterInSeconds) {
                $recoveryAfterInSeconds = null;
            }

            if ($type->isContainer()) {
                $normalizedSteps[] = [
                    'itemId' => $itemId,
                    'parentBlockId' => $parentBlockId,
                    'type' => $type->value,
                    'label' => self::normalizeNullableString(is_string($workoutStep['label'] ?? null) ? $workoutStep['label'] : null),
                    'repetitions' => max(1, (int) ($workoutStep['repetitions'] ?? 1)),
                    'targetType' => null,
                    'conditionType' => null,
                    'durationInSeconds' => null,
                    'distanceInMeters' => null,
                    'targetPace' => null,
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ];

                if (null === $durationInSeconds && null === $distanceInMeters && null === $targetHeartRate && null === $recoveryAfterInSeconds) {
                    continue;
                }

                $legacyStepTargetType = $targetType ?? PlannedSessionStepTargetType::TIME;
                $normalizedSteps[] = [
                    'itemId' => sprintf('%s-step', $itemId),
                    'parentBlockId' => $itemId,
                    'type' => PlannedSessionStepType::INTERVAL->value,
                    'label' => self::normalizeNullableString(is_string($workoutStep['label'] ?? null) ? $workoutStep['label'] : null),
                    'repetitions' => 1,
                    'targetType' => $legacyStepTargetType->value,
                    'conditionType' => self::inferConditionType($legacyStepTargetType)?->value,
                    'durationInSeconds' => $durationInSeconds,
                    'distanceInMeters' => $distanceInMeters,
                    'targetPace' => $targetPace,
                    'targetPower' => $targetPower,
                    'targetHeartRate' => $targetHeartRate,
                    'recoveryAfterInSeconds' => null,
                ];

                if (null !== $recoveryAfterInSeconds) {
                    $normalizedSteps[] = [
                        'itemId' => sprintf('%s-recovery', $itemId),
                        'parentBlockId' => $itemId,
                        'type' => PlannedSessionStepType::RECOVERY->value,
                        'label' => 'Recovery',
                        'repetitions' => 1,
                        'targetType' => PlannedSessionStepTargetType::TIME->value,
                        'conditionType' => null,
                        'durationInSeconds' => $recoveryAfterInSeconds,
                        'distanceInMeters' => null,
                        'targetPace' => null,
                        'targetPower' => null,
                        'targetHeartRate' => null,
                        'recoveryAfterInSeconds' => null,
                    ];
                }

                continue;
            }

            if (!self::isValidStepTarget($targetType, $conditionType, $durationInSeconds, $distanceInMeters, $targetHeartRate)) {
                if (null === $recoveryAfterInSeconds) {
                    continue;
                }

                $targetType = PlannedSessionStepTargetType::TIME;
                $conditionType = null;
                $durationInSeconds = $recoveryAfterInSeconds;
                $distanceInMeters = null;
                $targetHeartRate = null;
            }

            $normalizedSteps[] = [
                'itemId' => $itemId,
                'parentBlockId' => $parentBlockId,
                'type' => $type->value,
                'label' => self::normalizeNullableString(is_string($workoutStep['label'] ?? null) ? $workoutStep['label'] : null),
                'repetitions' => max(1, (int) ($workoutStep['repetitions'] ?? 1)),
                'targetType' => $targetType?->value,
                'conditionType' => $conditionType?->value,
                'durationInSeconds' => $durationInSeconds,
                'distanceInMeters' => $distanceInMeters,
                'targetPace' => $targetPace,
                'targetPower' => $targetPower,
                'targetHeartRate' => $targetHeartRate,
                'recoveryAfterInSeconds' => $recoveryAfterInSeconds,
            ];
        }

        return $normalizedSteps;
    }

    /**
    * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     */
    private static function calculateWorkoutSequenceDuration(array $workoutSteps, ?string $parentBlockId = null): ?int
    {
        $totalDurationInSeconds = 0;

        foreach ($workoutSteps as $workoutStep) {
            if (($workoutStep['parentBlockId'] ?? null) !== $parentBlockId) {
                continue;
            }

            $stepType = PlannedSessionStepType::tryFrom($workoutStep['type']) ?? PlannedSessionStepType::INTERVAL;
            if ($stepType->isContainer()) {
                $childDurationInSeconds = self::calculateWorkoutSequenceDuration($workoutSteps, $workoutStep['itemId']);
                if (null === $childDurationInSeconds) {
                    return null;
                }

                $totalDurationInSeconds += max(1, $workoutStep['repetitions']) * $childDurationInSeconds;

                continue;
            }

            $estimatedStepDurationInSeconds = self::estimateWorkoutStepDurationInSeconds($workoutStep);
            if (null === $estimatedStepDurationInSeconds) {
                return null;
            }

            $totalDurationInSeconds += (max(1, $workoutStep['repetitions']) * $estimatedStepDurationInSeconds)
                + (max(0, $workoutStep['repetitions'] - 1) * ($workoutStep['recoveryAfterInSeconds'] ?? 0));
        }

        return $totalDurationInSeconds;
    }

    /**
    * @param array{targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     */
    private static function estimateWorkoutStepDurationInSeconds(array $workoutStep): ?int
    {
        $targetType = PlannedSessionStepTargetType::tryFrom((string) ($workoutStep['targetType'] ?? ''));
        if (PlannedSessionStepTargetType::HEART_RATE === $targetType) {
            return null !== $workoutStep['durationInSeconds']
                ? $workoutStep['durationInSeconds']
                : null;
        }

        if (null !== $workoutStep['durationInSeconds'] && $workoutStep['durationInSeconds'] > 0) {
            return $workoutStep['durationInSeconds'];
        }

        if (null === $workoutStep['distanceInMeters'] || $workoutStep['distanceInMeters'] <= 0) {
            return null;
        }

        $secondsPerMeter = self::parsePaceSecondsPerMeter($workoutStep['targetPace']);
        if (null === $secondsPerMeter) {
            return null;
        }

        return (int) round($secondsPerMeter * $workoutStep['distanceInMeters']);
    }

    private static function inferTargetType(?int $durationInSeconds, ?int $distanceInMeters, ?int $targetHeartRate): ?PlannedSessionStepTargetType
    {
        return match (true) {
            null !== $targetHeartRate => PlannedSessionStepTargetType::HEART_RATE,
            null !== $distanceInMeters => PlannedSessionStepTargetType::DISTANCE,
            null !== $durationInSeconds => PlannedSessionStepTargetType::TIME,
            default => null,
        };
    }

    private static function inferConditionType(?PlannedSessionStepTargetType $targetType): ?PlannedSessionStepConditionType
    {
        return PlannedSessionStepTargetType::HEART_RATE === $targetType
            ? PlannedSessionStepConditionType::HOLD_TARGET
            : null;
    }

    private static function isValidStepTarget(?PlannedSessionStepTargetType $targetType, ?PlannedSessionStepConditionType $conditionType, ?int $durationInSeconds, ?int $distanceInMeters, ?int $targetHeartRate): bool
    {
        return match ($targetType) {
            PlannedSessionStepTargetType::TIME => match ($conditionType) {
                null, PlannedSessionStepConditionType::HOLD_TARGET, PlannedSessionStepConditionType::LAP_BUTTON => null !== $durationInSeconds,
                default => false,
            },
            PlannedSessionStepTargetType::DISTANCE => null !== $distanceInMeters,
            PlannedSessionStepTargetType::HEART_RATE => match ($conditionType ?? PlannedSessionStepConditionType::HOLD_TARGET) {
                PlannedSessionStepConditionType::HOLD_TARGET => null !== $durationInSeconds && null !== $targetHeartRate,
                PlannedSessionStepConditionType::UNTIL_BELOW, PlannedSessionStepConditionType::UNTIL_ABOVE => null !== $targetHeartRate,
                PlannedSessionStepConditionType::LAP_BUTTON => true,
            },
            null => (null !== $durationInSeconds || null !== $distanceInMeters),
        };
    }

    private static function normalizeItemId(mixed $value, int $index): string
    {
        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        return sprintf('workout-item-%d-%s', $index, substr(md5((string) $index.serialize($value)), 0, 8));
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private static function normalizeWorkoutStepEffortTargets(ActivityType $activityType, ?string $targetPace, ?int $targetPower): array
    {
        if ($activityType->supportsPowerData()) {
            if (null !== $targetPower && $targetPower > 0) {
                return [null, $targetPower];
            }

            return [$targetPace, null];
        }

        return [$targetPace, null];
    }

    private static function parsePaceSecondsPerMeter(?string $targetPace): ?float
    {
        if (null === $targetPace) {
            return null;
        }

        if (!preg_match('/^\s*(\d+):(\d{2})(?:\s*\/\s*(km|mi))?\s*$/i', $targetPace, $matches)) {
            return null;
        }

        $seconds = ((int) $matches[1] * 60) + (int) $matches[2];
        $unit = strtolower($matches[3] ?? 'km');
        $meters = 'mi' === $unit ? 1609.344 : 1000.0;

        return $seconds / $meters;
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
