<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RaceEventsByIdMapBuilder;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class RaceEventsByIdMapBuilderTest extends TestCase
{
    public function testBuildIndexesRaceEventsById(): void
    {
        $firstRace = $this->createRaceEvent('2023-10-19 00:00:00', 'Local 10K');
        $secondRace = $this->createRaceEvent('2023-11-12 00:00:00', 'A-race');

        $indexed = (new RaceEventsByIdMapBuilder())->build([$firstRace, $secondRace]);

        self::assertSame($firstRace, $indexed[(string) $firstRace->getId()]);
        self::assertSame($secondRace, $indexed[(string) $secondRace->getId()]);
    }

    private function createRaceEvent(string $day, string $title): RaceEvent
    {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString($day),
            type: RaceEventType::RUN_10K,
            title: $title,
            location: null,
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: null,
            createdAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2023-10-01 08:00:00'),
        );
    }
}