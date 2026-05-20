<?php

declare(strict_types=1);

namespace App\Tests\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

trait BuildsPlannerFixtures
{
    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return array<string, float|null>
     */
    protected function buildPlannedSessionEstimatesById(array $plannedSessions): array
    {
        $plannedSessionEstimatesById = [];

        foreach ($plannedSessions as $plannedSession) {
            $plannedSessionEstimatesById[(string) $plannedSession->getId()] = $plannedSession->getTargetLoad();
        }

        return $plannedSessionEstimatesById;
    }

    protected function createPlannedSession(
        string $day,
        ActivityType $activityType,
        string $title,
        float $targetLoad,
        int $targetDurationInSeconds,
        PlannedSessionIntensity $targetIntensity,
        string $createdAt = '2026-04-01 08:00:00',
        ?string $updatedAt = null,
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: $activityType,
            title: $title,
            notes: null,
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString($createdAt),
            updatedAt: SerializableDateTime::fromString($updatedAt ?? $createdAt),
        );
    }

    protected function createRaceEvent(
        string $day,
        RaceEventType $type,
        string $title,
        ?string $location = null,
        ?int $targetFinishTimeInSeconds = null,
        string $createdAt = '2026-01-01 08:00:00',
        ?string $updatedAt = null,
    ): RaceEvent {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: SerializableDateTime::fromString($day),
            type: $type,
            title: $title,
            location: $location,
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: $targetFinishTimeInSeconds,
            createdAt: SerializableDateTime::fromString($createdAt),
            updatedAt: SerializableDateTime::fromString($updatedAt ?? $createdAt),
        );
    }

    protected function createTrainingBlock(
        string $startDay,
        string $endDay,
        TrainingBlockPhase $phase,
        string $title,
        ?RaceEventId $targetRaceEventId = null,
        string $createdAt = '2026-01-01 08:00:00',
        ?string $updatedAt = null,
    ): TrainingBlock {
        return TrainingBlock::create(
            trainingBlockId: TrainingBlockId::random(),
            startDay: SerializableDateTime::fromString($startDay),
            endDay: SerializableDateTime::fromString($endDay),
            targetRaceEventId: $targetRaceEventId,
            phase: $phase,
            title: $title,
            focus: null,
            notes: null,
            createdAt: SerializableDateTime::fromString($createdAt),
            updatedAt: SerializableDateTime::fromString($updatedAt ?? $createdAt),
        );
    }
}