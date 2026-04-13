<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\TrainingLoad;

final class ReadinessMismatchAnalyzer
{
    /**
     * @param array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}|null $latestRecoveryCheckIn
     */
    public function analyze(?ReadinessAssessment $readinessAssessment, ?array $latestRecoveryCheckIn): ?ReadinessMismatchInsight
    {
        if (null === $readinessAssessment || null === $latestRecoveryCheckIn) {
            return null;
        }

        $objectiveImpact = $readinessAssessment->sumFactors([
            ReadinessFactor::KEY_HRV,
            ReadinessFactor::KEY_SLEEP_DURATION,
            ReadinessFactor::KEY_SLEEP_SCORE,
            ReadinessFactor::KEY_STEPS,
            ReadinessFactor::KEY_TSB,
            ReadinessFactor::KEY_AC_RATIO,
            ReadinessFactor::KEY_MONOTONY,
            ReadinessFactor::KEY_STRAIN,
        ]);
        $subjectiveImpact = $readinessAssessment->sumFactors([ReadinessFactor::KEY_RECOVERY_CHECK_IN]);

        if (
            $latestRecoveryCheckIn['stress'] >= 4
            && $latestRecoveryCheckIn['fatigue'] <= 3
            && $latestRecoveryCheckIn['soreness'] <= 3
            && $subjectiveImpact <= -6.0
            && $objectiveImpact > -8.0
        ) {
            return new ReadinessMismatchInsight(
                key: 'stressDominant',
                title: 'Stress is leading today\'s drag',
                summary: 'Your check-in stress is elevated, while load and recovery metrics are not the main problem.',
            );
        }

        if ($subjectiveImpact <= -8.0 && $objectiveImpact >= -3.0) {
            return new ReadinessMismatchInsight(
                key: 'subjectiveWorse',
                title: 'You feel worse than the metrics suggest',
                summary: 'Your check-in is signaling a tougher day than HRV, sleep, and load balance alone would predict.',
            );
        }

        if ($objectiveImpact <= -10.0 && $subjectiveImpact >= -3.0) {
            return new ReadinessMismatchInsight(
                key: 'objectiveWorse',
                title: 'Your recovery metrics are lagging behind how you feel',
                summary: 'HRV, sleep, or load balance look suppressed even though your check-in stayed relatively okay.',
            );
        }

        return null;
    }
}
