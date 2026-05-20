<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Activity\EnrichedActivities;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class Calendar
{
    private Month $month;

    private EnrichedActivities $enrichedActivities;

    /** @var array<string, list<PlannedSession>> */
    private array $plannedSessionsByDay;

    /** @var array<string, list<RaceEvent>> */
    private array $raceEventsByDay;

    /** @var array<string, list<TrainingBlock>> */
    private array $trainingBlocksByDay;

    /**
     * @param array<string, list<PlannedSession>> $plannedSessionsByDay
     * @param array<string, list<RaceEvent>>      $raceEventsByDay
     * @param array<string, list<TrainingBlock>>  $trainingBlocksByDay
     */
    private function __construct(
        Month $month,
        EnrichedActivities $enrichedActivities,
        array $plannedSessionsByDay,
        array $raceEventsByDay,
        array $trainingBlocksByDay,
    ) {
        $this->month = $month;
        $this->enrichedActivities = $enrichedActivities;
        $this->plannedSessionsByDay = $plannedSessionsByDay;
        $this->raceEventsByDay = $raceEventsByDay;
        $this->trainingBlocksByDay = $trainingBlocksByDay;
    }

    /**
     * @param array<string, list<PlannedSession>> $plannedSessionsByDay
     * @param array<string, list<RaceEvent>>      $raceEventsByDay
     * @param array<string, list<TrainingBlock>>  $trainingBlocksByDay
     */
    public static function create(
        Month $month,
        EnrichedActivities $enrichedActivities,
        array $plannedSessionsByDay = [],
        array $raceEventsByDay = [],
        array $trainingBlocksByDay = [],
    ): self {
        return new self(
            month: $month,
            enrichedActivities: $enrichedActivities,
            plannedSessionsByDay: $plannedSessionsByDay,
            raceEventsByDay: $raceEventsByDay,
            trainingBlocksByDay: $trainingBlocksByDay,
        );
    }

    public function getMonth(): Month
    {
        return $this->month;
    }

    public function getDays(): Days
    {
        $previousMonth = $this->month->getPreviousMonth();
        $nextMonth = $this->month->getNextMonth();
        $numberOfDaysInPreviousMonth = $previousMonth->getNumberOfDays();

        $days = Days::empty();
        for ($i = 1; $i < $this->month->getWeekDayOfFirstDay(); ++$i) {
            // Prepend with days of previous month.
            $dayNumber = $numberOfDaysInPreviousMonth - ($this->month->getWeekDayOfFirstDay() - $i - 1);
            $date = SerializableDateTime::createFromFormat(
                format: 'd-n-Y',
                datetime: $dayNumber.'-'.$previousMonth->getMonth().'-'.$previousMonth->getYear(),
            );

            $days->add(Day::create(
                date: $date,
                dayNumber: $dayNumber,
                isCurrentMonth: false,
                activities: $this->enrichedActivities->findByStartDate($date, null),
                plannedSessions: $this->plannedSessionsByDay[$date->format('Y-m-d')] ?? [],
                raceEvents: $this->raceEventsByDay[$date->format('Y-m-d')] ?? [],
                trainingBlocks: $this->trainingBlocksByDay[$date->format('Y-m-d')] ?? [],
            ));
        }

        for ($i = 0; $i < $this->month->getNumberOfDays(); ++$i) {
            $dayNumber = $i + 1;
            $date = SerializableDateTime::createFromFormat(
                format: 'd-n-Y',
                datetime: $dayNumber.'-'.$this->month->getMonth().'-'.$this->month->getYear(),
            );

            $days->add(Day::create(
                date: $date,
                dayNumber: $dayNumber,
                isCurrentMonth: true,
                activities: $this->enrichedActivities->findByStartDate($date, null),
                plannedSessions: $this->plannedSessionsByDay[$date->format('Y-m-d')] ?? [],
                raceEvents: $this->raceEventsByDay[$date->format('Y-m-d')] ?? [],
                trainingBlocks: $this->trainingBlocksByDay[$date->format('Y-m-d')] ?? [],
            ));
        }

        for ($i = 0; $i < count($days) % 7; ++$i) {
            // Append with days of next month.
            $dayNumber = $i + 1;
            $date = SerializableDateTime::createFromFormat(
                format: 'd-n-Y',
                datetime: $dayNumber.'-'.$nextMonth->getMonth().'-'.$nextMonth->getYear(),
            );

            $days->add(Day::create(
                date: $date,
                dayNumber: $dayNumber,
                isCurrentMonth: false,
                activities: $this->enrichedActivities->findByStartDate($date, null),
                plannedSessions: $this->plannedSessionsByDay[$date->format('Y-m-d')] ?? [],
                raceEvents: $this->raceEventsByDay[$date->format('Y-m-d')] ?? [],
                trainingBlocks: $this->trainingBlocksByDay[$date->format('Y-m-d')] ?? [],
            ));
        }

        return $days;
    }
}
