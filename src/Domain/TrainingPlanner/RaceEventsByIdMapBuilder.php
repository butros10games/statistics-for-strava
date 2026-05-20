<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class RaceEventsByIdMapBuilder
{
    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, RaceEvent>
     */
    public function build(array $raceEvents): array
    {
        $raceEventsById = [];

        foreach ($raceEvents as $raceEvent) {
            $raceEventsById[(string) $raceEvent->getId()] = $raceEvent;
        }

        return $raceEventsById;
    }
}