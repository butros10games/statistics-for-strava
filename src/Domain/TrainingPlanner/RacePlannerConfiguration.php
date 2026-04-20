<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\KeyValue\Key;
use App\Infrastructure\KeyValue\KeyValue;
use App\Infrastructure\KeyValue\KeyValueStore;
use App\Infrastructure\KeyValue\Value;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class RacePlannerConfiguration
{
    public function __construct(
        private KeyValueStore $keyValueStore,
    ) {
    }

    public function savePlanStartDay(SerializableDateTime $planStartDay): void
    {
        $this->keyValueStore->save(KeyValue::fromState(
            key: Key::RACE_PLANNER_PLAN_START_DAY,
            value: Value::fromString($planStartDay->format('Y-m-d')),
        ));
    }

    public function clearPlanStartDay(): void
    {
        $this->keyValueStore->clear(Key::RACE_PLANNER_PLAN_START_DAY);
    }

    public function findPlanStartDay(): ?SerializableDateTime
    {
        try {
            return SerializableDateTime::fromString((string) $this->keyValueStore->find(Key::RACE_PLANNER_PLAN_START_DAY))
                ->setTime(0, 0);
        } catch (EntityNotFound) {
            return null;
        }
    }
}