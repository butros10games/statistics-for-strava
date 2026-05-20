<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Activity\Activities;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class Day
{
    private SerializableDateTime $date;

    private int $dayNumber;

    private bool $isCurrentMonth;

    private Activities $activities;

    /** @var list<PlannedSession> */
    private array $plannedSessions;

    /** @var list<RaceEvent> */
    private array $raceEvents;

    /** @var list<TrainingBlock> */
    private array $trainingBlocks;

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param list<RaceEvent>      $raceEvents
     * @param list<TrainingBlock>  $trainingBlocks
     */
    private function __construct(
        SerializableDateTime $date,
        int $dayNumber,
        bool $isCurrentMonth,
        Activities $activities,
        array $plannedSessions,
        array $raceEvents,
        array $trainingBlocks,
    ) {
        $this->date = $date;
        $this->dayNumber = $dayNumber;
        $this->isCurrentMonth = $isCurrentMonth;
        $this->activities = $activities;
        $this->plannedSessions = $plannedSessions;
        $this->raceEvents = $raceEvents;
        $this->trainingBlocks = $trainingBlocks;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param list<RaceEvent>      $raceEvents
     * @param list<TrainingBlock>  $trainingBlocks
     */
    public static function create(
        SerializableDateTime $date,
        int $dayNumber,
        bool $isCurrentMonth,
        Activities $activities,
        array $plannedSessions = [],
        array $raceEvents = [],
        array $trainingBlocks = [],
    ): self {
        return new self(
            date: $date,
            dayNumber: $dayNumber,
            isCurrentMonth: $isCurrentMonth,
            activities: $activities,
            plannedSessions: $plannedSessions,
            raceEvents: $raceEvents,
            trainingBlocks: $trainingBlocks,
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

    /**
     * @return list<RaceEvent>
     */
    public function getRaceEvents(): array
    {
        return $this->raceEvents;
    }

    /**
     * @return list<TrainingBlock>
     */
    public function getTrainingBlocks(): array
    {
        return $this->trainingBlocks;
    }
}
