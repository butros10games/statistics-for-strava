<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessScore;
use App\Domain\Dashboard\Widget\TrainingLoad\TrainingLoadForecastProjection;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class RaceReadinessContextBuilder
{
    /**
     * @param list<PlannedSession> $plannedSessions
     * @param list<RaceEvent> $raceEvents
     * @param list<TrainingBlock> $trainingBlocks
     * @param array<string, RaceEvent> $raceEventsById
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    public function build(
        SerializableDateTime $referenceDate,
        array $plannedSessions,
        array $raceEvents,
        array $trainingBlocks,
        ?TrainingBlock $currentTrainingBlock,
        array $raceEventsById,
        array $plannedSessionEstimatesById,
        ?ReadinessScore $readinessScore = null,
        ?TrainingLoadForecastProjection $forecastProjection = null,
    ): RaceReadinessContext {
        $estimatedLoad = $this->buildEstimatedLoadForPlannedSessions($plannedSessions, $plannedSessionEstimatesById);
        $activityTypeSummaries = $this->buildActivityTypeSummariesForPlannedSessions($plannedSessions);
        $disciplineCounts = $this->buildTriathlonDisciplineCounts($activityTypeSummaries);
        $targetRace = $this->resolveTargetRace(
            contextualTrainingBlocks: $trainingBlocks,
            currentTrainingBlock: $currentTrainingBlock,
            contextualRaceEvents: $raceEvents,
            raceEventsById: $raceEventsById,
        );
        $primaryTrainingBlock = $this->resolvePrimaryTrainingBlock($trainingBlocks, $currentTrainingBlock);

        $hardSessionCount = 0;
        $easySessionCount = 0;
        $sessionDays = [];
        $hasLongRideSession = false;
        $hasLongRunSession = false;

        foreach ($plannedSessions as $plannedSession) {
            if ($this->isHardPlannedSession($plannedSession, $plannedSessionEstimatesById)) {
                ++$hardSessionCount;
            }

            if ($this->isEasyPlannedSession($plannedSession, $plannedSessionEstimatesById)) {
                ++$easySessionCount;
            }

            $sessionDays[$plannedSession->getDay()->format('Y-m-d')] = true;

            $durationInSeconds = $plannedSession->getTargetDurationInSeconds() ?? $plannedSession->getWorkoutDurationInSeconds() ?? 0;
            if (ActivityType::RIDE === $plannedSession->getActivityType() && $durationInSeconds >= 5_400) {
                $hasLongRideSession = true;
            }

            if (ActivityType::RUN === $plannedSession->getActivityType() && $durationInSeconds >= 4_500) {
                $hasLongRunSession = true;
            }
        }

        return new RaceReadinessContext(
            targetRace: $targetRace,
            primaryTrainingBlock: $primaryTrainingBlock,
            targetRaceCountdownDays: $this->buildTargetRaceCountdownDays($referenceDate, $targetRace),
            hasRaceEventInContextWindow: [] !== $raceEvents,
            estimatedLoad: $estimatedLoad,
            activityTypeSummaries: $activityTypeSummaries,
            disciplineCounts: $disciplineCounts,
            sessionCount: count($plannedSessions),
            distinctSessionDayCount: count($sessionDays),
            hardSessionCount: $hardSessionCount,
            easySessionCount: $easySessionCount,
            brickDayCount: $this->countBrickDays($plannedSessions),
            hasLongRideSession: $hasLongRideSession,
            hasLongRunSession: $hasLongRunSession,
            readinessScore: $readinessScore,
            forecastConfidence: $forecastProjection?->getConfidence(),
            forecastDaysUntilTsbHealthy: $forecastProjection?->getDaysUntilTsbHealthy(),
            forecastDaysUntilAcRatioHealthy: $forecastProjection?->getDaysUntilAcRatioHealthy(),
        );
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    private function buildEstimatedLoadForPlannedSessions(array $plannedSessions, array $plannedSessionEstimatesById): float
    {
        $estimatedLoad = 0.0;

        foreach ($plannedSessions as $plannedSession) {
            $estimatedLoad += $plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? 0.0;
        }

        return $estimatedLoad;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return list<array{activityType: ActivityType, count: int}>
     */
    private function buildActivityTypeSummariesForPlannedSessions(array $plannedSessions): array
    {
        $countsByActivityType = [];

        foreach ($plannedSessions as $plannedSession) {
            $activityType = $plannedSession->getActivityType();
            $key = $activityType->value;

            if (!isset($countsByActivityType[$key])) {
                $countsByActivityType[$key] = [
                    'activityType' => $activityType,
                    'count' => 0,
                ];
            }

            ++$countsByActivityType[$key]['count'];
        }

        usort($countsByActivityType, static function (array $left, array $right): int {
            if ($left['count'] === $right['count']) {
                return strcmp($left['activityType']->value, $right['activityType']->value);
            }

            return $right['count'] <=> $left['count'];
        });

        return array_values($countsByActivityType);
    }

    /**
     * @param list<array{activityType: ActivityType, count: int}> $activityTypeSummaries
     *
     * @return array{swim: int, bike: int, run: int}
     */
    private function buildTriathlonDisciplineCounts(array $activityTypeSummaries): array
    {
        $counts = ['swim' => 0, 'bike' => 0, 'run' => 0];

        foreach ($activityTypeSummaries as $summary) {
            match ($summary['activityType']) {
                ActivityType::WATER_SPORTS => $counts['swim'] += $summary['count'],
                ActivityType::RIDE => $counts['bike'] += $summary['count'],
                ActivityType::RUN => $counts['run'] += $summary['count'],
                default => null,
            };
        }

        return $counts;
    }

    /**
     * @param list<TrainingBlock> $contextualTrainingBlocks
     * @param list<RaceEvent> $contextualRaceEvents
     * @param array<string, RaceEvent> $raceEventsById
     */
    private function resolveTargetRace(
        array $contextualTrainingBlocks,
        ?TrainingBlock $currentTrainingBlock,
        array $contextualRaceEvents,
        array $raceEventsById,
    ): ?RaceEvent {
        foreach ($contextualTrainingBlocks as $trainingBlock) {
            $targetRaceEventId = $trainingBlock->getTargetRaceEventId();
            if (null === $targetRaceEventId) {
                continue;
            }

            return $raceEventsById[(string) $targetRaceEventId] ?? null;
        }

        $currentTrainingBlockTargetRaceEventId = $currentTrainingBlock?->getTargetRaceEventId();
        if (null !== $currentTrainingBlockTargetRaceEventId) {
            return $raceEventsById[(string) $currentTrainingBlockTargetRaceEventId] ?? null;
        }

        return $contextualRaceEvents[0] ?? null;
    }

    /**
     * @param list<TrainingBlock> $contextualTrainingBlocks
     */
    private function resolvePrimaryTrainingBlock(array $contextualTrainingBlocks, ?TrainingBlock $currentTrainingBlock): ?TrainingBlock
    {
        return $contextualTrainingBlocks[0] ?? $currentTrainingBlock;
    }

    private function buildTargetRaceCountdownDays(SerializableDateTime $referenceDate, ?RaceEvent $targetRace): ?int
    {
        if (null === $targetRace) {
            return null;
        }

        return max(0, (int) $referenceDate->setTime(0, 0)->diff($targetRace->getDay(), false)->format('%r%a'));
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     */
    private function countBrickDays(array $plannedSessions): int
    {
        $sessionsByDay = [];

        foreach ($plannedSessions as $plannedSession) {
            $sessionsByDay[$plannedSession->getDay()->format('Y-m-d')][] = $plannedSession;
        }

        $brickDays = 0;

        foreach ($sessionsByDay as $sessionsForDay) {
            $hasRide = false;
            $hasRun = false;

            foreach ($sessionsForDay as $plannedSession) {
                $hasRide = $hasRide || ActivityType::RIDE === $plannedSession->getActivityType();
                $hasRun = $hasRun || ActivityType::RUN === $plannedSession->getActivityType();
            }

            if ($hasRide && $hasRun) {
                ++$brickDays;
            }
        }

        return $brickDays;
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    private function isHardPlannedSession(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        $targetIntensity = $plannedSession->getTargetIntensity();
        if (null !== $targetIntensity) {
            return PlannedSessionIntensity::HARD === $targetIntensity || PlannedSessionIntensity::RACE === $targetIntensity;
        }

        return ($plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? 0.0) >= 110.0;
    }

    /**
     * @param array<string, null|float> $plannedSessionEstimatesById
     */
    private function isEasyPlannedSession(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        if (PlannedSessionIntensity::EASY === $plannedSession->getTargetIntensity()) {
            return true;
        }

        $estimatedLoad = $plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? null;

        return null !== $estimatedLoad && $estimatedLoad > 0 && $estimatedLoad <= 50.0;
    }
}