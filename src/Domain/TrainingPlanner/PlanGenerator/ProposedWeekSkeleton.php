<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class ProposedWeekSkeleton
{
    /**
     * @param list<ProposedSession> $sessions
     */
    private function __construct(
        private int $weekNumber,
        private SerializableDateTime $startDay,
        private SerializableDateTime $endDay,
        private array $sessions,
        private float $targetLoadMultiplier,
        private bool $isManuallyPlanned,
        private bool $isRecoveryWeek,
    ) {
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    public static function create(
        int $weekNumber,
        SerializableDateTime $startDay,
        SerializableDateTime $endDay,
        array $sessions,
        float $targetLoadMultiplier = 1.0,
        bool $isManuallyPlanned = false,
        bool $isRecoveryWeek = false,
    ): self {
        $indexedSessions = [];
        foreach (array_values($sessions) as $index => $session) {
            $indexedSessions[] = [
                'index' => $index,
                'session' => $session,
            ];
        }

        usort($indexedSessions, static function (array $left, array $right): int {
            $dayComparison = $left['session']->getDay() <=> $right['session']->getDay();
            if (0 !== $dayComparison) {
                return $dayComparison;
            }

            return $left['index'] <=> $right['index'];
        });

        return new self(
            weekNumber: $weekNumber,
            startDay: $startDay,
            endDay: $endDay,
            sessions: array_values(array_map(
                static fn (array $indexedSession): ProposedSession => $indexedSession['session'],
                $indexedSessions,
            )),
            targetLoadMultiplier: $targetLoadMultiplier,
            isManuallyPlanned: $isManuallyPlanned,
            isRecoveryWeek: $isRecoveryWeek,
        );
    }

    public function getWeekNumber(): int
    {
        return $this->weekNumber;
    }

    public function getStartDay(): SerializableDateTime
    {
        return $this->startDay;
    }

    public function getEndDay(): SerializableDateTime
    {
        return $this->endDay;
    }

    /**
     * @return list<ProposedSession>
     */
    public function getSessions(): array
    {
        return $this->sessions;
    }

    public function getTargetLoadMultiplier(): float
    {
        return $this->targetLoadMultiplier;
    }

    public function getTargetLoadPercentage(): int
    {
        return (int) round($this->targetLoadMultiplier * 100);
    }

    public function isManuallyPlanned(): bool
    {
        return $this->isManuallyPlanned;
    }

    public function isRecoveryWeek(): bool
    {
        return $this->isRecoveryWeek;
    }

    public function getSessionCount(): int
    {
        return count($this->sessions);
    }

    public function hasRaceEffortSession(): bool
    {
        return null !== $this->findRaceEffortSession();
    }

    public function getRaceSummaryLabel(): ?string
    {
        $raceSession = $this->findRaceEffortSession();
        if (null === $raceSession) {
            return null;
        }

        $raceType = trim((string) ($raceSession->getNotes() ?? ''));
        $raceTitle = trim((string) ($raceSession->getTitle() ?? ''));

        if ('' === $raceType) {
            return '' === $raceTitle ? null : $raceTitle;
        }

        if ('' === $raceTitle) {
            return $raceType;
        }

        return sprintf('%s · %s', $raceType, $raceTitle);
    }

    public function getTargetDurationInSecondsForActivityType(ActivityType $activityType): int
    {
        $durationInSeconds = 0;

        foreach ($this->sessions as $session) {
            if ($session->getActivityType() !== $activityType) {
                continue;
            }

            $durationInSeconds += max(0, $session->getTargetDurationInSeconds() ?? 0);
        }

        return $durationInSeconds;
    }

    public function getFormattedTargetDurationForActivityType(ActivityType $activityType): string
    {
        return $this->formatCompactDuration($this->getTargetDurationInSecondsForActivityType($activityType));
    }

    private function formatCompactDuration(int $durationInSeconds): string
    {
        if ($durationInSeconds < 60) {
            return '0m';
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

    private function findRaceEffortSession(): ?ProposedSession
    {
        foreach ($this->sessions as $session) {
            if (PlannedSessionIntensity::RACE === $session->getTargetIntensity()) {
                return $session;
            }
        }

        return null;
    }
}
