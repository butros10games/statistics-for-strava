<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final class PlannedSessionDemandClassifier
{
    private const float HARD_LOAD_THRESHOLD = 110.0;
    private const float EASY_LOAD_THRESHOLD = 50.0;

    /**
     * @param array<string, float|null> $plannedSessionEstimatesById
     */
    public static function isHard(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        $targetIntensity = $plannedSession->getTargetIntensity();
        if (null !== $targetIntensity) {
            return PlannedSessionIntensity::HARD === $targetIntensity || PlannedSessionIntensity::RACE === $targetIntensity;
        }

        return (self::estimateLoad($plannedSession, $plannedSessionEstimatesById) ?? 0.0) >= self::HARD_LOAD_THRESHOLD;
    }

    /**
     * @param array<string, float|null> $plannedSessionEstimatesById
     */
    public static function isEasy(PlannedSession $plannedSession, array $plannedSessionEstimatesById): bool
    {
        if (PlannedSessionIntensity::EASY === $plannedSession->getTargetIntensity()) {
            return true;
        }

        $estimatedLoad = self::estimateLoad($plannedSession, $plannedSessionEstimatesById);

        return null !== $estimatedLoad && $estimatedLoad > 0 && $estimatedLoad <= self::EASY_LOAD_THRESHOLD;
    }

    /**
     * @param array<string, float|null> $plannedSessionEstimatesById
     */
    private static function estimateLoad(PlannedSession $plannedSession, array $plannedSessionEstimatesById): ?float
    {
        return $plannedSessionEstimatesById[(string) $plannedSession->getId()] ?? null;
    }
}