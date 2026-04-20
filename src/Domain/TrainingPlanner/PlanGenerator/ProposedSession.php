<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class ProposedSession
{
    private function __construct(
        private SerializableDateTime $day,
        private ActivityType $activityType,
        private PlannedSessionIntensity $targetIntensity,
        private ?string $title,
        private ?string $notes,
        private ?int $targetDurationInSeconds,
        private bool $isKeySession,
        private bool $isBrickSession,
        private array $workoutSteps,
    ) {
    }

    public static function create(
        SerializableDateTime $day,
        ActivityType $activityType,
        PlannedSessionIntensity $targetIntensity,
        ?string $title = null,
        ?string $notes = null,
        ?int $targetDurationInSeconds = null,
        bool $isKeySession = false,
        bool $isBrickSession = false,
        array $workoutSteps = [],
    ): self {
        return new self(
            day: $day,
            activityType: $activityType,
            targetIntensity: $targetIntensity,
            title: $title,
            notes: $notes,
            targetDurationInSeconds: $targetDurationInSeconds,
            isKeySession: $isKeySession,
            isBrickSession: $isBrickSession,
            workoutSteps: $workoutSteps,
        );
    }

    public function getDay(): SerializableDateTime
    {
        return $this->day;
    }

    public function getActivityType(): ActivityType
    {
        return $this->activityType;
    }

    public function getTargetIntensity(): PlannedSessionIntensity
    {
        return $this->targetIntensity;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getTargetDurationInSeconds(): ?int
    {
        return $this->targetDurationInSeconds;
    }

    public function isKeySession(): bool
    {
        return $this->isKeySession;
    }

    public function isBrickSession(): bool
    {
        return $this->isBrickSession;
    }

    public function getWorkoutSteps(): array
    {
        return $this->workoutSteps;
    }

    public function hasWorkoutSteps(): bool
    {
        return [] !== $this->workoutSteps;
    }

    /**
     * @return list<array{headline: string, meta: ?string, depth: int}>
     */
    public function getWorkoutPreviewRows(): array
    {
        $rows = [];
        $this->appendWorkoutPreviewRows($rows, $this->workoutSteps, 0);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param list<array{headline: string, meta: ?string, depth: int}> $rows
     */
    private function appendWorkoutPreviewRows(array &$rows, array $steps, int $depth): void
    {
        foreach ($steps as $step) {
            $type = (string) ($step['type'] ?? 'steady');
            $headline = match ($type) {
                'warmup' => 'Warm-up',
                'cooldown' => 'Cool-down',
                'recovery' => 'Recovery',
                'interval' => 'Work',
                'repeatBlock' => sprintf('%dx block', max(1, (int) ($step['repetitions'] ?? 1))),
                default => ucfirst($type),
            };

            $label = trim((string) ($step['label'] ?? ''));
            if ('' !== $label) {
                $headline = sprintf('%s · %s', $headline, $label);
            }

            $metaParts = array_values(array_filter([
                $this->formatWorkoutTargetLabel($step),
                $this->formatWorkoutEffortLabel($step),
            ]));

            $rows[] = [
                'headline' => $headline,
                'meta' => [] === $metaParts ? null : implode(' · ', $metaParts),
                'depth' => $depth,
            ];

            if ('repeatBlock' === $type && is_array($step['steps'] ?? null)) {
                /** @var list<array<string, mixed>> $childSteps */
                $childSteps = $step['steps'];
                $this->appendWorkoutPreviewRows($rows, $childSteps, $depth + 1);
            }
        }
    }

    /**
     * @param array<string, mixed> $step
     */
    private function formatWorkoutTargetLabel(array $step): ?string
    {
        $targetType = (string) ($step['targetType'] ?? '');
        $durationInSeconds = isset($step['durationInSeconds']) ? (int) $step['durationInSeconds'] : null;
        $distanceInMeters = isset($step['distanceInMeters']) ? (int) $step['distanceInMeters'] : null;

        return match ($targetType) {
            'time' => null === $durationInSeconds ? null : $this->formatCompactDuration($durationInSeconds),
            'distance' => null === $distanceInMeters ? null : $this->formatDistance($distanceInMeters),
            'heartRate' => isset($step['targetHeartRate']) ? sprintf('%sbpm', $step['targetHeartRate']) : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $step
     */
    private function formatWorkoutEffortLabel(array $step): ?string
    {
        if (isset($step['targetPower']) && null !== $step['targetPower']) {
            return sprintf('%sW', $step['targetPower']);
        }

        if (isset($step['targetPace']) && null !== $step['targetPace']) {
            return trim((string) $step['targetPace']);
        }

        if (isset($step['targetHeartRate']) && null !== $step['targetHeartRate']) {
            return sprintf('%sbpm', $step['targetHeartRate']);
        }

        return null;
    }

    private function formatCompactDuration(int $durationInSeconds): string
    {
        if ($durationInSeconds < 60) {
            return sprintf('%ds', $durationInSeconds);
        }

        $hours = intdiv($durationInSeconds, 3600);
        $minutes = intdiv($durationInSeconds % 3600, 60);

        if (0 === $hours) {
            return sprintf('%dm', $minutes);
        }

        if (0 === $minutes) {
            return sprintf('%dh', $hours);
        }

        return sprintf('%dh %02dm', $hours, $minutes);
    }

    private function formatDistance(int $distanceInMeters): string
    {
        if ($distanceInMeters >= 1000) {
            return sprintf('%s km', number_format($distanceInMeters / 1000, 1));
        }

        return sprintf('%sm', $distanceInMeters);
    }
}
