<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Activity\Activities;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class Day
{
    private function __construct(
        private SerializableDateTime $date,
        private int $dayNumber,
        private bool $isCurrentMonth,
        private Activities $activities,
        private array $plannedSessions,
    ) {
    }

    public static function create(
        SerializableDateTime $date,
        int $dayNumber,
        bool $isCurrentMonth,
        Activities $activities,
        array $plannedSessions = [],
    ): self {
        return new self(
            date: $date,
            dayNumber: $dayNumber,
            isCurrentMonth: $isCurrentMonth,
            activities: $activities,
            plannedSessions: $plannedSessions,
        );
    }

    public function getDate(): SerializableDateTime
    {
        return $this->date;
    }

    public function getDayNumber(): int
    {
        return $this->dayNumber;
    }

    public function isCurrentMonth(): bool
    {
        return $this->isCurrentMonth;
    }

    public function getActivities(): Activities
    {
        return $this->activities;
    }

    /**
     * @return list<PlannedSession>
     */
    public function getPlannedSessions(): array
    {
        return $this->plannedSessions;
    }
}
